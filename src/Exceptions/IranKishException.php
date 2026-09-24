<?php

namespace MJSeydi\iranKish\Exceptions;

use MJSeydi\iranKish\Support\ResponseCode;
use RuntimeException;
use Throwable;

class IranKishException extends RuntimeException
{
    public function __construct(
        string $message,
        protected ?string $responseCode = null,
        protected array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, is_numeric($responseCode) ? (int) $responseCode : 0, $previous);
    }

    public static function fromResponse(array $response, ?string $fallbackCode = null): static
    {
        // A confirmation carries a second code for the transaction itself
        // inside "result"; when that one failed it is the one that matters.
        $innerCode = $response['result']['responseCode'] ?? null;

        if ($innerCode !== null && ! ResponseCode::isSuccessful($innerCode)) {
            return new static(ResponseCode::message($innerCode), (string) $innerCode, $response);
        }

        $code = $response['responseCode'] ?? $fallbackCode;
        $code = $code === null ? null : (string) $code;
        $message = $response['description'] ?? null;

        if (! is_string($message) || $message === '') {
            $message = ResponseCode::message($code);
        }

        return new static($message, $code, $response);
    }

    public function getResponseCode(): ?string
    {
        return $this->responseCode;
    }

    public function getResponse(): array
    {
        return $this->response;
    }
}
