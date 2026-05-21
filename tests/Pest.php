<?php

use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;
use Bpotmalnik\LunarPaynow\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lunar\DataTypes\Price;
use Lunar\DataTypes\ShippingOption;
use Lunar\Facades\ShippingManifest;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\CartLine;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Lunar\Models\Transaction;

uses(TestCase::class)->in('Feature');
uses(RefreshDatabase::class)->in('Feature');

function buildCart(array $overrides = []): Cart
{
    Language::factory()->create(['default' => true]);

    $currency = Currency::factory()->create(['default' => true]);

    $taxClass = TaxClass::factory()->create();

    $cart = Cart::factory()->create(array_merge(['currency_id' => $currency->id], $overrides));

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'Standard',
            description: 'Standard delivery',
            identifier: 'STANDARD',
            price: new Price(500, $currency, 1),
            taxClass: $taxClass,
        )
    );

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'shipping',
        'shipping_option' => 'STANDARD',
    ]);

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'billing',
    ]);

    $variant = ProductVariant::factory()->create();

    $variant->prices()->create([
        'price' => 1000,
        'currency_id' => $currency->id,
        'tier' => 1,
    ]);

    CartLine::factory()->create([
        'cart_id' => $cart->id,
        'purchasable_id' => $variant->id,
        'purchasable_type' => ProductVariant::class,
        'quantity' => 1,
    ]);

    return $cart->calculate();
}

function makePaynowPaymentWithOrder(string $paymentId = 'PBLA-111-222-333', string $statusValue = 'NEW'): array
{
    Language::factory()->create(['default' => true]);
    Currency::factory()->create(['default' => true]);

    $order = Order::factory()->create([
        'status' => 'draft',
        'total' => 10000,
        'sub_total' => 9000,
        'tax_total' => 1000,
    ]);

    $transaction = Transaction::factory()->create([
        'order_id' => $order->id,
        'type' => 'intent',
        'driver' => 'paynow',
        'amount' => 10000,
        'success' => false,
        'reference' => $paymentId,
        'status' => $statusValue,
        'card_type' => 'paynow',
        'meta' => ['paynow_payment_id' => $paymentId],
    ]);

    $paynowPayment = PaynowPayment::create([
        'order_id' => $order->id,
        'transaction_id' => $transaction->id,
        'paynow_payment_id' => $paymentId,
        'external_id' => Str::uuid(),
        'status' => PaymentStatus::from($statusValue),
        'amount' => 10000,
        'currency' => 'PLN',
        'redirect_url' => 'https://api.sandbox.paynow.pl/'.$paymentId,
    ]);

    return compact('order', 'transaction', 'paynowPayment');
}

function notificationBody(string $paymentId, string $status): string
{
    return json_encode([
        'paymentId' => $paymentId,
        'externalId' => 'ext-001',
        'status' => $status,
        'currency' => 'PLN',
        'amount' => 10000,
    ]);
}

function notificationSignature(string $body, string $key = 'test-signature-key'): string
{
    return base64_encode(hash_hmac('sha256', $body, $key, true));
}
