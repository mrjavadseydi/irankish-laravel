<?php

namespace MJSeydi\iranKish\Tests;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use MJSeydi\iranKish\Exceptions\IranKishException;
use MJSeydi\iranKish\Facades\IranKish;
use MJSeydi\iranKish\IranKish as IranKishClient;
use MJSeydi\iranKish\Support\ResponseCode;

class IranKishTest extends TestCase
{
    public function test_it_is_registered_in_the_container(): void
    {
        $this->assertInstanceOf(IranKishClient::class, app('IranKish'));
        $this->assertSame(app(IranKishClient::class), app('IranKish'));
        $this->assertSame('08012345', config('IranKish.terminalId'));
        $this->assertSame('https://ikc.shaparak.ir', config('IranKish.base_url'));
    }

    public function test_the_authentication_envelope_can_be_opened_with_the_private_key(): void
    {
        $envelope = IranKish::authenticationEnvelope(150000);

        openssl_private_decrypt(hex2bin($envelope['data']), $plain, static::$privateKey);
        $this->assertSame(16 + 32, strlen($plain));

        $aesKey = substr($plain, 0, 16);
        $this->assertSame(16, strlen(hex2bin($envelope['iv'])));

        // the HMAC is over the cipher text, so re-encrypting must reproduce it
        $expected = hex2bin('08012345'.'A1B2C3D4E5F60718'.'000000150000'.'00');
        $cipherText = openssl_encrypt($expected, 'AES-128-CBC', $aesKey, OPENSSL_RAW_DATA, hex2bin($envelope['iv']));
        $this->assertSame(hash('sha256', $cipherText, true), substr($plain, 16));
    }

    public function test_the_public_key_may_be_bare_base64_or_a_file(): void
    {
        $base64 = trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', static::$publicKey));
        config(['IranKish.public_key' => $base64]);
        $this->assertArrayHasKey('data', IranKish::authenticationEnvelope(1000));

        $path = tempnam(sys_get_temp_dir(), 'ikc');
        file_put_contents($path, static::$publicKey);
        config(['IranKish.public_key' => $path]);
        $this->assertArrayHasKey('data', IranKish::authenticationEnvelope(1000));
        unlink($path);

        config(['IranKish.public_key' => str_replace("\n", '\n', static::$publicKey)]);
        $this->assertArrayHasKey('data', IranKish::authenticationEnvelope(1000));
    }

    public function test_an_invalid_public_key_throws(): void
    {
        config(['IranKish.public_key' => 'not-a-key']);

        $this->expectException(IranKishException::class);
        IranKish::authenticationEnvelope(1000);
    }

    public function test_it_requests_a_token(): void
    {
        Http::fake([
            'ikc.shaparak.ir/api/v3/tokenization/make' => Http::response([
                'responseCode' => '00',
                'description' => 'عملیات با موفقیت انجام شد',
                'status' => true,
                'result' => ['token' => 'TOKEN123', 'initiateTimeStamp' => 1, 'expiryTimeStamp' => 2],
            ]),
        ]);

        $token = IranKish::requestToken(150000, 'order-42', [
            'additional_parameters' => ['nationalId' => '0012345678'],
        ]);

        $this->assertSame('TOKEN123', $token);

        Http::assertSent(function (ClientRequest $request) {
            $body = $request->data();

            return $request->url() === 'https://ikc.shaparak.ir/api/v3/tokenization/make'
                && $request->method() === 'POST'
                && $body['request']['requestId'] === 'order-42'
                && $body['request']['paymentId'] === null
                && $body['request']['amount'] === 150000
                && $body['request']['terminalId'] === '08012345'
                && $body['request']['acceptorId'] === '992180008012345'
                && $body['request']['revertUri'] === 'https://shop.test/callback'
                && $body['request']['transactionType'] === 'Purchase'
                && $body['request']['additionalParameters'] === [['Key' => 'nationalId', 'Value' => '0012345678']]
                && isset($body['authenticationEnvelope']['data'], $body['authenticationEnvelope']['iv']);
        });
    }

    public function test_a_failed_token_request_throws_with_the_gateway_code(): void
    {
        Http::fake(['*' => Http::response([
            'responseCode' => '907',
            'description' => '',
            'status' => false,
            'result' => null,
        ])]);

        try {
            IranKish::requestToken(1000, 'x');
            $this->fail('No exception thrown');
        } catch (IranKishException $e) {
            $this->assertSame('907', $e->getResponseCode());
            $this->assertSame(907, $e->getCode());
            $this->assertSame(ResponseCode::message('907'), $e->getMessage());
        }
    }

    public function test_a_non_json_response_throws(): void
    {
        Http::fake(['*' => Http::response('<html>bad gateway</html>', 502)]);

        $this->expectException(IranKishException::class);
        IranKish::requestToken(1000);
    }

