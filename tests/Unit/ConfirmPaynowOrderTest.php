<?php

use Bpotmalnik\LunarPaynow\Actions\HandlePaynowPayment;
use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Events\PaymentConfirmed;
use Bpotmalnik\LunarPaynow\Events\PaymentFailed;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Bpotmalnik\LunarPaynow\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\assertDatabaseHas;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('marks the paynow payment as confirmed', function () {
    Event::fake([PaymentConfirmed::class]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, PaymentStatus::Confirmed);

    assertDatabaseHas(PaynowPayment::class, [
        'id' => $payment->id,
        'status' => PaymentStatus::Confirmed->value,
    ]);
});

it('marks the intent transaction as successful on CONFIRMED', function () {
    Event::fake([PaymentConfirmed::class]);
    ['paynowPayment' => $payment, 'order' => $order, 'transaction' => $intent] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, PaymentStatus::Confirmed);

    assertDatabaseHas('lunar_transactions', [
        'id' => $intent->id,
        'success' => true,
        'status' => PaymentStatus::Confirmed->value,
    ]);
});

it('creates a capture transaction linked to the intent on CONFIRMED', function () {
    Event::fake([PaymentConfirmed::class]);
    ['paynowPayment' => $payment, 'order' => $order, 'transaction' => $intent] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, PaymentStatus::Confirmed);

    assertDatabaseHas('lunar_transactions', [
        'parent_transaction_id' => $intent->id,
        'type' => 'capture',
        'success' => true,
        'driver' => 'paynow',
        'reference' => $payment->paynow_payment_id,
        'status' => PaymentStatus::Confirmed->value,
    ]);
});

it('sets placed_at on the order', function () {
    Event::fake([PaymentConfirmed::class]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    expect($order->placed_at)->toBeNull();

    app(HandlePaynowPayment::class)($payment, $order, PaymentStatus::Confirmed);

    expect($order->fresh()->placed_at)->not->toBeNull();
});

it('does not overwrite placed_at if already set', function () {
    Event::fake([PaymentConfirmed::class]);
    $placedAt = now()->subHour();
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();
    $order->update(['placed_at' => $placedAt]);

    app(HandlePaynowPayment::class)($payment, $order->fresh(), PaymentStatus::Confirmed);

    expect($order->fresh()->placed_at->toDateTimeString())->toBe($placedAt->toDateTimeString());
});

it('sets order status from config mapping on CONFIRMED', function () {
    Event::fake([PaymentConfirmed::class]);
    config(['lunar.paynow.status_mapping.CONFIRMED' => 'payment-received']);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, PaymentStatus::Confirmed);

    expect($order->fresh()->status)->toBe('payment-received');
});

it('dispatches PaymentConfirmed event', function () {
    Event::fake([PaymentConfirmed::class]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, PaymentStatus::Confirmed);

    Event::assertDispatched(PaymentConfirmed::class, fn ($e) => $e->order->id === $order->id
        && $e->paynowPayment->id === $payment->id
    );
});

it('still places the order when no intent transaction exists', function () {
    Event::fake([PaymentConfirmed::class]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();
    $payment->update(['transaction_id' => null]);

    app(HandlePaynowPayment::class)($payment->fresh(), $order, PaymentStatus::Confirmed);

    expect($order->fresh()->placed_at)->not->toBeNull();
    Event::assertDispatched(PaymentConfirmed::class);
});

dataset('failed_statuses', [
    'REJECTED' => [PaymentStatus::Rejected],
    'ABANDONED' => [PaymentStatus::Abandoned],
    'EXPIRED' => [PaymentStatus::Expired],
    'ERROR' => [PaymentStatus::Error],
]);

it('marks the paynow payment with the failed status', function (PaymentStatus $status) {
    Event::fake([PaymentFailed::class]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, $status);

    assertDatabaseHas(PaynowPayment::class, [
        'id' => $payment->id,
        'status' => $status->value,
    ]);
})->with('failed_statuses');

it('marks the intent transaction as failed', function (PaymentStatus $status) {
    Event::fake([PaymentFailed::class]);
    ['paynowPayment' => $payment, 'order' => $order, 'transaction' => $intent] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, $status);

    assertDatabaseHas('lunar_transactions', [
        'id' => $intent->id,
        'success' => false,
        'status' => $status->value,
    ]);
})->with('failed_statuses');

it('sets order status from config mapping on failure', function (PaymentStatus $status) {
    Event::fake([PaymentFailed::class]);
    config(['lunar.paynow.status_mapping' => array_fill_keys(
        ['REJECTED', 'ABANDONED', 'EXPIRED', 'ERROR'],
        'payment-failed'
    )]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, $status);

    expect($order->fresh()->status)->toBe('payment-failed');
})->with('failed_statuses');

it('dispatches PaymentFailed event', function (PaymentStatus $status) {
    Event::fake([PaymentFailed::class]);
    ['paynowPayment' => $payment, 'order' => $order] = makePaynowPaymentWithOrder();

    app(HandlePaynowPayment::class)($payment, $order, $status);

    Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->order->id === $order->id
        && $e->paynowPayment->id === $payment->id
    );
})->with('failed_statuses');
