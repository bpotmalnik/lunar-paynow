<?php

use Bpotmalnik\LunarPaynow\Enums\ApiErrorType;
use Bpotmalnik\LunarPaynow\Enums\RefundReason;
use Bpotmalnik\LunarPaynow\Exceptions\PaynowApiException;
use Bpotmalnik\LunarPaynow\PaynowClient;
use Bpotmalnik\LunarPaynow\Tests\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

it('sends a signed POST and returns the payment response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'paymentId' => 'PBLA-111-222-333',
            'redirectUrl' => 'https://api.sandbox.paynow.pl/PBLA-111-222-333?token=abc',
            'status' => 'NEW',
        ], 201),
    ]);

    $result = app(PaynowClient::class)->createPayment([
        'amount' => 10000,
        'externalId' => 'ext-001',
        'description' => 'Test order',
        'buyer' => ['email' => 'buyer@example.com'],
    ]);

    expect($result['paymentId'])->toBe('PBLA-111-222-333')
        ->and($result['status'])->toBe('NEW');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/payments')
        && $req->method() === 'POST'
        && $req->hasHeader('Api-Key')
        && $req->hasHeader('Signature')
        && $req->hasHeader('Idempotency-Key')
    );
});

it('throws on a 400 payment creation response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response(
            ['errors' => [['field' => 'amount', 'message' => 'Too low']]],
            400
        ),
    ]);

    app(PaynowClient::class)->createPayment([
        'amount' => 0, 'externalId' => 'x', 'description' => 'x', 'buyer' => ['email' => 'x@x.com'],
    ]);
})->throws(PaynowApiException::class);

it('throws on a 401 payment creation response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    app(PaynowClient::class)->createPayment([
        'amount' => 1000, 'externalId' => 'x', 'description' => 'x', 'buyer' => ['email' => 'x@x.com'],
    ]);
})->throws(PaynowApiException::class);

it('throws on a 500 payment creation response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response(null, 500),
    ]);

    app(PaynowClient::class)->createPayment([
        'amount' => 1000, 'externalId' => 'x', 'description' => 'x', 'buyer' => ['email' => 'x@x.com'],
    ]);
})->throws(PaynowApiException::class);

it('sends a signed GET and returns the payment status', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/PBLA-111-222-333/status' => Http::response([
            'paymentId' => 'PBLA-111-222-333',
            'status' => 'CONFIRMED',
        ], 200),
    ]);

    $result = app(PaynowClient::class)->getPaymentStatus('PBLA-111-222-333');

    expect($result['status'])->toBe('CONFIRMED');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/payments/PBLA-111-222-333/status')
        && $req->method() === 'GET'
        && $req->hasHeader('Api-Key')
        && $req->hasHeader('Signature')
    );
});

it('throws on a 404 payment status response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/UNKNOWN/status' => Http::response(null, 404),
    ]);

    app(PaynowClient::class)->getPaymentStatus('UNKNOWN');
})->throws(PaynowApiException::class);

it('sends a refund with the correct amount', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/PBLA-111-222-333/refunds' => Http::response([
            'refundId' => 'REFX-111-222-333',
            'status' => 'NEW',
        ], 201),
    ]);

    $result = app(PaynowClient::class)->createRefund('PBLA-111-222-333', 5000);

    expect($result['refundId'])->toBe('REFX-111-222-333')
        ->and($result['status'])->toBe('NEW');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/payments/PBLA-111-222-333/refunds')
        && $req->method() === 'POST'
        && data_get($req->data(), 'amount') === 5000
    );
});

it('includes the reason in a refund request', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'refundId' => 'REFX-999-999-999',
            'status' => 'NEW',
        ], 201),
    ]);

    app(PaynowClient::class)->createRefund(
        'PBLA-111-222-333',
        1000,
        RefundReason::RefundBefore14
    );

    Http::assertSent(fn ($req) => data_get($req->data(), 'reason') === 'REFUND_BEFORE_14');
});

it('throws on a failed refund response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response(null, 422),
    ]);

    app(PaynowClient::class)->createRefund('PBLA-111-222-333', 1000);
})->throws(PaynowApiException::class);

it('accepts a valid notification signature', function () {
    $key = config('lunar.paynow.signature_key');
    $body = '{"paymentId":"PBLA-111-222-333","status":"CONFIRMED"}';
    $sig = base64_encode(hash_hmac('sha256', $body, $key, true));

    expect(app(PaynowClient::class)->verifyNotificationSignature($body, $sig))->toBeTrue();
});

it('rejects an invalid notification signature', function () {
    expect(app(PaynowClient::class)->verifyNotificationSignature('body', 'not-valid'))->toBeFalse();
});

it('rejects a signature for a tampered body', function () {
    $key = config('lunar.paynow.signature_key');
    $original = '{"paymentId":"PBLA-111-222-333","status":"CONFIRMED"}';
    $tampered = '{"paymentId":"PBLA-111-222-333","status":"REJECTED"}';
    $sig = base64_encode(hash_hmac('sha256', $original, $key, true));

    expect(app(PaynowClient::class)->verifyNotificationSignature($tampered, $sig))->toBeFalse();
});

