<?php

namespace MJSeydi\iranKish\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string requestToken(int $amount, int|string|null $requestId = null, array $options = [])
 * @method static string paymentUrl()
 * @method static string redirectForm(string $token)
 * @method static \Illuminate\Http\Response redirect(string $token)
 * @method static array verify(string $token, string $retrievalReferenceNumber, string $systemTraceAuditNumber)
 * @method static array verifyCallback(\Illuminate\Http\Request|null $request = null, int|null $expectedAmount = null)
 * @method static array callback(\Illuminate\Http\Request|null $request = null)
 * @method static array reverse(string $token, string $retrievalReferenceNumber, string $systemTraceAuditNumber)
 * @method static array inquiryByReferenceNumber(string $retrievalReferenceNumber)
 * @method static array inquiryByToken(string $token)
 * @method static array inquiryByRequestId(string $requestId)
 * @method static array authenticationEnvelope(int $amount)
 * @method static array generateAuthenticationEnvelope($pub_key, $terminalID, $password, $amount)
 * @method static array getIranKishToken($Amount, $orderId)
 * @method static array verifyPayment($verifySaleReferenceId, $systemTraceAuditNumber, $token)
 *
 * @see \MJSeydi\iranKish\IranKish
 */
class IranKish extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MJSeydi\iranKish\IranKish::class;
    }
}