    public function test_the_redirect_form_posts_the_token(): void
    {
        $html = IranKish::redirect('TOK"EN')->getContent();

        $this->assertStringContainsString('action="https://ikc.shaparak.ir/iuiv3/IPG/Index/"', $html);
        $this->assertStringContainsString('name="tokenIdentity" value="TOK&quot;EN"', $html);
    }

    public function test_it_verifies_the_callback(): void
    {
        Http::fake(['ikc.shaparak.ir/api/v3/confirmation/purchase' => Http::response([
            'responseCode' => '00',
            'status' => true,
            'result' => [
                'responseCode' => '00',
                'retrievalReferenceNumber' => '123456789012',
                'systemTraceAuditNumber' => '654321',
                'amount' => '150000',
                'maskedPan' => '603799******1234',
            ],
        ])]);

        $request = Request::create('/callback', 'POST', [
            'token' => 'TOKEN123',
            'responseCode' => '00',
            'requestId' => 'order-42',
            'retrievalReferenceNumber' => '123456789012',
            'systemTraceAuditNumber' => '654321',
            'amount' => '150000',
        ]);

        $result = IranKish::verifyCallback($request, 150000);

        $this->assertSame('123456789012', $result['retrievalReferenceNumber']);
        $this->assertSame('order-42', $result['requestId']);

        Http::assertSent(fn (ClientRequest $r) => $r->data() === [
            'terminalId' => '08012345',
            'retrievalReferenceNumber' => '123456789012',
            'systemTraceAuditNumber' => '654321',
            'tokenIdentity' => 'TOKEN123',
        ]);
    }

    public function test_a_cancelled_callback_is_not_confirmed(): void
    {
        Http::fake();

        $request = Request::create('/callback', 'POST', ['token' => 'T', 'responseCode' => '17']);

        try {
            IranKish::verifyCallback($request);
            $this->fail('No exception thrown');
        } catch (IranKishException $e) {
            $this->assertSame('17', $e->getResponseCode());
        }

        Http::assertNothingSent();
    }

    public function test_an_amount_mismatch_is_not_confirmed(): void
    {
        Http::fake();

        $request = Request::create('/callback', 'POST', [
            'token' => 'T', 'responseCode' => '00', 'retrievalReferenceNumber' => '1',
            'systemTraceAuditNumber' => '2', 'amount' => '1000',
        ]);

        $this->expectException(IranKishException::class);

        try {
            IranKish::verifyCallback($request, 150000);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_failed_inner_confirmation_code_throws(): void
    {
        Http::fake(['*' => Http::response([
            'responseCode' => '00',
            'status' => true,
            'result' => ['responseCode' => '51'],
        ])]);

        try {
            IranKish::verify('T', '1', '2');
            $this->fail('No exception thrown');
        } catch (IranKishException $e) {
            $this->assertSame('51', $e->getResponseCode());
        }
    }

    public function test_reverse_and_inquiry_hit_their_endpoints(): void
    {
        Http::fake(['*' => Http::response(['responseCode' => '00', 'status' => true, 'result' => ['isReversed' => true]])]);

        $this->assertSame(['isReversed' => true], IranKish::reverse('T', '1', '2'));
        IranKish::inquiryByToken('T');

        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://ikc.shaparak.ir/api/v3/confirmation/reversePurchase');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://ikc.shaparak.ir/api/v3/inquiry/single'
            && $r['findOption'] === 2
            && $r['tokenIdentity'] === 'T'
            && $r['passPhrase'] === 'A1B2C3D4E5F60718');
    }

    public function test_the_legacy_methods_keep_working(): void
    {
        Http::fake([
            '*/tokenization/make' => Http::response(['responseCode' => '00', 'result' => ['token' => 'TOK']]),
            '*/confirmation/purchase' => Http::response(['responseCode' => '00', 'result' => []]),
        ]);

        $response = IranKish::getIranKishToken(1000, 55);
        $this->assertSame('TOK', $response['result']['token']);

        $this->assertSame('00', IranKish::verifyPayment('1', '2', 'TOK')['responseCode']);

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/tokenization/make') && $r['request']['paymentId'] === '55');
    }

    public function test_an_explicit_config_can_be_used_for_another_terminal(): void
    {
        Http::fake(['*' => Http::response(['responseCode' => '00', 'result' => ['token' => 'TOK']])]);

        $client = new IranKishClient([
            'terminalId' => '99999999',
            'password' => 'FFFFFFFFFFFFFFFF',
            'acceptor' => '1',
            'public_key' => static::$publicKey,
            'callback' => 'https://other.test/cb',
            'base_url' => 'https://sandbox.test',
        ]);

        $client->requestToken(1000);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://sandbox.test/api/v3/tokenization/make'
            && $r['request']['terminalId'] === '99999999');
    }

    public function test_response_codes_are_normalized(): void
    {
        $this->assertTrue(ResponseCode::isSuccessful('00'));
        $this->assertTrue(ResponseCode::isSuccessful(0));
        $this->assertFalse(ResponseCode::isSuccessful(null));
        $this->assertSame(ResponseCode::message('5'), ResponseCode::message('05'));
    }
}
