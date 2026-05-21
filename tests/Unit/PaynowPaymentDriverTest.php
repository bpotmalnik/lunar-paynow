<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Bpotmalnik\LunarPaynow\Enums\ApiErrorType;
use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Exceptions\PaynowApiException;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Bpotmalnik\LunarPaynow\Models\PaynowRefund;
use Bpotmalnik\LunarPaynow\PaynowPaymentDriver;
use Bpotmalnik\LunarPaynow\Responses\PaymentAuthorize;
use Bpotmalnik\LunarPaynow\Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);





function makeOrder(int $total = 10000): Order
{
    Language::factory()->create(['default' => true]);
    Currency::factory()->create(['default' => true, 'code' => 'PLN']);

    return Order::factory()->create([
        'status'    => 'draft',
        'total'     => $total,
        'sub_total' => (int) ($total * 0.9),
        'tax_total' => (int) ($total * 0.1),
    ]);
}

function fakeSuccessfulPaynow(string $paymentId = 'PBLA-111-222-333'): void
{
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'paymentId'   => $paymentId,
            'redirectUrl' => "https://api.sandbox.paynow.pl/{$paymentId}?token=tok",
            'status'      => 'NEW',
        ], 201),
    ]);
}





it('fails when no order or cart is provided', function () {
    Event::fake();

    $result = app(PaynowPaymentDriver::class)->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('No order or cart provided');

    Event::assertDispatched(PaymentAttemptEvent::class, fn ($e) => ! $e->paymentAuthorize->success);
});

it('returns a redirect URL on success', function () {
    Event::fake();
    fakeSuccessfulPaynow();

    $order = makeOrder();

    $result = app(PaynowPaymentDriver::class)->order($order)->authorize();

    expect($result)->toBeInstanceOf(PaymentAuthorize::class)
        ->and($result->success)->toBeTrue()
        ->and($result->paymentType)->toBe('paynow')
        ->and($result->redirectUrl)->toContain('PBLA-111-222-333')
        ->and($result->orderId)->toBe($order->id);

    Event::assertDispatched(PaymentAttemptEvent::class, fn ($e) => $e->paymentAuthorize->success);
});

it('creates an intent transaction and payment record', function () {
    fakeSuccessfulPaynow();

    $order = makeOrder();

    app(PaynowPaymentDriver::class)->order($order)->authorize();

    $this->assertDatabaseHas('transactions', [
        'order_id'  => $order->id,
        'type'      => 'intent',
        'success'   => false,
        'driver'    => 'paynow',
        'reference' => 'PBLA-111-222-333',
    ]);

    $this->assertDatabaseHas(PaynowPayment::class, [
        'order_id'          => $order->id,
        'paynow_payment_id' => 'PBLA-111-222-333',
        'status'            => PaymentStatus::New->value,
    ]);
});

it('reuses a pending payment to avoid a double charge', function () {
    Event::fake();

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => false,
        'reference' => 'PBLA-EXISTING-001',
        'status'    => 'PENDING',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-EXISTING-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Pending,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://api.sandbox.paynow.pl/PBLA-EXISTING-001',
    ]);

    $result = app(PaynowPaymentDriver::class)->order($order)->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toContain('PBLA-EXISTING-001');

    Http::assertNothingSent();
});

it('dispatches a failure event when the API throws', function () {
    Event::fake();

    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response(['error' => 'Bad request'], 400),
    ]);

    $order = makeOrder();

    $result = app(PaynowPaymentDriver::class)->order($order)->authorize();

    expect($result->success)->toBeFalse();

    Event::assertDispatched(PaymentAttemptEvent::class, fn ($e) => ! $e->paymentAuthorize->success);
});

it('does not create a payment record on API failure', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response(null, 500),
    ]);

    $order = makeOrder();

    app(PaynowPaymentDriver::class)->order($order)->authorize();

    $this->assertDatabaseCount(PaynowPayment::class, 0);
});

