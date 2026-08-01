<?php

namespace App\Observers;

use App\Models\Advertisement;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Building;
use App\Models\ChargeRule;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Plan;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * ثبتِ خودکارِ حذفِ رکوردهای حساس در لاگ فعالیت.
 *
 * ─── مسئله‌ای که این حل می‌کند ─────────────────────────────────────────────
 * `Audit::log()` دستی صدا زده می‌شد و در عمل **۹ کنترلر یادشان می‌رفت**؛
 * یعنی حذفِ اطلاعیه، قانونِ شارژ، تخفیف، تبلیغ، پکیج و عضو هیچ ردی به جا
 * نمی‌گذاشت. برای جدولی که هدفش «تا نشود ردِ کارها را شست» است، این خودش
 * بزرگ‌ترین سوراخ بود.
 *
 * حالا حذف از خودِ چرخه‌ی عمرِ مدل ثبت می‌شود، پس فراموش‌شدنی نیست: هر مسیرِ
 * تازه‌ای هم که در آینده رکوردی را پاک کند، خودبه‌خود لاگ می‌گیرد.
 *
 * ─── چرا فقط حذف؟ ─────────────────────────────────────────────────────────
 * حذف برگشت‌ناپذیر است و بیشترین ارزشِ رهگیری را دارد. ثبتِ خودکارِ هر
 * `updated` جدول را با تغییراتِ بی‌اهمیت (مثلاً `last_seen_at`) پر می‌کرد و
 * لاگ را غیرقابلِ‌مرور می‌ساخت؛ به‌روزرسانی‌های مهم همچنان دستی و با توضیحِ
 * دقیقِ خودشان ثبت می‌شوند.
 */
class AuditObserver
{
    /**
     * نامِ فارسیِ هر مدل، برای پیامِ خواناییِ لاگ.
     *
     * مدلی که اینجا نیست هم لاگ می‌شود (با نامِ کلاس)، ولی افزودنش به این
     * فهرست پیام را برای ادمین قابل‌فهم می‌کند.
     *
     * @var array<class-string, array{0: string, 1: string}>
     */
    private const LABELS = [
        Unit::class => ['unit', 'واحد'],
        User::class => ['user', 'کاربر'],
        Announcement::class => ['announcement', 'اطلاعیه'],
        ChargeRule::class => ['charge_rule', 'قانون شارژ'],
        Discount::class => ['discount', 'تخفیف'],
        Advertisement::class => ['advertisement', 'تبلیغ'],
        Plan::class => ['plan', 'پکیج اشتراک'],
        Expense::class => ['expense', 'هزینه'],
        Income::class => ['income', 'درآمد'],
        Building::class => ['building', 'ساختمان'],
        Bill::class => ['bill', 'قبض'],
    ];

    public function deleted(Model $model): void
    {
        [$slug, $label] = self::LABELS[$model::class] ?? [
            strtolower(class_basename($model)),
            class_basename($model),
        ];

        Audit::log(
            "{$slug}.deleted",
            "حذف {$label}",
            $model,
            /*
             * شناسه‌ی خودِ رکورد پس از حذف دیگر قابلِ بازیابی نیست، پس چند
             * فیلدِ شناساننده همین‌جا ثبت می‌شود — وگرنه لاگ می‌گوید «چیزی حذف
             * شد» بی‌آنکه بشود فهمید کدام.
             */
            $this->identity($model),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Model $model): array
    {
        /*
         * `getAttributes()` و نه `getAttribute()`.
         *
         * دومی اگر ستونی نباشد سراغِ متدِ هم‌نام می‌رود و آن را رابطه فرض
         * می‌کند؛ `Unit::label()` یک متدِ معمولی است و همین باعثِ
         * «must return a relationship instance» و ۵۰۰ شدنِ حذفِ واحد شد.
         *
         * مقدارِ خام هم برای لاگ درست‌تر است: چیزی که واقعاً در دیتابیس بود
         * ثبت می‌شود، نه خروجیِ محاسبه‌شده‌ی یک accessor.
         */
        $attributes = $model->getAttributes();

        $identity = [];
        $identifyingColumns = [
            'title', 'name', 'label', 'unit_number', 'phone', 'period', 'slug', 'amount',
        ];

        foreach ($identifyingColumns as $key) {
            $value = $attributes[$key] ?? null;
            if ($value !== null && ! is_array($value)) {
                $identity[$key] = $value;
            }
        }

        return $identity;
    }
}
