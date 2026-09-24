# Changelog

## 1.0.0 - 2026-09-24

- Support Laravel 10, 11, 12 and 13 (PHP 8.1+).
- HTTP calls use Laravel's HTTP client (fakeable with `Http::fake()`), with a configurable timeout and base url.
- New `requestToken()`, `redirect()` / `redirectForm()` / `paymentUrl()`, `verify()`, `verifyCallback()`, `callback()`,
  `reverse()` and `inquiryBy*()` methods that throw `IranKishException` with the gateway response code.
- Order id is sent as `requestId` by `requestToken()`; `paymentId` is optional.
- Persian messages for all gateway response codes (`Support\ResponseCode`).
- Config reads from `.env`; public key may be PEM, bare base64 or a file path.
- Fixed the facade alias (was registered as `Calculator`).
- `getIranKishToken()` and `verifyPayment()` are kept for backward compatibility and deprecated.
