<?php

namespace Bpotmalnik\LunarPaynow\Http\Controllers;

use Bpotmalnik\LunarPaynow\Actions\HandlePaynowPayment;
use Bpotmalnik\LunarPaynow\Contracts\PaynowClientContract;
use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaynowNotificationController extends Controller
{
    public function __invoke(Request $request, PaynowClientContract $client): Response
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

            if (! $paynowPayment || $paynowPayment->status->isTerminal()) {
                return;
            }

            $order = $paynowPayment->order;

            if (! $order) {
                return;
            }

            app(HandlePaynowPayment::class)($paynowPayment, $order, $status);
        });

        return response('', 200);
    }
}
