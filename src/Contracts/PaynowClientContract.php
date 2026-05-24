<?php

declare(strict_types=1);

namespace Bpotmalnik\LunarPaynow\Contracts;

use Bpotmalnik\LunarPaynow\Enums\RefundReason;

interface PaynowClientContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array;

    /** @return array<string, mixed> */
    public function getPaymentStatus(string $paymentId): array;

    /**
     * @return array<string, mixed>
     */
    public function createRefund(string $paymentId, int $amount, ?RefundReason $reason = null): array;

    public function cancelRefund(string $refundId): void;

    /** @return array<string, mixed> */
    public function getRefundStatus(string $refundId): array;

    public function verifyNotificationSignature(string $payload, string $signature): bool;
}
