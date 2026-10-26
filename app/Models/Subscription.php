<?php

namespace App\Models;

use App\Contracts\PlanCapabilities;
use App\Enums\SubscriptionPlan;
use App\Services\Payment\GatewayOrder;
use App\Support\PrivateFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * یک خرید اشتراک. عمداً از BelongsToComplex استفاده نمی‌کند چون ادمین کل
 * باید بتواند اشتراک همه‌ی مجتمع‌ها را ببیند و complex_id هم nullable است.
 */
class Subscription extends Model implements GatewayOrder
{
    use HasFactory;

    protected $fillable = [
        'complex_id', 'user_id', 'plan', 'plan_id', 'status', 'method', 'purchase_kind', 'amount', 'months',
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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** پکیجِ دیتابیسی (اگر این اشتراک به یکی وصل باشد). */
    /** @return BelongsTo<Plan, $this> */
    public function planRef(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * منبعِ قابلیت‌ها: پکیجِ دیتابیسی مقدم است، وگرنه enumِ قدیمی.
     */
    public function resolvedPlan(): PlanCapabilities
    {
        return $this->planRef ?? $this->plan;
    }

    /** برچسبِ پلن برای نمایش، مستقل از منبعش. */
    public function planLabel(): string
    {
        return $this->resolvedPlan()->planLabel();
    }

    /** مسیر خرید: پرداخت آنلاین یا آپلود رسید. */
    public function methodLabel(): string
    {
        return $this->method === 'receipt' ? 'واریز و آپلود رسید' : 'پرداخت آنلاین';
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
        return route('subscription.callback', $this);
    }

    public function gatewayPayerPhone(): ?string
    {
        return $this->user?->phone;
    }

    public function gatewayDescription(): string
    {
        return 'اشتراک '.$this->planLabel();
    }

    public function gatewayRefId(): ?string
    {
        return $this->ref_id;
    }

    public function markGatewayRequested(string $gateway, string $refId): void
    {
        $this->update(['gateway' => $gateway, 'ref_id' => $refId]);
    }

    /** آیا فعال‌سازیِ همین لحظه از مسیرِ پرداختِ تأییدشده است؟ (`activating`) */
    private static int $activating = 0;

    /**
     * فعال‌سازیِ اشتراکِ **آنلاین** فقط از مسیرِ پرداختِ تأییدشده (مرحلهٔ ۱۲ · فاز ۲؛ تصمیمِ قطعیِ کارفرما).
     *
     * `SubscriptionRenewal::complete` (تمدید) و `ComplexPurchase::complete` (خریدِ اولیه) — هر دو پس از تأییدِ مستقلِ
     * درگاه در سرور و زیرِ قفل — کارشان را درونِ همین می‌کنند. هر مسیرِ دیگری — دکمه‌ی «تأیید»، کدِ رهگیریِ دستی،
     * تغییرِ وضعیت از پنل یا endpoint ِ فردا — به `saving` ِ پایین می‌خورد و رد می‌شود.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    public static function activating(\Closure $work): mixed
    {
        self::$activating++;

        try {
            return $work();
        } finally {
            self::$activating--;
        }
    }

    protected static function booted(): void
    {
        /*
         * 🔴 نگهبانِ مدل: ادمینِ کل (یا هر کدِ دیگری) اشتراکِ آنلاینِ پرداخت‌نشده را فعال نمی‌کند.
         *
         * - اشتراکِ `online` فقط درونِ `activating` فعال ساخته یا فعال می‌شود؛
         * - ردیفِ موجود با تغییرِ وضعیت فعال نمی‌شود — جز «اهدای پلن» (`manual`، ابزارِ جدای سکو)؛
         * - روشِ پرداختِ ردیف پس از ساخت عوض نمی‌شود (دورزدنِ دو قاعده‌ی بالا با `manual` کردن).
         *
         * به‌روزرسانیِ مستقیمِ query builder از این‌جا رد نمی‌شود؛ `SubscriptionActivationTest` می‌سنجد که هیچ کدی
         * با آن اشتراکی را فعال نمی‌کند.
         */
        static::saving(function (Subscription $subscription): void {
            if ($subscription->exists && $subscription->isDirty('method')) {
                throw new LogicException('روشِ پرداختِ اشتراک پس از ساخت عوض نمی‌شود.');
            }

            $becomesActive = $subscription->status === 'active' && (! $subscription->exists || $subscription->isDirty('status'));
            if (! $becomesActive || self::$activating > 0) {
                return;
            }

            if ($subscription->method === 'online' || ($subscription->exists && $subscription->method !== 'manual')) {
                throw new LogicException('اشتراک فقط با پرداختِ آنلاینِ تأییدشده فعال می‌شود.');
            }
        });

        /** با حذف اشتراک، فایل رسیدش هم از دیسک پاک می‌شود. */
        static::deleting(function (Subscription $subscription) {
            if ($subscription->receipt_path) {
                PrivateFiles::disk()->delete($subscription->receipt_path);
            }
        });
    }

    /** @return BelongsTo<Complex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /** @return BelongsTo<User, $this> */
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
            // پلنِ دیگری با پرداختِ موفق جایش را گرفت (۱۳/۶، بندِ ۲۲)
            'replaced' => 'خاتمه با تغییرِ پلن',
            default => 'منقضی‌شده',
        };
    }
}
