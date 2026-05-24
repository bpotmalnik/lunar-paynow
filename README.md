# lunar-paynow

<p>
    <a href="https://packagist.org/packages/bpotmalnik/lunar-paynow"><img src="https://img.shields.io/packagist/v/bpotmalnik/lunar-paynow" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/bpotmalnik/lunar-paynow"><img src="https://img.shields.io/packagist/php-v/bpotmalnik/lunar-paynow" alt="PHP Version"></a>
    <a href="https://packagist.org/packages/bpotmalnik/lunar-paynow"><img src="https://img.shields.io/packagist/l/bpotmalnik/lunar-paynow" alt="License"></a>
</p>

PayNow v3 payment driver for [LunarPHP](https://lunarphp.io). Handles the full payment lifecycle — authorization, notifications, refunds, partial refunds, refund cancellation, and payment recovery — following Lunar's payment driver conventions.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Authorizing a payment](#authorizing-a-payment)
  - [Handling the redirect](#handling-the-redirect)
  - [Payment recovery](#payment-recovery)
  - [Refunding](#refunding)
  - [Cancelling a refund](#cancelling-a-refund)
- [Notifications](#notifications)
- [Events](#events)
- [Error messages](#error-messages)
- [Translation](#translation)
- [Testing in your application](#testing-in-your-application)
  - [FakePaynowClient](#fakepaynovclient)
  - [Simulating a webhook confirmation](#simulating-a-webhook-confirmation)
- [Package development](#package-development)
- [License](#license)

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- LunarPHP 1.x

## Installation

Install the package via Composer:

```bash
composer require bpotmalnik/lunar-paynow
```

Publish the configuration file and migrations, then run the migrations:

```bash
php artisan vendor:publish --tag=lunar-paynow-config
php artisan vendor:publish --tag=lunar-paynow-migrations
php artisan migrate
```

Optionally publish the translation files if you want to customise the error messages:

```bash
php artisan vendor:publish --tag=lunar-paynow-lang
```

## Configuration

Add the following variables to your `.env` file:

```env
PAYNOW_API_KEY=your-api-key
PAYNOW_SIGNATURE_KEY=your-signature-key
PAYNOW_SANDBOX=true
```

Both credentials are available in your PayNow merchant dashboard under **Integration** → **Keys**.

#### Notification URL

PayNow sends server-to-server POST requests when a payment status changes. Configure the full URL in your merchant dashboard under **PoS settings** → **Notification URL**:

```
https://yoursite.com/paynow/notification
```

The route is registered automatically by the package. It operates outside the `web` middleware group — CSRF protection is replaced by HMAC-SHA256 signature verification on every inbound request.

You may change the path via the environment:

```env
PAYNOW_NOTIFICATION_PATH=paynow/notification
```

#### Order status mapping

By default the package maps PayNow statuses to these Lunar order statuses. Override them to match your store's configuration:

```env
PAYNOW_STATUS_CONFIRMED=payment-received
PAYNOW_STATUS_REJECTED=payment-failed
PAYNOW_STATUS_ABANDONED=payment-failed
PAYNOW_STATUS_EXPIRED=payment-failed
PAYNOW_STATUS_ERROR=payment-failed
```

Full configuration reference: [`config/lunar/paynow.php`](config/lunar/paynow.php).

## Usage

### Authorizing a payment

Call `authorize()` from your checkout controller. The method creates a draft order from the cart (or reuses one if it already exists), calls the PayNow API, and returns a redirect URL.

```php
use Lunar\Facades\Payments;

$result = Payments::driver('paynow')
    ->cart($cart)
    ->withData([
        'continue_url' => route('checkout.complete'),
    ])
    ->authorize();

if (! $result->success) {
    return back()->withErrors(['payment' => $result->message]);
}

return redirect($result->redirectUrl);
```

`$result->message` is always safe to display to the customer. For the detailed admin-level reason (e.g. "Signature key misconfigured"), use `$result->adminMessage()` in logs or the admin panel.

#### Optional withData keys

| Key | Description |
|-----|-------------|
| `continue_url` | Where PayNow redirects the customer after the payment page. Overrides the PoS default. |
| `description` | Payment description shown on the PayNow page. Falls back to `PAYNOW_PAYMENT_DESCRIPTION`. |
| `refund_reason` | One of `RMA`, `REFUND_BEFORE_14`, `REFUND_AFTER_14`, `OTHER`. Used when calling `refund()`. |

### Handling the redirect

After `authorize()` succeeds, redirect the customer to `$result->redirectUrl`. PayNow handles the payment and redirects them back to your `continue_url`. At this point the payment may not yet be confirmed — confirmation arrives asynchronously via the [notification endpoint](#notifications).

A typical `continue_url` handler simply polls the order status:

```php
public function complete(Order $order)
{
    if ($order->placed_at) {
        return view('checkout.success', ['order' => $order]);
    }

    // Payment still pending — show a waiting page or poll via JS.
    return view('checkout.pending', ['order' => $order]);
}
```

### Payment recovery

PayNow allows customers to retry a payment that failed with a `PENDING`, `REJECTED`, or `ERROR` status. The recovered payment shares the same `externalId` as the original so PayNow can link the attempts on its side.

```php
use Bpotmalnik\LunarPaynow\Models\PaynowPayment;

$failedPayment = PaynowPayment::findOrFail($id);

$result = Payments::driver('paynow')
    ->recoverFrom($failedPayment)
    ->withData(['continue_url' => route('checkout.complete')])
    ->authorize();

if (! $result->success) {
    return back()->withErrors(['payment' => $result->message]);
}

return redirect($result->redirectUrl);
```

Recovery must be enabled in the PayNow merchant panel. Calling `recoverFrom()` on a payment that is not in a recoverable status returns a failure response immediately without hitting the API.

### Refunding

Refunds are initiated from the Lunar admin panel via the standard capture transaction interface. To trigger one programmatically, pass the capture `Transaction` and an amount in grosze (smallest currency unit):

```php
use Lunar\Facades\Payments;

$result = Payments::driver('paynow')
    ->refund($captureTransaction, 5000); // 50.00 PLN

if (! $result->success) {
    // $result->message contains the admin-translated reason.
}
```

To include a refund reason recognised by PayNow:

```php
$result = Payments::driver('paynow')
    ->withData(['refund_reason' => 'RMA'])
    ->refund($captureTransaction, 5000);
```

Valid reasons: `RMA`, `REFUND_BEFORE_14`, `REFUND_AFTER_14`, `OTHER`.

The package validates that:

- The source payment is `CONFIRMED`.
- The requested amount does not exceed the unrefunded balance (taking partial refunds already made into account).
- The payment is not older than six months (`REFUND_POSSIBILITY_EXPIRED`).

All amounts are integers in the smallest currency unit. `10000` = 100.00 PLN.

### Cancelling a refund

PayNow supports cancelling a refund that is still in `NEW` status (the *awaiting refunds* feature, used when your merchant balance is temporarily insufficient).

```php
use Bpotmalnik\LunarPaynow\Models\PaynowRefund;
use Lunar\Facades\Payments;

$refund = PaynowRefund::where('refund_id', $refundId)->firstOrFail();

$result = Payments::driver('paynow')->cancelRefund($refund);

if (! $result->success) {
    // $result->message explains why (e.g. status is no longer NEW).
}
```

Cancellation is only possible while the refund status is `NEW`. The package rejects the request locally and does not call the API if the status has already advanced.

## Notifications

PayNow sends a POST request to your notification URL whenever a payment status changes to a terminal state (`CONFIRMED`, `REJECTED`, `ABANDONED`, `EXPIRED`, or `ERROR`).

The package handles this automatically:

- Verifies the `Signature` header using HMAC-SHA256 before processing anything.
- Uses a database transaction with `lockForUpdate` to prevent duplicate processing if PayNow sends the same notification twice.
- On `CONFIRMED`: marks the intent transaction as successful, creates a `capture` transaction, sets `placed_at` on the order, updates the order status, and fires `PaymentConfirmed`.
- On failure statuses: marks the intent transaction as failed, updates the order status, and fires `PaymentFailed`.

Non-terminal statuses (`NEW`, `PENDING`) are acknowledged with `200` and ignored — the notification is only fully processed once a final state is reached.

## Events

Listen for these events to react to payment outcomes in your application:

```php
use Bpotmalnik\LunarPaynow\Events\PaymentConfirmed;
use Bpotmalnik\LunarPaynow\Events\PaymentFailed;

// In a service provider or EventServiceProvider:
Event::listen(PaymentConfirmed::class, function (PaymentConfirmed $event) {
    $event->order;        // Lunar\Models\Order
    $event->paynowPayment; // Bpotmalnik\LunarPaynow\Models\PaynowPayment
});

Event::listen(PaymentFailed::class, function (PaymentFailed $event) {
    $event->order;
    $event->paynowPayment;
});
```

## Error messages

`$result->message` from `authorize()` is always **customer-safe** — it maps to a localised string the customer can act on, or falls back to a generic message for errors that should not be surfaced (configuration problems, merchant balance issues, etc.).

For the detailed admin-level reason, use `$result->adminMessage()` or access `$result->errorType` directly:

```php
$result = Payments::driver('paynow')->cart($cart)->authorize();

if (! $result->success) {
    // Show to customer:
    return back()->withErrors(['payment' => $result->message]);

    // Log for the developer/admin:
    Log::error($result->adminMessage(), [
        'error_type' => $result->errorType?->value,
        'order'      => $result->orderId,
    ]);
}
```

The `refund()` and `cancelRefund()` methods are admin operations — their `$result->message` is the admin-translated string and is displayed directly in the Lunar admin panel.

#### Customer-safe errors

These are shown to the customer verbatim; all others fall back to a generic message:

| PayNow error | Example |
|---|---|
| `SYSTEM_TEMPORARILY_UNAVAILABLE` | "Payment service is temporarily unavailable." |
| `PAYMENT_AMOUNT_TOO_SMALL` | "The payment amount is too small." |
| `PAYMENT_AMOUNT_TOO_LARGE` | "The payment amount is too large." |
| `PAYMENT_METHOD_NOT_AVAILABLE` | "The selected payment method is not available." |
| `AUTHORIZATION_CODE_EXPIRED` | "Your BLIK code has expired." |
| `AUTHORIZATION_CODE_INVALID` | "Invalid BLIK code." |
| `AUTHORIZATION_CODE_USED` | "This BLIK code has already been used." |

Errors such as `VERIFICATION_FAILED`, `INSUFFICIENT_BALANCE_FUNDS`, and `REFUND_POSSIBILITY_EXPIRED` are admin-only — the customer receives the generic fallback.

## Translation

The package ships with English and Polish translations. Polish is the default language for the PayNow market, but all strings follow Laravel's standard translation resolution — the active application locale is used automatically.

The language files are split into two sections:

- `errors.admin.*` — detailed messages for the Lunar admin panel and application logs.
- `errors.customer.*` — short, user-friendly messages safe for browser display.

To customise any string, publish the translations and edit the files under `lang/vendor/lunar-paynow/`:

```bash
php artisan vendor:publish --tag=lunar-paynow-lang
```

## Testing in your application

The package ships with a `FakePaynowClient` that lets you write feature tests for the full checkout flow without making real HTTP calls to PayNow or needing valid API credentials.

### FakePaynowClient

`Bpotmalnik\LunarPaynow\Testing\FakePaynowClient` implements `PaynowClientContract` and can be swapped into the container before each test:

```php
use Bpotmalnik\LunarPaynow\Contracts\PaynowClientContract;
use Bpotmalnik\LunarPaynow\Testing\FakePaynowClient;

beforeEach(function () {
    $fake = new FakePaynowClient;
    $this->app->instance(PaynowClientContract::class, $fake);
    $this->paynow = $fake;
});
```

The fake behaves as follows:

| Method | Behaviour |
|---|---|
| `createPayment()` | Returns `['paymentId' => $this->paymentId, 'status' => 'NEW', 'redirectUrl' => $this->redirectUrl]` |
| `verifyNotificationSignature()` | Always returns `true` — any signature header is accepted |
| `getPaymentStatus()` | Returns `['paymentId' => $this->paymentId, 'status' => 'CONFIRMED']` |
| `createRefund()` | Returns a minimal successful refund payload |
| `cancelRefund()` / `getRefundStatus()` | No-ops / return stubs |

Two public properties let you reference the deterministic values in assertions:

```php
$fake->paymentId;    // 'fake-paynow-id-00001'
$fake->redirectUrl;  // 'https://paynow.pl/fake-redirect'
```

You can override them before the test to simulate different scenarios:

```php
$fake = new FakePaynowClient;
$fake->paymentId = 'custom-id-for-this-test';
```

### Simulating a webhook confirmation

Because `verifyNotificationSignature()` always returns `true`, you can POST a fake webhook notification directly in your test without a valid HMAC signature:

```php
$body = json_encode(['paymentId' => $this->paynow->paymentId, 'status' => 'CONFIRMED']);

$this->call(
    'POST',
    route('paynow.notification'),
    [],
    [],
    [],
    ['HTTP_SIGNATURE' => 'fake-sig', 'CONTENT_TYPE' => 'application/json'],
    $body,
);
```

This triggers the full notification handling path: the `PaynowPayment` record is updated, a `capture` transaction is created, `placed_at` is set on the order, the order status transitions to `payment-received`, and the `PaymentConfirmed` event is fired — exactly as in production.

## Package development

```bash
composer test
```

`composer test` runs the full quality suite in order: Pint lint check, PHPStan, unit tests, feature tests.

Individual commands:

```bash
composer lint          # fix code style with Pint

composer test:lint     # check style without fixing
composer test:types    # PHPStan static analysis
composer test:unit
composer test:feature
```

## License

lunar-paynow is open-sourced software licensed under the [MIT license](LICENSE.md).