it('passes the continue_url to PayNow', function () {
    fakeSuccessfulPaynow();

    $order = makeOrder();

    app(PaynowPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://myshop.com/checkout/complete'])
        ->authorize();

    Http::assertSent(fn ($req) => str_contains(
        $req->body(),
        'myshop.com/checkout/complete'
    ));
});





it('capture always succeeds', function () {
    $tx = Mockery::mock(Transaction::class);

    $result = app(PaynowPaymentDriver::class)->capture($tx, 0);

    expect($result->success)->toBeTrue();
});





it('refund fails when no payment record exists for the transaction', function () {
    $tx = Transaction::factory()->create([
        'order_id'  => Order::factory()->create()->id,
        'type'      => 'capture',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-MISSING',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($tx, 5000);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.admin.refund_record_not_found'));
});

it('refund creates a refund transaction on success', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFX-111-222-333',
            'status'   => 'NEW',
        ], 201),
    ]);

    $order = makeOrder();

    $intentTx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-111-222-333',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $intentTx->id,
        'paynow_payment_id' => 'PBLA-111-222-333',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://api.sandbox.paynow.pl/PBLA-111-222-333',
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($intentTx, 5000, 'Customer request');

    expect($result->success)->toBeTrue();

    $this->assertDatabaseHas('transactions', [
        'parent_transaction_id' => $intentTx->id,
        'type'                  => 'refund',
        'success'               => true,
        'driver'                => 'paynow',
        'amount'                => 5000,
        'reference'             => 'REFX-111-222-333',
    ]);
});

it('refunds the full transaction amount when no amount is given', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFX-FULL',
            'status'   => 'NEW',
        ], 201),
    ]);

    $order = makeOrder(10000);

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-222-333-444',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-222-333-444',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    app(PaynowPaymentDriver::class)->refund($tx, 0);

    Http::assertSent(fn ($req) => data_get($req->data(), 'amount') === 10000);
});

it('does not create a refund transaction on API error', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response(null, 422),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-333-444-555',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-333-444-555',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($tx, 5000);

    expect($result->success)->toBeFalse();

    $this->assertDatabaseMissing('transactions', ['type' => 'refund']);
});





it('recovery reuses the original externalId', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'paymentId'   => 'PBLA-RECOVERY-001',
            'redirectUrl' => 'https://api.sandbox.paynow.pl/PBLA-RECOVERY-001?token=new',
            'status'      => 'NEW',
        ], 201),
    ]);

    $order = makeOrder();
    $originalExternalId = (string) \Illuminate\Support\Str::uuid();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => false,
        'reference' => 'PBLA-ORIGINAL-001',
        'status'    => 'REJECTED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $failedPayment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-ORIGINAL-001',
        'external_id'       => $originalExternalId,
        'status'            => PaymentStatus::Rejected,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://api.sandbox.paynow.pl/PBLA-ORIGINAL-001',
    ]);

    $result = app(PaynowPaymentDriver::class)
        ->order($order)
        ->recoverFrom($failedPayment)
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toContain('PBLA-RECOVERY-001');

    // PayNow must receive the same externalId
    Http::assertSent(fn ($req) => str_contains($req->body(), $originalExternalId));
});

it('recovery links the new payment to the original via parent_payment_id', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'paymentId'   => 'PBLA-RECOVERY-002',
            'redirectUrl' => 'https://api.sandbox.paynow.pl/PBLA-RECOVERY-002?token=new',
            'status'      => 'NEW',
        ], 201),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => false,
        'reference' => 'PBLA-ORIGINAL-002',
        'status'    => 'ERROR',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $failedPayment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-ORIGINAL-002',
        'external_id'       => (string) \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Error,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    app(PaynowPaymentDriver::class)
        ->order($order)
        ->recoverFrom($failedPayment)
        ->authorize();

    $this->assertDatabaseHas(PaynowPayment::class, [
        'paynow_payment_id' => 'PBLA-RECOVERY-002',
        'parent_payment_id' => $failedPayment->id,
        'external_id'       => $failedPayment->external_id,
    ]);
});

