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

    // $message follows Lunar's convention — customer-safe for the checkout layer.
    // Use this for the detailed admin reason shown in the order panel or logs.
    public function adminMessage(): string
    {
        return $this->errorType?->adminMessage($this->message ?? '')
            ?? $this->message
            ?? '';
    }
}
