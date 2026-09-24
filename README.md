# Laravel Iran Kish payment package

A Laravel package for the Iran Kish (IKC) IPG v3 payment gateway.

پکیج درگاه پرداخت ایران کیش برای لاراول

## Requirements

- PHP 8.2 or newer, with the `openssl` and `json` extensions
- Laravel 12 or 13

## Installation

```bash
composer require javad/ir-kish
```

The service provider and the `IranKish` facade are auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --tag=irankish-config
```

Then set your terminal credentials in `.env`:

```dotenv
IRANKISH_TERMINAL_ID=
IRANKISH_PASSWORD=
IRANKISH_ACCEPTOR_ID=
# PEM content (use \n for new lines), bare base64, or an absolute path to a PEM file
IRANKISH_PUBLIC_KEY=
IRANKISH_CALLBACK_URL=https://your-site.com/payment/callback
```

The callback URL must be on the website registered for your acceptor, and it has to accept
`POST` requests from the gateway, so exclude it from CSRF verification
(`bootstrap/app.php` in Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['payment/callback']);
})
```

## Usage

Amounts are in **Rial**.

### 1. Request a token and send the customer to the gateway

```php
use MJSeydi\iranKish\Exceptions\IranKishException;
use MJSeydi\iranKish\Facades\IranKish;

public function pay(Order $order)
{
    try {
        // the second argument is sent as "requestId" and comes back to your callback
        $token = IranKish::requestToken($order->amount, (string) $order->id);
    } catch (IranKishException $e) {
        return back()->withErrors($e->getMessage()); // $e->getResponseCode() holds the gateway code
    }

    $order->update(['token' => $token]);

    return IranKish::redirect($token); // auto-submitting form to the gateway
}
```

Options can be passed as the third argument:

```php
IranKish::requestToken($amount, $requestId, [
    'callback' => route('payment.callback'),       // override the configured revert url
    'payment_id' => '...',                         // Iranian standard payment identifier (شناسه پرداخت)
    'cms_preservation_id' => '989121234567',       // payer mobile, if enabled for your terminal
    'additional_parameters' => ['nationalId' => '...'],
]);
```

If you prefer your own view, build the form yourself:

```blade
<form action="{{ IranKish::paymentUrl() }}" method="POST">
    <input type="hidden" name="tokenIdentity" value="{{ $token }}">
    <button type="submit">ورود به درگاه پرداخت</button>
</form>
```

### 2. Verify the payment in the callback

The purchase **must** be confirmed after the customer returns, otherwise the gateway reverses it.

```php
public function callback(Request $request)
{
    $callback = IranKish::callback($request); // token, responseCode, requestId, retrievalReferenceNumber, ...
    $order = Order::findOrFail($callback['requestId']);

    // the posted fields come through the customer's browser: make sure the token is the one you issued
    abort_unless($callback['token'] === $order->token, 403);

    try {
        // checks the callback code and amount, then confirms the purchase
        $result = IranKish::verifyCallback($request, $order->amount);
    } catch (IranKishException $e) {
        return view('payment.failed', ['message' => $e->getMessage()]);
    }

    $order->update([
        'paid' => true,
        'reference' => $result['retrievalReferenceNumber'],
        'trace' => $result['systemTraceAuditNumber'],
    ]);

    return view('payment.success');
}
```

Or confirm with the values you already have:

```php
$result = IranKish::verify($token, $retrievalReferenceNumber, $systemTraceAuditNumber);
```

### Reverse and inquiry

```php
IranKish::reverse($token, $retrievalReferenceNumber, $systemTraceAuditNumber);

IranKish::inquiryByToken($token);
IranKish::inquiryByReferenceNumber($retrievalReferenceNumber);
IranKish::inquiryByRequestId($requestId);
```

### More than one terminal

```php
use MJSeydi\iranKish\IranKish;

$gateway = new IranKish([
    'terminalId' => '...',
    'password' => '...',
    'acceptor' => '...',
    'public_key' => '...',
    'callback' => '...',
]);
```

### Response codes

`MJSeydi\iranKish\Support\ResponseCode::message($code)` returns the Persian description of any gateway response code.

## Upgrading from the untagged `dev-master` version

- Requires PHP 8.2+ and Laravel 12 or 13. HTTP calls now go through Laravel's HTTP client, so they can be faked with `Http::fake()` in your tests.
- `getIranKishToken()` and `verifyPayment()` still exist and return the raw gateway response, but are deprecated in favour of `requestToken()` and `verifyCallback()` / `verify()`.
  They now throw `IranKishException` when the gateway can't be reached or the public key is invalid.
- The facade alias is now `IranKish` (it was mistakenly registered as `Calculator`).
- Config values can come from `.env`, and the config can be published with `--tag=irankish-config`.

## Testing

```bash
composer test
```

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## License

MIT