it('recovery falls back to the failed payment order', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'paymentId'   => 'PBLA-RECOVERY-003',
            'redirectUrl' => 'https://api.sandbox.paynow.pl/PBLA-RECOVERY-003?token=new',
            'status'      => 'NEW',
        ], 201),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => false,
        'reference' => 'PBLA-ORIGINAL-003',
        'status'    => 'PENDING',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $failedPayment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-ORIGINAL-003',
        'external_id'       => (string) \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Pending,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    // No ->order() chained — driver must pick it up from the failed payment
    $result = app(PaynowPaymentDriver::class)
        ->recoverFrom($failedPayment)
        ->authorize();

    expect($result->success)->toBeTrue();
});

it('recovery fails when the payment is not in a recoverable status', function () {
    Event::fake();

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-CONFIRMED-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $confirmedPayment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-CONFIRMED-001',
        'external_id'       => (string) \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $result = app(PaynowPaymentDriver::class)
        ->order($order)
        ->recoverFrom($confirmedPayment)
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('not in a recoverable status');

    Http::assertNothingSent();
    Event::assertDispatched(PaymentAttemptEvent::class, fn ($e) => ! $e->paymentAuthorize->success);
});





it('refund fails when the source payment is not confirmed', function () {
    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => false,
        'reference' => 'PBLA-PENDING-001',
        'status'    => 'PENDING',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-PENDING-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Pending,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($tx, 5000);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.admin.refund_not_confirmed', [
            'status' => 'PENDING',
        ]));

    Http::assertNothingSent();
});

it('refund fails when the amount exceeds the refundable balance', function () {
    $order = makeOrder(10000);

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-OVER-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $payment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-OVER-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    // Existing pending refund of 8000
    \Bpotmalnik\LunarPaynow\Models\PaynowRefund::create([
        'paynow_payment_id' => $payment->id,
        'refund_id'         => 'REFX-EXISTING-001',
        'status'            => 'PENDING',
        'amount'            => 8000,
    ]);

    // Trying to refund 5000 more when only 2000 remains
    $result = app(PaynowPaymentDriver::class)->refund($tx, 5000);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.admin.refund_exceeds_balance', [
            'amount'    => 5000,
            'available' => 2000,
        ]));

    Http::assertNothingSent();
});

it('creates a PaynowRefund record and Lunar transaction on success', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFX-NEW-001',
            'status'   => 'PENDING',
        ], 201),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-REFUND-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-REFUND-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($tx, 3000);

    expect($result->success)->toBeTrue();

    $this->assertDatabaseHas(PaynowRefund::class, [
        'refund_id' => 'REFX-NEW-001',
        'status'    => 'PENDING',
        'amount'    => 3000,
    ]);

    $this->assertDatabaseHas('transactions', [
        'parent_transaction_id' => $tx->id,
        'type'                  => 'refund',
        'reference'             => 'REFX-NEW-001',
        'amount'                => 3000,
    ]);
});

it('sends the refund reason when provided', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFX-REASON-001',
            'status'   => 'PENDING',
        ], 201),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-REASON-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-REASON-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    app(PaynowPaymentDriver::class)
        ->withData(['refund_reason' => 'RMA'])
        ->refund($tx, 1000);

    Http::assertSent(fn ($req) => data_get($req->data(), 'reason') === 'RMA');
});

it('excludes failed and cancelled refunds from the available balance', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFX-BALANCE-001',
            'status'   => 'PENDING',
        ], 201),
    ]);

    $order = makeOrder(10000);

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-BALANCE-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $payment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-BALANCE-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    // A failed refund should NOT reduce the available balance
    \Bpotmalnik\LunarPaynow\Models\PaynowRefund::create([
        'paynow_payment_id' => $payment->id,
        'refund_id'         => 'REFX-FAILED-PREV',
        'status'            => 'FAILED',
        'amount'            => 9000,
    ]);

    // Full 10000 should still be refundable despite the failed attempt
    $result = app(PaynowPaymentDriver::class)->refund($tx, 10000);

    expect($result->success)->toBeTrue();
});