it('rejects a wrong signature of the same length', function () {
    $key = config('lunar.paynow.signature_key');
    $wrongSig = base64_encode(str_repeat('x', 32));

    expect(app(PaynowClient::class)->verifyNotificationSignature('test-body', $wrongSig))->toBeFalse();
});

it('sends a refund cancellation with an empty body', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/REFX-111-222-333/cancel' => Http::response(null, 200),
    ]);

    app(PaynowClient::class)->cancelRefund('REFX-111-222-333');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/refunds/REFX-111-222-333/cancel')
        && $req->method() === 'POST'
        && $req->hasHeader('Api-Key')
        && $req->hasHeader('Signature')
        && $req->hasHeader('Idempotency-Key')
        && $req->body() === ''
    );
});

it('throws on a failed refund cancellation response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/*/cancel' => Http::response(
            ['errors' => [['errorType' => 'CONFLICT', 'message' => 'Refund not in NEW status']]],
            400
        ),
    ]);

    app(PaynowClient::class)->cancelRefund('REFX-111-222-333');
})->throws(PaynowApiException::class);

it('returns the current refund status', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/REFX-111-222-333/status' => Http::response([
            'refundId' => 'REFX-111-222-333',
            'status' => 'SUCCESSFUL',
        ], 200),
    ]);

    $result = app(PaynowClient::class)->getRefundStatus('REFX-111-222-333');

    expect($result['status'])->toBe('SUCCESSFUL');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/refunds/REFX-111-222-333/status')
        && $req->method() === 'GET'
    );
});

it('includes a failureReason in the refund status when present', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/REFX-FAILED-001/status' => Http::response([
            'refundId' => 'REFX-FAILED-001',
            'status' => 'FAILED',
            'failureReason' => 'CARD_BALANCE_ERROR',
        ], 200),
    ]);

    $result = app(PaynowClient::class)->getRefundStatus('REFX-FAILED-001');

    expect($result['status'])->toBe('FAILED')
        ->and($result['failureReason'])->toBe('CARD_BALANCE_ERROR');
});

it('throws on a 404 refund status response', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/refunds/UNKNOWN/status' => Http::response(null, 404),
    ]);

    app(PaynowClient::class)->getRefundStatus('UNKNOWN');
})->throws(PaynowApiException::class);

it('parses an errorType from the errors array envelope', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'errors' => [
                ['errorType' => 'VERIFICATION_FAILED', 'message' => 'Your signature header is incorrect'],
            ],
        ], 401),
    ]);

    try {
        app(PaynowClient::class)->createPayment([
            'amount' => 100, 'externalId' => 'x', 'description' => 'x', 'buyer' => ['email' => 'x@x.com'],
        ]);
    } catch (PaynowApiException $e) {
        expect($e->errorType)->toBe(ApiErrorType::VerificationFailed)
            ->and($e->getMessage())->toBe('Your signature header is incorrect')
            ->and($e->statusCode)->toBe(401);

        return;
    }

    fail('Expected PaynowApiException was not thrown.');
});

it('parses an errorType from the flat envelope', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/*/refunds' => Http::response([
            'errorType' => 'REFUND_POSSIBILITY_EXPIRED',
            'message' => 'Payment refund possibility expired because transaction is older than 6 months',
        ], 422),
    ]);

    try {
        app(PaynowClient::class)->createRefund('PBLA-111-222-333', 1000);
    } catch (PaynowApiException $e) {
        expect($e->errorType)->toBe(ApiErrorType::RefundPossibilityExpired)
            ->and($e->getMessage())->toContain('6 months');

        return;
    }

    fail('Expected PaynowApiException was not thrown.');
});

it('sets errorType to null for an unrecognised error string', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response([
            'errors' => [['errorType' => 'SOME_FUTURE_ERROR', 'message' => 'Something new']],
        ], 400),
    ]);

    try {
        app(PaynowClient::class)->createPayment([
            'amount' => 100, 'externalId' => 'x', 'description' => 'x', 'buyer' => ['email' => 'x@x.com'],
        ]);
    } catch (PaynowApiException $e) {
        expect($e->errorType)->toBeNull()
            ->and($e->getMessage())->toBe('Something new');

        return;
    }

    fail('Expected PaynowApiException was not thrown.');
});

it('falls back to a generic message when the response body is empty', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments' => Http::response(null, 503),
    ]);

    try {
        app(PaynowClient::class)->createPayment([
            'amount' => 100, 'externalId' => 'x', 'description' => 'x', 'buyer' => ['email' => 'x@x.com'],
        ]);
    } catch (PaynowApiException $e) {
        expect($e->getMessage())->toContain('503')
            ->and($e->errorType)->toBeNull();

        return;
    }

    fail('Expected PaynowApiException was not thrown.');
});

it('parses errors from a GET response body', function () {
    Http::fake([
        'api.sandbox.paynow.pl/v3/payments/PBLA-111-222-333/status' => Http::response([
            'errors' => [['errorType' => 'NOT_FOUND', 'message' => 'Payment not found']],
        ], 404),
    ]);

    try {
        app(PaynowClient::class)->getPaymentStatus('PBLA-111-222-333');
    } catch (PaynowApiException $e) {
        expect($e->errorType)->toBe(ApiErrorType::NotFound)
            ->and($e->getMessage())->toBe('Payment not found');

        return;
    }

    fail('Expected PaynowApiException was not thrown.');
});
