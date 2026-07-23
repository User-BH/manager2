<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToComplex;
use App\Services\Payment\GatewayOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Payment extends Model implements GatewayOrder
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'unit_id', 'bill_id', 'user_id', 'amount', 'method',
        'status', 'period', 'gateway', 'ref_id', 'tracking_code', 'paid_at',
        'receipt_path', 'receipt_original_name', 'receipt_paid_on',
        'reviewed_by', 'reviewed_at', 'review_note', 'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'receipt_paid_on' => 'date',
        ];
    }

    /**
     * با حذف پرداخت، فایل رسیدش هم از دیسک پاک می‌شود.
     *
     * توجه: حذف‌های آبشاریِ خودِ دیتابیس (مثلاً حذف واحد) این رویداد را
     * اجرا نمی‌کنند؛ آن فایل‌های یتیم را دستور `receipts:prune` جمع می‌کند.
     */
    protected static function booted(): void
    {
        static::deleting(function (Payment $payment) {
            if ($payment->receipt_path) {
                Storage::disk('local')->delete($payment->receipt_path);
            }
        });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ---------------- GatewayOrder ---------------- */

    public function gatewayOrderId(): int
    {
        return $this->id;
    }

    public function gatewayAmount(): float
    {
        return (float) $this->amount;
    }

    public function gatewayCallbackUrl(): string
    {
        return route('payments.callback', $this);
    }

    public function gatewayPayerPhone(): ?string
    {
        return $this->user?->phone;
    }

    public function gatewayDescription(): string
    {
        return 'شارژ ساختمان';
    }

    public function gatewayRefId(): ?string
    {
        return $this->ref_id;
    }

    public function markGatewayRequested(string $gateway, string $refId): void
    {
        $this->update(['gateway' => $gateway, 'ref_id' => $refId]);
    }
}