it('cancels a NEW refund and updates both statuses', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/REFX-CANCEL-001/cancel' => Http::response(null, 200),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-CANCEL-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $payment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-CANCEL-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $refundTx = Transaction::factory()->create([
        'order_id'              => $order->id,
        'parent_transaction_id' => $tx->id,
        'type'                  => 'refund',
        'driver'                => 'paynow',
        'amount'                => 5000,
        'success'               => true,
        'reference'             => 'REFX-CANCEL-001',
        'status'                => 'NEW',
        'card_type'             => 'paynow',
        'meta'                  => [],
    ]);

    $refund = \Bpotmalnik\LunarPaynow\Models\PaynowRefund::create([
        'paynow_payment_id'    => $payment->id,
        'lunar_transaction_id' => $refundTx->id,
        'refund_id'            => 'REFX-CANCEL-001',
        'status'               => 'NEW',
        'amount'               => 5000,
    ]);

    $result = app(PaynowPaymentDriver::class)->cancelRefund($refund);

    expect($result->success)->toBeTrue();

    $this->assertDatabaseHas(PaynowRefund::class, [
        'id'     => $refund->id,
        'status' => 'CANCELLED',
    ]);

    $this->assertDatabaseHas('transactions', [
        'id'      => $refundTx->id,
        'success' => false,
        'status'  => 'CANCELLED',
    ]);
});

it('cancel fails when the refund is not in NEW status', function () {
    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-NOCANCL-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $payment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-NOCANCL-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $refund = \Bpotmalnik\LunarPaynow\Models\PaynowRefund::create([
        'paynow_payment_id' => $payment->id,
        'refund_id'         => 'REFX-NOCANCL-001',
        'status'            => 'PENDING',
        'amount'            => 5000,
    ]);

    $result = app(PaynowPaymentDriver::class)->cancelRefund($refund);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('PENDING');

    Http::assertNothingSent();
});

