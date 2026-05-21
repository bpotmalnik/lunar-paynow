<?php

namespace Bpotmalnik\LunarPaynow\Models;

use Bpotmalnik\LunarPaynow\Enums\PaymentStatus;
use Bpotmalnik\LunarPaynow\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\Order;
use Lunar\Models\Transaction;

/**
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $paynow_payment_id
 * @property string|null $redirect_url
 * @property string|null $external_id
 * @property int|null $parent_payment_id
 * @property int $order_id
 * @property int|null $transaction_id
 * @property string $currency
 */
class PaynowPayment extends Model
{
    protected $table = 'paynow_payments';

    protected $fillable = [
        'order_id',
        'transaction_id',
        'paynow_payment_id',
        'external_id',
        'status',
        'amount',
        'currency',
        'redirect_url',
        'parent_payment_id',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
    ];

    public function isRecoverable(): bool
    {
        return in_array($this->status, [
            PaymentStatus::Pending,
            PaymentStatus::Rejected,
            PaymentStatus::Error,
        ]);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<PaynowPayment, $this> */
    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    /** @return HasMany<PaynowPayment, $this> */
    public function recoveryAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    /** @return HasMany<PaynowRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaynowRefund::class);
    }

    public function amountRefunded(): int
    {
        return $this->refunds()
            ->whereNotIn('status', [RefundStatus::Failed->value, RefundStatus::Cancelled->value])
            ->sum('amount');
    }

    public function refundableAmount(): int
    {
        return $this->amount - $this->amountRefunded();
    }
}
