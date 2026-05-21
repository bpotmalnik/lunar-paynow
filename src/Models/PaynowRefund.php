<?php

namespace Bpotmalnik\LunarPaynow\Models;

use Bpotmalnik\LunarPaynow\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Transaction;

/**
 * @property RefundStatus $status
 * @property int $amount
 * @property string $refund_id
 * @property string|null $failure_reason
 * @property int $paynow_payment_id
 * @property int|null $lunar_transaction_id
 */
class PaynowRefund extends Model
{
    protected $table = 'paynow_refunds';

    protected $fillable = [
        'paynow_payment_id',
        'lunar_transaction_id',
        'refund_id',
        'status',
        'amount',
        'failure_reason',
    ];

    protected $casts = [
        'status' => RefundStatus::class,
        'amount' => 'integer',
    ];

    public function isCancellable(): bool
    {
        return $this->status === RefundStatus::New;
    }

    /** @return BelongsTo<PaynowPayment, $this> */
    public function paynowPayment(): BelongsTo
    {
        return $this->belongsTo(PaynowPayment::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function lunarTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'lunar_transaction_id');
    }
}
