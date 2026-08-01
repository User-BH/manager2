<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک ردیفِ دفتر: «از این پرداخت، این مقدار روی این قبض نشست».
 *
 * مجموعِ تخصیص‌های هر قبض باید همیشه با `bills.paid_amount` برابر باشد؛
 * `FinancialIntegrityTest` همین را می‌سنجد.
 *
 * @property int $id
 * @property int $complex_id
 * @property int|null $payment_id
 * @property int|null $bill_id
 * @property string $amount
 */
class PaymentAllocation extends Model
{
    protected $fillable = ['complex_id', 'payment_id', 'bill_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
