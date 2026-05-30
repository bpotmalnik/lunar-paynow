<?php

namespace Bpotmalnik\LunarPaynow\Actions;

use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Events\PaymentConfirmed;
use Bpotmalnik\LunarPaynow\Events\PaymentFailed;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Lunar\Models\Order;

class HandlePaynowPayment
{
    public function __invoke(PaynowPayment $paynowPayment, Order $order, PaymentStatus $status): void
    {
        $paynowPayment->update(['status' => $status]);

        if ($status->isSuccessful()) {
            $this->handleConfirmed($paynowPayment, $order);
        } else {
            $this->handleFailed($paynowPayment);
        }

        $mapping = config('lunar.paynow.status_mapping', []);
        $order->update(['status' => $mapping[$status->value] ?? $mapping['ERROR'] ?? 'payment-failed']);

        $status->isSuccessful()
            ? PaymentConfirmed::dispatch($paynowPayment, $order)
            : PaymentFailed::dispatch($paynowPayment, $order);
    }

    private function handleConfirmed(PaynowPayment $paynowPayment, Order $order): void
    {
        $intent = $paynowPayment->transaction;

        if ($intent) {
            $intent->update(['success' => true, 'status' => PaymentStatus::Confirmed->value]);

            $order->transactions()->create([
                'parent_transaction_id' => $intent->id,
                'type' => 'capture',
                'success' => true,
                'driver' => 'paynow',
                // @phpstan-ignore-next-line
                'amount' => $intent->amount->value,
                'reference' => $paynowPayment->paynow_payment_id,
                'status' => PaymentStatus::Confirmed->value,
                'card_type' => 'paynow',
                'meta' => ['paynow_payment_id' => $paynowPayment->paynow_payment_id],
            ]);
        }

        $order->update(['placed_at' => $order->placed_at ?? now()]);
    }

    private function handleFailed(PaynowPayment $paynowPayment): void
    {
        $paynowPayment->transaction?->update([
            'success' => false,
            'status' => $paynowPayment->status->value,
        ]);
    }
}
