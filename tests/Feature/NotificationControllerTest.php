<?php

use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Events\PaymentConfirmed;
use Bpotmalnik\LunarPaynow\Events\PaymentFailed;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Illuminate\Support\Facades\Event;
use Lunar\Models\Transaction;

use function Pest\Laravel\assertDatabaseHas;

it('returns 401 when Signature header is missing', function () {
    $body = notificationBody('PBLA-111-222-333', 'CONFIRMED');

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        $body
    )->assertStatus(401);
});

it('returns 401 when Signature header is invalid', function () {
    $body = notificationBody('PBLA-111-222-333', 'CONFIRMED');

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => 'bad-sig', 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertStatus(401);
});

it('returns 401 when body is tampered after signing', function () {
    $originalBody = notificationBody('PBLA-111-222-333', 'CONFIRMED');
    $sig = notificationSignature($originalBody);
    $tamperedBody = notificationBody('PBLA-111-222-333', 'REJECTED');
    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $tamperedBody
    )->assertStatus(401);
});

it('returns 200 and does nothing when paymentId is missing', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);

    $body = json_encode(['status' => 'CONFIRMED']);
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
});

it('returns 200 and does nothing when status is missing', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);

    $body = json_encode(['paymentId' => 'PBLA-111-222-333']);
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
});

it('returns 200 and ignores NEW status', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['paynowPayment' => $p] = makePaynowPaymentWithOrder();

    $body = notificationBody($p->paynow_payment_id, 'NEW');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
});

it('returns 200 and ignores PENDING status', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['paynowPayment' => $p] = makePaynowPaymentWithOrder();

    $body = notificationBody($p->paynow_payment_id, 'PENDING');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
});

it('returns 200 and does nothing for an unknown paymentId', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);

    $body = notificationBody('PBLA-UNKNOWN-000', 'CONFIRMED');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
});

it('is idempotent for a payment already in CONFIRMED status', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['paynowPayment' => $p] = makePaynowPaymentWithOrder('PBLA-CONFIRM-DONE', 'CONFIRMED');

    $body = notificationBody($p->paynow_payment_id, 'CONFIRMED');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
    expect(Transaction::count())->toBe(1);
});

it('is idempotent for a payment already in REJECTED status', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['paynowPayment' => $p] = makePaynowPaymentWithOrder('PBLA-REJECT-DONE', 'REJECTED');

    $body = notificationBody($p->paynow_payment_id, 'CONFIRMED');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    Event::assertNothingDispatched();
});

it('creates a capture transaction, sets order status, and fires PaymentConfirmed on CONFIRMED', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['order' => $order, 'transaction' => $intentTx, 'paynowPayment' => $p] = makePaynowPaymentWithOrder();

    $body = notificationBody($p->paynow_payment_id, 'CONFIRMED');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    assertDatabaseHas(PaynowPayment::class, [
        'id' => $p->id,
        'status' => PaymentStatus::Confirmed->value,
    ]);

    assertDatabaseHas('lunar_transactions', [
        'parent_transaction_id' => $intentTx->id,
        'type' => 'capture',
        'success' => true,
        'driver' => 'paynow',
    ]);

    assertDatabaseHas('lunar_transactions', [
        'id' => $intentTx->id,
        'success' => true,
        'status' => PaymentStatus::Confirmed->value,
    ]);

    expect($order->fresh()->status)->toBe('payment-received');

    Event::assertDispatched(PaymentConfirmed::class, fn ($e) => $e->order->id === $order->id);
});

dataset('failed_statuses', [
    'REJECTED' => ['REJECTED'],
    'ABANDONED' => ['ABANDONED'],
    'EXPIRED' => ['EXPIRED'],
    'ERROR' => ['ERROR'],
]);

it('sets the order status and fires PaymentFailed for each terminal failure status', function (string $status) {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['order' => $order, 'paynowPayment' => $p] = makePaynowPaymentWithOrder("PBLA-FAIL-{$status}", 'PENDING');

    $body = notificationBody($p->paynow_payment_id, $status);
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    assertDatabaseHas(PaynowPayment::class, [
        'id' => $p->id,
        'status' => $status,
    ]);

    expect($order->fresh()->status)->toBe('payment-failed');

    Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->order->id === $order->id);
})->with('failed_statuses');

it('marks the intent transaction as failed on REJECTED', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    ['transaction' => $intentTx, 'paynowPayment' => $p] = makePaynowPaymentWithOrder();

    $body = notificationBody($p->paynow_payment_id, 'REJECTED');
    $sig = notificationSignature($body);

    $this->call('POST', route('paynow.notification'), [], [], [],
        ['HTTP_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        $body
    )->assertOk();

    assertDatabaseHas('lunar_transactions', [
        'id' => $intentTx->id,
        'success' => false,
        'status' => 'REJECTED',
    ]);
});
