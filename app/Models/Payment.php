<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Concerns\BelongsToComplex;
use App\Models\Concerns\NeverDeleted;
use App\Services\Payment\GatewayOrder;
use App\Support\PrivateFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $complex_id
 * @property int|null $user_id
 * @property PaymentStatus $status
 * @property string|null $receipt_path
 */
class Payment extends Model implements GatewayOrder
{
    /*
     * ⚠️ `NeverDeleted`: این رکورد ویرایش می‌شود ولی هرگز حذف نه (R51).
     *
     * هیچ عملیاتِ کاربریِ مشروعی به‌نامِ «حذفِ قبض» یا «حذفِ پرداخت»
     * وجود ندارد — اصلاح با تغییرِ مبلغ یا وضعیت انجام می‌شود. تا
     * پیش از این هیچ سدی نبود و یک `->delete()` در کدِ آینده
     * می‌توانست تاریخچه‌ی مالی را ببرد.
     */
    use BelongsToComplex, NeverDeleted;
    use HasFactory;

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
                PrivateFiles::disk()->delete($payment->receipt_path);
            }
        });

        /*
         * 🔴 وضعیت فقط از مسیرهای مجاز عبور می‌کند (فاز ۱).
         *
         * ─── چه چیزی باز بود ───────────────────────────────────────────────
         * `PaymentService::settle()` پیش از تسویه فقط یک حالت را رد می‌کرد:
         *
         *     if ($fresh->status === PaymentStatus::Success) return false;
         *
         * ⚠️ یعنی پرداختِ **ناموفق** یا **ردشده** با فراخوانیِ دوباره موفق
         * می‌شد و پولی که هرگز نیامده بود روی بدهی می‌نشست. و `reject()`
         * هیچ شرطی نداشت: پرداختِ تسویه‌شده را می‌شد رد کرد، بی‌آنکه پولش
         * برگردد.
         *
         * ─── چرا اینجا و نه در سرویس ───────────────────────────────────────
         * شرطِ داخلِ سرویس فقط همان یک مسیر را می‌بندد. کنترلر، کارِ صف،
         * دستورِ کنسول و `tinker` هرکدام راهِ خودشان را دارند. قاعده جایی
         * می‌نشیند که **همه‌ی مسیرها از آن می‌گذرند**.
         *
         * ⚠️ مرزِ صریح: به‌روزرسانیِ انبوه رویدادِ مدل شلیک نمی‌کند. تنها
         * مسیرِ انبوهِ زنده (`GatewayController`) محافظش را در خودِ SQL دارد
         * (`->where('status', Pending)`) و تستِ جداگانه‌ای همان را می‌سنجد.
         */
        static::updating(function (Payment $payment) {
            if (! $payment->isDirty('status')) {
                return;
            }

            $from = $payment->getOriginal('status');
            $from = $from instanceof PaymentStatus ? $from : PaymentStatus::from((string) $from);
            $to = $payment->status;

            if ($from === $to || $from->canTransitionTo($to)) {
                return;
            }

            throw DomainException::invalid(
                "پرداختِ «{$from->label()}» نمی‌تواند به «{$to->label()}» برود.",
                'payment.invalid_transition',
            );
        });
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsTo<User, $this> */
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