it('cancel fails when the API rejects the cancellation', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/*/cancel' => Http::response(
            ['errors' => [['errorType' => 'CONFLICT', 'message' => 'Already processing']]],
            400
        ),
    ]);

    $order = makeOrder();

    $tx = Transaction::factory()->create([
        'order_id'  => $order->id,
        'type'      => 'intent',
        'driver'    => 'paynow',
        'amount'    => 10000,
        'success'   => true,
        'reference' => 'PBLA-APIERR-001',
        'status'    => 'CONFIRMED',
        'card_type' => 'paynow',
        'meta'      => [],
    ]);

    $payment = PaynowPayment::create([
        'order_id'          => $order->id,
        'transaction_id'    => $tx->id,
        'paynow_payment_id' => 'PBLA-APIERR-001',
        'external_id'       => \Illuminate\Support\Str::uuid(),
        'status'            => PaymentStatus::Confirmed,
        'amount'            => 10000,
        'currency'          => 'PLN',
        'redirect_url'      => 'https://example.com',
    ]);

    $refund = \Bpotmalnik\LunarPaynow\Models\PaynowRefund::create([
        'paynow_payment_id' => $payment->id,
        'refund_id'         => 'REFX-APIERR-001',
        'status'            => 'NEW',
        'amount'            => 5000,
    ]);

    $result = app(PaynowPaymentDriver::class)->cancelRefund($refund);

    expect($result->success)->toBeFalse();

    // Status must not have been updated on API failure
    $this->assertDatabaseHas(PaynowRefund::class, [
        'id'     => $refund->id,
        'status' => 'NEW',
    ]);
});





it('uses a customer-safe message and logs critical for VERIFICATION_FAILED', function () {
    Log::shouldReceive('critical')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'PAYNOW_SIGNATURE_KEY'));

    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'errors' => [['errorType' => 'VERIFICATION_FAILED', 'message' => 'Your signature header is incorrect']],
        ], 401),
    ]);

    $result = app(PaynowPaymentDriver::class)->order(makeOrder())->authorize();

    // message is customer-safe — generic fallback because VERIFICATION_FAILED is admin-only
    expect($result->success)->toBeFalse()
        ->and($result->errorType)->toBe(ApiErrorType::VerificationFailed)
        ->and($result->message)->toBe(trans('lunar-paynow::errors.customer.generic'));

    // admin gets detail via adminMessage()
    expect($result->adminMessage())->toBe(trans('lunar-paynow::errors.admin.verification_failed'));
});

it('uses a customer-safe message for SYSTEM_TEMPORARILY_UNAVAILABLE', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'errorType' => 'SYSTEM_TEMPORARILY_UNAVAILABLE', 'message' => 'down',
        ], 503),
    ]);

    $result = app(PaynowPaymentDriver::class)->order(makeOrder())->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->errorType)->toBe(ApiErrorType::SystemTemporarilyUnavailable)
        ->and($result->message)->toBe(trans('lunar-paynow::errors.customer.system_temporarily_unavailable'));
});

it('uses a customer-safe message for PAYMENT_AMOUNT_TOO_SMALL', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'errors' => [['errorType' => 'PAYMENT_AMOUNT_TOO_SMALL', 'message' => 'too small']],
        ], 400),
    ]);

    $result = app(PaynowPaymentDriver::class)->order(makeOrder(50))->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->errorType->isCustomerSafe())->toBeTrue()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.customer.payment_amount_too_small'));
});

it('falls back to the generic customer message for an unknown error type', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'errors' => [['errorType' => 'SOME_FUTURE_ERROR', 'message' => 'Something unexpected']],
        ], 400),
    ]);

    $result = app(PaynowPaymentDriver::class)->order(makeOrder())->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->errorType)->toBeNull()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.customer.generic'));
});

it('uses the admin translation for REFUND_POSSIBILITY_EXPIRED', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'errorType' => 'REFUND_POSSIBILITY_EXPIRED', 'message' => 'expired',
        ], 422),
    ]);

    $order = makeOrder();
    $tx = Transaction::factory()->create([
        'order_id' => $order->id, 'type' => 'intent', 'driver' => 'paynow',
        'amount' => 10000, 'success' => true, 'reference' => 'PBLA-EXP-001',
        'status' => 'CONFIRMED', 'card_type' => 'paynow', 'meta' => [],
    ]);
    PaynowPayment::create([
        'order_id' => $order->id, 'transaction_id' => $tx->id,
        'paynow_payment_id' => 'PBLA-EXP-001', 'external_id' => \Illuminate\Support\Str::uuid(),
        'status' => PaymentStatus::Confirmed, 'amount' => 10000, 'currency' => 'PLN', 'redirect_url' => 'https://example.com',
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($tx, 5000);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.admin.refund_possibility_expired'));
});

it('uses the admin translation for INSUFFICIENT_BALANCE_FUNDS', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'errors' => [['errorType' => 'INSUFFICIENT_BALANCE_FUNDS', 'message' => 'no funds']],
        ], 422),
    ]);

    $order = makeOrder();
    $tx = Transaction::factory()->create([
        'order_id' => $order->id, 'type' => 'intent', 'driver' => 'paynow',
        'amount' => 10000, 'success' => true, 'reference' => 'PBLA-BAL-001',
        'status' => 'CONFIRMED', 'card_type' => 'paynow', 'meta' => [],
    ]);
    PaynowPayment::create([
        'order_id' => $order->id, 'transaction_id' => $tx->id,
        'paynow_payment_id' => 'PBLA-BAL-001', 'external_id' => \Illuminate\Support\Str::uuid(),
        'status' => PaymentStatus::Confirmed, 'amount' => 10000, 'currency' => 'PLN', 'redirect_url' => 'https://example.com',
    ]);

    $result = app(PaynowPaymentDriver::class)->refund($tx, 5000);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(trans('lunar-paynow::errors.admin.insufficient_balance_funds'));
});
