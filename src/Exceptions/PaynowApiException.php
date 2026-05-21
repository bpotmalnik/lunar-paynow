<?php

namespace Bpotmalnik\LunarPaynow\Exceptions;

use Bpotmalnik\LunarPaynow\Enums\ApiErrorType;
use RuntimeException;

class PaynowApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        public readonly ?ApiErrorType $errorType = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(int $status, array $body): self
    {
        $rawType = $body['errors'][0]['errorType']
            ?? $body['errorType']
            ?? null;

        $message = $body['errors'][0]['message']
            ?? $body['message']
            ?? "PayNow API error (HTTP {$status})";

        return new self(
            message: $message,
            statusCode: $status,
            errors: $body,
            errorType: $rawType !== null ? ApiErrorType::tryFrom($rawType) : null,
        );
    }
}
