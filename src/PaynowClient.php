<?php

namespace Bpotmalnik\LunarPaynow;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Bpotmalnik\LunarPaynow\Enums\RefundReason;
use Bpotmalnik\LunarPaynow\Exceptions\PaynowApiException;

class PaynowClient
{
    private const SANDBOX_URL = 'https://api.sandbox.paynow.pl';

    private const PRODUCTION_URL = 'https://api.paynow.pl';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $signatureKey,
        private readonly bool $sandbox = false,
    ) {}

    public function createPayment(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->post('/v3/payments', $body, $this->idempotencyKey($payload['externalId']));
    }

    public function getPaymentStatus(string $paymentId): array
    {
        return $this->get("/v3/payments/{$paymentId}/status", $this->idempotencyKey("status-{$paymentId}"));
    }

    public function createRefund(string $paymentId, int $amount, ?RefundReason $reason = null): array
    {
        $payload = array_filter(['amount' => $amount, 'reason' => $reason?->value]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->post("/v3/payments/{$paymentId}/refunds", $body, $this->idempotencyKey("refund-{$paymentId}-{$amount}"));
    }

    public function cancelRefund(string $refundId): void
    {
        $this->post("/v3/refunds/{$refundId}/cancel", '', $this->idempotencyKey("cancel-{$refundId}"));
    }

    public function getRefundStatus(string $refundId): array
    {
        return $this->get("/v3/refunds/{$refundId}/status", $this->idempotencyKey("refund-status-{$refundId}"));
    }

    public function verifyNotificationSignature(string $payload, string $signature): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $payload, $this->signatureKey, true));

        return hash_equals($expected, $signature);
    }

    private function post(string $uri, string $body, string $idempotencyKey): array
    {
        try {
            return Http::withHeaders($this->headers($body, $idempotencyKey))
                ->withBody($body, 'application/json')
                ->post($this->baseUrl().$uri)
                ->throw()
                ->json() ?? [];
        } catch (RequestException $e) {
            throw PaynowApiException::fromResponse(
                $e->response->status(),
                $e->response->json() ?? [],
            );
        }
    }

    private function get(string $uri, string $idempotencyKey): array
    {
        try {
            return Http::withHeaders($this->headers('', $idempotencyKey))
                ->get($this->baseUrl().$uri)
                ->throw()
                ->json() ?? [];
        } catch (RequestException $e) {
            throw PaynowApiException::fromResponse(
                $e->response->status(),
                $e->response->json() ?? [],
            );
        }
    }

    private function headers(string $body, string $idempotencyKey): array
    {
        return [
            'Api-Key'         => $this->apiKey,
            'Signature'       => $this->sign($body),
            'Idempotency-Key' => $idempotencyKey,
            'Accept'          => 'application/json',
        ];
    }

    private function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->signatureKey, true));
    }

    private function idempotencyKey(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 45);
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }
}
