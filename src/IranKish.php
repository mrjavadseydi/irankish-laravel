<?php

namespace MJSeydi\iranKish;

use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MJSeydi\iranKish\Exceptions\IranKishException;
use MJSeydi\iranKish\Support\ResponseCode;

class IranKish
{
    public const TOKEN_PATH = '/api/v3/tokenization/make';

    public const CONFIRM_PATH = '/api/v3/confirmation/purchase';

    public const REVERSE_PATH = '/api/v3/confirmation/reversePurchase';

    public const INQUIRY_PATH = '/api/v3/inquiry/single';

    /**
     * @param  array|null  $config  explicit settings (e.g. for a second terminal);
     *                              null reads the "IranKish" config on every call
     */
    public function __construct(protected ?array $config = null)
    {
    }

    /**
     * Request a payment token for the given amount (in Rial).
     *
     * The request id is echoed back to the callback as "requestId", so it is
     * the natural place for your order id. Supported options:
     *  - callback:              revert url, defaults to the configured one
     *  - payment_id:            Iranian standard payment identifier (شناسه پرداخت)
     *  - transaction_type:      defaults to "Purchase"
     *  - cms_preservation_id:   payer mobile/email, when enabled for your terminal
     *  - additional_parameters: key => value pairs sent as additionalParameters
     *  - request:               raw fields merged into the request body
     *
     * @throws IranKishException
     */
    public function requestToken(int $amount, int|string|null $requestId = null, array $options = []): string
    {
        $request = [
            'acceptorId' => (string) $this->config('acceptor'),
            'amount' => $amount,
            'billInfo' => null,
            'paymentId' => isset($options['payment_id']) ? (string) $options['payment_id'] : null,
            'requestId' => $requestId === null ? uniqid() : (string) $requestId,
            'requestTimestamp' => time(),
            'revertUri' => $options['callback'] ?? $this->config('callback'),
            'terminalId' => (string) $this->config('terminalId'),
            'transactionType' => $options['transaction_type'] ?? 'Purchase',
        ];

        if (! empty($options['cms_preservation_id'])) {
            $request['cmsPreservationId'] = (string) $options['cms_preservation_id'];
        }

        if (! empty($options['additional_parameters'])) {
            foreach ($options['additional_parameters'] as $key => $value) {
                $request['additionalParameters'][] = ['Key' => (string) $key, 'Value' => (string) $value];
            }
        }

        $request = array_merge($request, $options['request'] ?? []);

        $response = $this->post(self::TOKEN_PATH, [
            'request' => $request,
            'authenticationEnvelope' => $this->authenticationEnvelope($amount),
        ]);

        $token = $response['result']['token'] ?? null;

        if (! $this->isSuccessful($response) || ! is_string($token) || $token === '') {
            throw IranKishException::fromResponse($response);
        }

        return $token;
    }

    /**
     * The url the customer has to POST the token to.
     */
    public function paymentUrl(): string
    {
        return (string) $this->config('payment_url', 'https://ikc.shaparak.ir/iuiv3/IPG/Index/');
    }

