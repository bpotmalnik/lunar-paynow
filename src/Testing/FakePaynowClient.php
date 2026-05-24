<?php

declare(strict_types=1);

namespace Bpotmalnik\LunarPaynow\Testing;

use Bpotmalnik\LunarPaynow\Contracts\PaynowClientContract;
use Bpotmalnik\LunarPaynow\Enums\RefundReason;

class FakePaynowClient implements PaynowClientContract
{
    public string $paymentId = 'fake-paynow-id-00001';

    public string $redirectUrl = 'https://paynow.pl/fake-redirect';

    /** @param array<string, mixed> $payload */
    public function createPayment(array $payload): array
    {
        return [
            'paymentId' => $this->paymentId,
            'status' => 'NEW',
            'redirectUrl' => $this->redirectUrl,
        ];
    }

    /** @return array<string, mixed> */
    public function getPaymentStatus(string $paymentId): array
    {
        return ['paymentId' => $paymentId, 'status' => 'CONFIRMED'];
    }

    /** @return array<string, mixed> */
    public function createRefund(string $paymentId, int $amount, ?RefundReason $reason = null): array
    {
        return ['refundId' => 'fake-refund-id', 'status' => 'PENDING'];
    }

    public function cancelRefund(string $refundId): void {}

    /** @return array<string, mixed> */
    public function getRefundStatus(string $refundId): array
    {
        return ['refundId' => $refundId, 'status' => 'PENDING'];
    }

    public function verifyNotificationSignature(string $payload, string $signature): bool
    {
        return true;
    }
}
