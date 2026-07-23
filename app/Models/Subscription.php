<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک خرید اشتراک. عمداً از BelongsToComplex استفاده نمی‌کند چون ادمین کل
 * باید بتواند اشتراک همه‌ی مجتمع‌ها را ببیند و complex_id هم nullable است.
 */
class Subscription extends Model
{
    protected $fillable = [
        'complex_id', 'user_id', 'plan', 'status', 'method', 'amount', 'months',
        'starts_at', 'ends_at', 'gateway', 'ref_id', 'tracking_code', 'paid_at',
        'receipt_path', 'receipt_original_name', 'receipt_paid_on',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'plan' => SubscriptionPlan::class,
            'amount' => 'decimal:2',
            'months' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'receipt_paid_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** مسیر خرید: پرداخت آنلاین یا آپلود رسید. */
    public function methodLabel(): string
    {
        return $this->method === 'receipt' ? 'واریز و آپلود رسید' : 'پرداخت آنلاین';
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** فعال یعنی پرداخت‌شده و هنوز منقضی نشده. */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => $this->isActive() ? 'فعال' : 'منقضی‌شده',
            // رسیدِ ثبت‌شده منتظر بررسی است، نه منتظر پرداخت؛ پرداخت انجام
            // شده و فقط تاییدش مانده.
            'pending' => $this->method === 'receipt' ? 'در انتظار بررسی' : 'در انتظار پرداخت',
            'failed' => $this->method === 'receipt' ? 'رد شده' : 'ناموفق',
            'canceled' => 'لغو شده',
            default => 'منقضی‌شده',
        };
    }
}
