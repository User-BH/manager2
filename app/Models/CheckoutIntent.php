<?php

namespace App\Models;

use App\Enums\ComplexType;
use App\Services\Payment\GatewayOrder;
use DomainException as LogicDomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * قصدِ خریدِ اشتراک پیش از وجودِ مجتمع (مرحلهٔ ۹ · فاز ۴).
 *
 * ─── ماشینِ وضعیت ───────────────────────────────────────────────────────
 *
 *   pending ──► completed   پرداخت تأیید شد و مجتمع + اشتراک + مدیر ساخته شدند
 *      │──────► paid        پرداخت تأیید شد ولی ساختن ممکن نشد (پول گرفته شده؛
 *      │                    بررسیِ دستی — هرگز بی‌صدا گم نمی‌شود)
 *      │──────► failed      درگاه گفت «نه» (لغو، شکست، مرجعِ بیگانه)
 *      └──────► cancelled   قصدِ تازه‌تری از همان کاربر جایش را گرفت
 *
 * هر گذارِ دیگری استثنا است (`transitionTo`)؛ کنترلر نمی‌تواند وضعیت را
 * دلخواه عوض کند. «نامعلوم» (نرسیدن به بانک، مبلغِ ناهمخوان) گذار نیست:
 * ردیف `pending` می‌ماند و audit می‌شود، چون نمی‌دانیم پول رفته یا نه (R59).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property int|null $plan_id
 * @property string $plan_name
 * @property int $amount
 * @property int $months
 * @property string $complex_name
 * @property ComplexType $complex_type
 * @property string|null $complex_address
 * @property string $status
 * @property string|null $gateway
 * @property string|null $authority
 * @property string|null $tracking_code
 * @property string|null $failure_reason
 * @property int|null $complex_id
 * @property int|null $subscription_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class CheckoutIntent extends Model implements GatewayOrder
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** پولِ قصدِ `paid` با مرجعِ بانکی برگشت (مرحلهٔ ۱۲ · فاز ۲) — پایانی */
    public const REFUNDED = 'refunded';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PENDING => [self::COMPLETED, self::PAID, self::FAILED, self::CANCELLED],
        // پول گرفته شد و مجتمع نشد: یا بعداً ساخته می‌شود (حساب دوباره واجدِ شرایط) یا پول برمی‌گردد (۱۲/۲)
        self::PAID => [self::COMPLETED, self::REFUNDED],
    ];

    /*
     * ⚠️ `status`، `tracking_code`، `complex_id` و `subscription_id` عمداً
     * fillable نیستند: فقط `transitionTo` و سرویسِ خرید می‌نویسندشان.
     */
    protected $fillable = [
        'user_id', 'plan_id', 'plan_name', 'amount', 'months',
        'complex_name', 'complex_type', 'complex_address',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'months' => 'integer',
            'complex_type' => ComplexType::class,
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CheckoutIntent $intent): void {
            // تصادفی و غیرقابلِ حدس — نه شناسه‌ی پشتِ‌سرهم در نشانیِ بازگشت
            $intent->public_id ??= (string) Str::ulid();
            $intent->status ??= self::PENDING;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Complex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** برای «تاریخچه‌ی پرداخت‌ها» ِ حسابِ اولیه (۱۳/۶، بندِ ۲۷) */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'در انتظار پرداخت',
            self::COMPLETED => 'موفق',
            // پول گرفته شد ولی مجتمع ساخته نشد — ادمینِ کل تعیینِ تکلیف می‌کند
            self::PAID => 'پرداخت‌شده، در انتظار بررسی',
            self::FAILED => 'ناموفق',
            self::CANCELLED => 'لغو شده',
            self::REFUNDED => 'بازپرداخت شد',
            default => $this->status,
        };
    }

    /**
     * تنها راهِ عوض‌کردنِ وضعیت.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(string $status, array $attributes = []): void
    {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw new LogicDomainException("گذارِ نامعتبرِ قصدِ خرید: {$this->status} → {$status}");
        }

        $this->forceFill([...$attributes, 'status' => $status])->save();
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
        return route('checkout.callback', $this);
    }

    public function gatewayPayerPhone(): ?string
    {
        return $this->user?->phone;
    }

    public function gatewayDescription(): string
    {
        return 'اشتراکِ ساکنا: '.$this->plan_name.' — '.$this->complex_name;
    }

    public function gatewayRefId(): ?string
    {
        return $this->authority;
    }

    public function markGatewayRequested(string $gateway, string $refId): void
    {
        $this->forceFill(['gateway' => $gateway, 'authority' => $refId])->save();
    }
}