    /**
     * An auto-submitting HTML form that sends the customer to the gateway.
     */
    public function redirectForm(string $token): string
    {
        $action = htmlspecialchars($this->paymentUrl(), ENT_QUOTES);
        $token = htmlspecialchars($token, ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>انتقال به درگاه پرداخت</title>
</head>
<body>
    <form id="irankish-redirect" action="{$action}" method="POST">
        <input type="hidden" name="tokenIdentity" value="{$token}">
        <noscript><button type="submit">ورود به درگاه پرداخت</button></noscript>
    </form>
    <script>document.getElementById('irankish-redirect').submit();</script>
</body>
</html>
HTML;
    }

    /**
     * A response that sends the customer to the gateway.
     */
    public function redirect(string $token): Response
    {
        return new Response($this->redirectForm($token));
    }

    /**
     * Confirm (verify) a purchase. Must be called after the callback, or the
     * gateway reverses the transaction automatically.
     *
     * @return array the "result" of the confirmation (retrievalReferenceNumber,
     *               systemTraceAuditNumber, amount, maskedPan, ...)
     *
     * @throws IranKishException
     */
    public function verify(string $token, string $retrievalReferenceNumber, string $systemTraceAuditNumber): array
    {
        return $this->confirmation(self::CONFIRM_PATH, $token, $retrievalReferenceNumber, $systemTraceAuditNumber);
    }

    /**
     * Read the callback the gateway posted to the revert url and confirm it.
     *
     * Pass the amount you requested a token for and it is checked against the
     * callback before the purchase is confirmed.
     *
     * @throws IranKishException
     */
    public function verifyCallback(?Request $request = null, ?int $expectedAmount = null): array
    {
        $callback = $this->callback($request);

        if (! ResponseCode::isSuccessful($callback['responseCode'])) {
            throw new IranKishException(
                ResponseCode::message($callback['responseCode']),
                $callback['responseCode'],
                $callback,
            );
        }

        if ($callback['token'] === null || $callback['retrievalReferenceNumber'] === null || $callback['systemTraceAuditNumber'] === null) {
            throw new IranKishException('اطلاعات بازگشتی از درگاه ناقص است', null, $callback);
        }

        if ($expectedAmount !== null && $callback['amount'] !== null && (int) $callback['amount'] !== $expectedAmount) {
            throw new IranKishException('مبلغ پرداخت شده با مبلغ سفارش مطابقت ندارد', null, $callback);
        }

        return $this->verify(
            $callback['token'],
            $callback['retrievalReferenceNumber'],
            $callback['systemTraceAuditNumber'],
        ) + ['requestId' => $callback['requestId'], 'paymentId' => $callback['paymentId']];
    }

    /**
     * The fields the gateway posts to the revert url.
     *
     * @return array<string, string|null>
     */
    public function callback(?Request $request = null): array
    {
        $request ??= Container::getInstance()->make('request');

        $fields = [
            'token', 'responseCode', 'acceptorId', 'merchantId', 'requestId', 'paymentId',
            'retrievalReferenceNumber', 'systemTraceAuditNumber', 'amount', 'maskedPan',
            'sha256OfPan', 'sha1OfPan',
        ];

        $values = [];

        foreach ($fields as $field) {
            $value = $request->input($field, $request->input(ucfirst($field)));
            $values[$field] = is_scalar($value) && $value !== '' ? (string) $value : null;
        }

        if ($values['token'] === null && is_string($token = $request->input('tokenIdentity')) && $token !== '') {
            $values['token'] = $token;
        }

        return $values;
    }

    /**
     * Reverse a purchase that was not confirmed yet.
     *
     * @throws IranKishException
     */
    public function reverse(string $token, string $retrievalReferenceNumber, string $systemTraceAuditNumber): array
    {
        return $this->confirmation(self::REVERSE_PATH, $token, $retrievalReferenceNumber, $systemTraceAuditNumber);
    }

    /**
     * @throws IranKishException
     */
    public function inquiryByReferenceNumber(string $retrievalReferenceNumber): array
    {
        return $this->inquiry(1, ['retrievalReferenceNumber' => $retrievalReferenceNumber]);
    }

    /**
     * @throws IranKishException
     */
    public function inquiryByToken(string $token): array
    {
        return $this->inquiry(2, ['tokenIdentity' => $token]);
    }

    /**
     * @throws IranKishException
     */
    public function inquiryByRequestId(string $requestId): array
    {
        return $this->inquiry(3, ['requestId' => $requestId]);
    }

    /**
     * Build the authentication envelope for the configured terminal.
     *
     * @return array{data: string, iv: string}
     *
     * @throws IranKishException
     */
    public function authenticationEnvelope(int $amount): array
    {
        return $this->generateAuthenticationEnvelope(
            $this->publicKey(),
            (string) $this->config('terminalId'),
            (string) $this->config('password'),
            $amount,
        );
    }

    /**
     * The terminal id, password and amount are encrypted with a one-time AES
     * key; that key and the SHA-256 of the cipher text are then sealed with
     * the Iran Kish RSA public key.
     *
     * @return array{data: string, iv: string}
     *
     * @throws IranKishException
     */
    public function generateAuthenticationEnvelope($pub_key, $terminalID, $password, $amount): array
    {
        $data = @hex2bin($terminalID . $password . str_pad((string) $amount, 12, '0', STR_PAD_LEFT) . '00');

        if ($data === false) {
            throw new IranKishException('شماره پایانه یا رمز پایانه ایران کیش نامعتبر است');
        }

        $cipher = 'AES-128-CBC';
        $secretKey = random_bytes(16);
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $cipherText = openssl_encrypt($data, $cipher, $secretKey, OPENSSL_RAW_DATA, $iv);
        $hmac = hash('sha256', $cipherText, true);

        $sealed = '';

        if (! @openssl_public_encrypt($secretKey . $hmac, $sealed, $pub_key)) {
            throw new IranKishException('کلید عمومی ایران کیش نامعتبر است');
        }

        return [
            'data' => bin2hex($sealed),
            'iv' => bin2hex($iv),
        ];
    }

    /**
     * Request a token and return the raw gateway response.
     *
     * @deprecated use requestToken(), which returns the token and throws on failure.
     *             Note that this method sends the order id as "paymentId".
     */
    public function getIranKishToken($Amount, $orderId): array
    {
        return $this->post(self::TOKEN_PATH, [
            'request' => [
                'acceptorId' => (string) $this->config('acceptor'),
                'amount' => (int) $Amount,
                'billInfo' => null,
                'requestId' => uniqid(),
                'paymentId' => (string) $orderId,
                'requestTimestamp' => time(),
                'revertUri' => $this->config('callback'),
                'terminalId' => (string) $this->config('terminalId'),
                'transactionType' => 'Purchase',
            ],
            'authenticationEnvelope' => $this->authenticationEnvelope((int) $Amount),
        ]);
    }

    /**
     * Confirm a purchase and return the raw gateway response.
     *
     * @deprecated use verify() or verifyCallback(), which throw on failure.
     */
    public function verifyPayment($verifySaleReferenceId, $systemTraceAuditNumber, $token): array
    {
        return $this->post(self::CONFIRM_PATH, [
            'terminalId' => (string) $this->config('terminalId'),
            'retrievalReferenceNumber' => (string) $verifySaleReferenceId,
            'systemTraceAuditNumber' => (string) $systemTraceAuditNumber,
            'tokenIdentity' => (string) $token,
        ]);
    }

    protected function confirmation(string $path, string $token, string $retrievalReferenceNumber, string $systemTraceAuditNumber): array
    {
        $response = $this->post($path, [
            'terminalId' => (string) $this->config('terminalId'),
            'retrievalReferenceNumber' => $retrievalReferenceNumber,
            'systemTraceAuditNumber' => $systemTraceAuditNumber,
            'tokenIdentity' => $token,
        ]);

        if (! $this->isSuccessful($response)) {
            throw IranKishException::fromResponse($response);
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    protected function inquiry(int $findOption, array $criteria): array
    {
        $response = $this->post(self::INQUIRY_PATH, [
            'passPhrase' => (string) $this->config('password'),
            'terminalId' => (string) $this->config('terminalId'),
            'findOption' => $findOption,
        ] + $criteria);

        if (! $this->isSuccessful($response)) {
            throw IranKishException::fromResponse($response);
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    protected function isSuccessful(array $response): bool
    {
        if (! ResponseCode::isSuccessful($response['responseCode'] ?? null) || ($response['status'] ?? true) === false) {
            return false;
        }

        $innerCode = $response['result']['responseCode'] ?? null;

        return $innerCode === null || ResponseCode::isSuccessful($innerCode);
    }

    /**
     * @throws IranKishException
     */
    protected function post(string $path, array $payload): array
    {
        try {
            $response = $this->client()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new IranKishException('ارتباط با درگاه ایران کیش برقرار نشد', null, [], $e);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new IranKishException('پاسخ نامعتبر از درگاه ایران کیش دریافت شد', null, [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $data;
    }

    protected function client(): PendingRequest
    {
        $client = Container::getInstance()->make(HttpFactory::class)
            ->baseUrl(rtrim((string) $this->config('base_url', 'https://ikc.shaparak.ir'), '/'))
            ->asJson()
            ->acceptJson()
            ->timeout((int) $this->config('timeout', 30));

        if ($this->config('legacy_ssl_ciphers', true) && defined('CURLOPT_SSL_CIPHER_LIST')) {
            $client->withOptions(['curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1']]);
        }

        return $client;
    }

    /**
     * The public key may be configured as PEM content (with real or escaped
     * new lines), bare base64, or a path to a PEM file.
     */
    protected function publicKey(): string
    {
        $key = trim((string) $this->config('public_key'));

        if ($key !== '' && ! str_contains($key, '-----BEGIN') && is_file($key)) {
            $key = trim((string) file_get_contents($key));
        }

        $key = str_replace('\n', "\n", $key);

        if ($key !== '' && ! str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n"
                . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")
                . '-----END PUBLIC KEY-----';
        }

        return $key;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        $config = $this->config ?? Container::getInstance()->make('config')->get('IranKish', []);

        return $config[$key] ?? $default;
    }
}
