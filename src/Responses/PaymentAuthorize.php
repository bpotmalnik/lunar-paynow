<?php

namespace Bpotmalnik\LunarPaynow\Responses;

use Bpotmalnik\LunarPaynow\Enums\ApiErrorType;
use Lunar\Base\DataTransferObjects\PaymentAuthorize as BasePaymentAuthorize;

class PaymentAuthorize extends BasePaymentAuthorize
{
    public function __construct(
        public bool $success = false,
        public ?string $message = null,
        public ?int $orderId = null,
        public ?string $paymentType = null,
        public ?string $redirectUrl = null,
        public ?ApiErrorType $errorType = null,
    ) {}

    public function adminMessage(): string
    {
        return $this->errorType?->adminMessage($this->message ?? '')
            ?? $this->message
            ?? '';
    }
}
