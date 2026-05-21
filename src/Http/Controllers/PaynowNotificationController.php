<?php

namespace Bpotmalnik\LunarPaynow\Http\Controllers;

use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Events\PaymentConfirmed;
use Bpotmalnik\LunarPaynow\Events\PaymentFailed;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Bpotmalnik\LunarPaynow\PaynowClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lunar\Models\Order;

class PaynowNotificationController extends Controller
{
    public function __invoke(Request $request, PaynowClient $client): Response
    {
        $rawBody = $request->getContent();
        $signature = $request->header('Signature', '');

        if (! $client->verifyNotificationSignature($rawBody, $signature)) {
            Log::warning('PayNow: invalid notification signature', ['ip' => $request->ip()]);

            return response('Forbidden', 401);
        }

        $data = $request->json()->all();

        if (empty($data['paymentId']) || empty($data['status'])) {
            return response('', 200);
        }

        $status = PaymentStatus::tryFrom($data['status']);

        if ($status === null || ! $status->isTerminal()) {
            return response('', 200);
        }

        DB::transaction(function () use ($data, $status) {
            $paynowPayment = PaynowPayment::lockForUpdate()
                ->where('paynow_payment_id', $data['paymentId'])
                ->first();

            if (! $paynowPayment || $paynowPayment->status?->isTerminal()) {
                return;
            }

            $paynowPayment->update(['status' => $status]);

            $order = $paynowPayment->order;

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
        });

        return response('', 200);
    }

    private function handleConfirmed(PaynowPayment $paynowPayment, Order $order): void
    {
        $intent = $paynowPayment->transaction;

        if (! $intent) {
            return;
        }

        $intent->update(['success' => true, 'status' => PaymentStatus::Confirmed->value]);

        $order->transactions()->create([
            'parent_transaction_id' => $intent->id,
            'type' => 'capture',
            'success' => true,
            'driver' => 'paynow',
            'amount' => $intent->amount->value,
            'reference' => $paynowPayment->paynow_payment_id,
            'status' => PaymentStatus::Confirmed->value,
            'card_type' => 'paynow',
            'meta' => ['paynow_payment_id' => $paynowPayment->paynow_payment_id],
        ]);

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
