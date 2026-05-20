<?php

namespace Bpotmalnik\LunarPaynow;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\Models\Contracts\Transaction as TransactionContract;
use Bpotmalnik\LunarPaynow\Enums\ApiErrorType;
use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Enums\RefundReason;
use Bpotmalnik\LunarPaynow\Enums\RefundStatus;
use Bpotmalnik\LunarPaynow\Exceptions\PaynowApiException;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Bpotmalnik\LunarPaynow\Models\PaynowRefund;
use Bpotmalnik\LunarPaynow\Responses\PaymentAuthorize;
use Lunar\PaymentTypes\AbstractPayment;

class PaynowPaymentDriver extends AbstractPayment
{
    protected ?PaynowPayment $recoveryPayment = null;

    public function __construct(private readonly PaynowClient $client) {}

    public function recoverFrom(PaynowPayment $failedPayment): static
    {
        $this->recoveryPayment = $failedPayment;

        return $this;
    }

    public function authorize(): PaymentAuthorize
    {
        if ($this->recoveryPayment) {
            return $this->authorizeRecovery();
        }

        if (! $this->order && $this->cart) {
            $this->order = $this->cart->draftOrder ?? $this->cart->createOrder();
        }

        if (! $this->order) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-paynow::errors.customer.generic'),
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        $order = $this->order;

        if ($order->placed_at) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-paynow::errors.customer.generic'),
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        // Reuse an existing pending payment rather than creating a new charge
        // when the customer hits the back button before completing payment.
        $existing = PaynowPayment::where('order_id', $order->id)
            ->whereNotIn('status', [
                PaymentStatus::Confirmed->value,
                PaymentStatus::Rejected->value,
                PaymentStatus::Abandoned->value,
                PaymentStatus::Expired->value,
                PaymentStatus::Error->value,
            ])
            ->latest()
            ->first();

        if ($existing?->redirect_url) {
            $response = new PaymentAuthorize(
                success: true,
                orderId: $order->id,
                paymentType: 'paynow',
                redirectUrl: $existing->redirect_url,
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        return $this->callPaynow((string) Str::uuid(), null);
    }

    // PayNow confirms and captures in one step via the CONFIRMED notification.
    public function capture(TransactionContract $transaction, $amount = 0): PaymentCapture
    {
        return new PaymentCapture(success: true);
    }

    public function refund(TransactionContract $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        // Both intent and capture transactions store the PayNow payment ID in `reference`.
        $paynowPayment = PaynowPayment::where('paynow_payment_id', $transaction->reference)->first();

        if (! $paynowPayment) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-paynow::errors.admin.refund_record_not_found'),
            );
        }

        if (! $paynowPayment->status->isSuccessful()) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-paynow::errors.admin.refund_not_confirmed', [
                    'status' => $paynowPayment->status->value,
                ]),
            );
        }

        $refundAmount = $amount ?: $transaction->amount->value;
        $available = $paynowPayment->refundableAmount();

        if ($refundAmount > $available) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-paynow::errors.admin.refund_exceeds_balance', [
                    'amount'    => $refundAmount,
                    'available' => $available,
                ]),
            );
        }

        $reason = isset($this->data['refund_reason'])
            ? RefundReason::from($this->data['refund_reason'])
            : null;

        try {
            $apiResponse = $this->client->createRefund(
                paymentId: $paynowPayment->paynow_payment_id,
                amount: $refundAmount,
                reason: $reason,
            );

            $lunarTx = $transaction->order->transactions()->create([
                'parent_transaction_id' => $transaction->id,
                'type'                  => 'refund',
                'success'               => true,
                'driver'                => 'paynow',
                'amount'                => $refundAmount,
                'reference'             => $apiResponse['refundId'],
                'status'                => $apiResponse['status'],
                'card_type'             => 'paynow',
                'notes'                 => $notes,
                'meta'                  => ['refund_id' => $apiResponse['refundId']],
            ]);

            PaynowRefund::create([
                'paynow_payment_id'    => $paynowPayment->id,
                'lunar_transaction_id' => $lunarTx->id,
                'refund_id'            => $apiResponse['refundId'],
                'status'               => $apiResponse['status'],
                'amount'               => $refundAmount,
            ]);
        } catch (PaynowApiException $e) {
            return new PaymentRefund(success: false, message: $this->adminMessage($e));
        }

        return new PaymentRefund(success: true);
    }

    public function cancelRefund(PaynowRefund $refund): PaymentRefund
    {
        if (! $refund->isCancellable()) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-paynow::errors.admin.refund_not_cancellable', [
                    'refund_id' => $refund->refund_id,
                    'status'    => $refund->status->value,
                ]),
            );
        }

        try {
            $this->client->cancelRefund($refund->refund_id);
        } catch (PaynowApiException $e) {
            return new PaymentRefund(success: false, message: $this->adminMessage($e));
        }

        $refund->update(['status' => RefundStatus::Cancelled]);

        $refund->lunarTransaction?->update(['success' => false, 'status' => 'CANCELLED']);

        return new PaymentRefund(success: true);
    }

    private function authorizeRecovery(): PaymentAuthorize
    {
        $failed = $this->recoveryPayment;

        if (! $failed->isRecoverable()) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-paynow::errors.customer.generic'),
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        $this->order ??= $failed->order;

        return $this->callPaynow($failed->external_id, $failed->id);
    }

    private function callPaynow(string $externalId, ?int $parentPaymentId): PaymentAuthorize
    {
        $order = $this->order;

        $email = $order->billingAddress?->contact_email ?? $this->cart?->user?->email;

        if (! $email) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-paynow::errors.customer.generic'),
                errorType: null,
            );
            Log::warning('PayNow: missing buyer email', ['order' => $order->id]);
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        try {
            $payload = array_filter([
                'amount'       => $order->total->value,
                'currency'     => $order->currency_code,
                'externalId'   => $externalId,
                'description'  => $this->data['description'] ?? config('lunar.paynow.description', 'Order payment'),
                'continueUrl'  => $this->data['continue_url'] ?? null,
                'validityTime' => config('lunar.paynow.validity_time', 3600),
                'buyer'        => array_filter([
                    'email'     => $email,
                    'firstName' => $order->billingAddress?->first_name,
                    'lastName'  => $order->billingAddress?->last_name,
                    'phone'     => $order->billingAddress?->contact_phone,
                ]),
                'orderItems' => $this->buildOrderItems(),
            ]);

            $apiResponse = $this->client->createPayment($payload);

            $transaction = $order->transactions()->create([
                'type'      => 'intent',
                'success'   => false,
                'driver'    => 'paynow',
                'amount'    => $order->total->value,
                'reference' => $apiResponse['paymentId'],
                'status'    => $apiResponse['status'],
                'card_type' => 'paynow',
                'meta'      => ['paynow_payment_id' => $apiResponse['paymentId']],
            ]);

            PaynowPayment::create([
                'order_id'          => $order->id,
                'transaction_id'    => $transaction->id,
                'paynow_payment_id' => $apiResponse['paymentId'],
                'external_id'       => $externalId,
                'status'            => $apiResponse['status'],
                'amount'            => $order->total->value,
                'currency'          => $order->currency_code,
                'redirect_url'      => $apiResponse['redirectUrl'],
                'parent_payment_id' => $parentPaymentId,
            ]);
        } catch (PaynowApiException $e) {
            if ($e->errorType === ApiErrorType::VerificationFailed) {
                Log::critical('PayNow signature verification failed — check PAYNOW_SIGNATURE_KEY', [
                    'order' => $order->id,
                ]);
            }

            // authorize() runs in checkout context — message must be safe for the customer.
            $response = new PaymentAuthorize(
                success: false,
                message: $e->errorType?->customerMessage()
                    ?? trans('lunar-paynow::errors.customer.generic'),
                errorType: $e->errorType,
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        $response = new PaymentAuthorize(
            success: true,
            orderId: $order->id,
            paymentType: 'paynow',
            redirectUrl: $apiResponse['redirectUrl'],
        );

        PaymentAttemptEvent::dispatch($response);

        return $response;
    }

    private function adminMessage(PaynowApiException $e): string
    {
        return $e->errorType?->adminMessage($e->getMessage())
            ?? trans('lunar-paynow::errors.admin.generic', ['message' => $e->getMessage()]);
    }

    private function buildOrderItems(): array
    {
        if (! $this->cart?->lines) {
            return [];
        }

        return $this->cart->lines->map(fn ($line) => [
            'name'     => $line->purchasable->translateAttribute('name'),
            'quantity' => $line->quantity,
            'price'    => $line->unitPrice->value,
        ])->values()->all();
    }
}
