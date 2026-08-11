<?php

namespace App\Services\System;

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ErrorEvent;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;

/**
 * آمارِ کلِ پلتفرم برای ادمینِ کل (R29).
 *
 * ─── چرا سرویسِ جدا ────────────────────────────────────────────────────────
 * تا امروز داشبوردِ سیستمی سه عدد داشت (تعدادِ مجتمع، واحد، کاربر) که همان‌جا
 * در کنترلر حساب می‌شدند. با افزودنِ درآمد، پرداخت و خطا، آن متد به یک
 * کنترلرِ پُر از کوئری تبدیل می‌شد که نه تست‌پذیر بود و نه از جای دیگری قابل
 * استفاده.
 *
 * ─── همه‌ی کوئری‌ها `withoutGlobalScopes` ───────────────────────────────────
 * این اعداد **کلِ پلتفرم** را می‌شمارند. اگر ادمینِ کل مجتمعی را انتخاب
 * کرده باشد، `ComplexScope` بی‌سروصدا همه‌چیز را به همان مجتمع محدود
 * می‌کرد و آمارِ پلتفرم عددِ یک مجتمع را نشان می‌داد — بی‌آنکه خطایی رخ
 * بدهد.
 */
class PlatformStats
{
    /** طولِ نمودارِ رشد (ماه). */
    private const TREND_MONTHS = 6;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'complexes' => $this->complexes(),
            'people' => $this->people(),
            'money' => $this->money(),
            'health' => $this->health(),
            'trend' => $this->trend(),
        ];
    }

    /** @return array<string, int> */
    private function complexes(): array
    {
        $complexes = Complex::withoutGlobalScopes()->get(['id', 'is_active']);

        return [
            'total' => $complexes->count(),
            'active' => $complexes->where('is_active', true)->count(),
            'suspended' => $complexes->where('is_active', false)->count(),
            'units' => Unit::withoutGlobalScopes()->count(),
        ];
    }

    /**
     * ─── «کاربرِ فعال» یعنی چه ──────────────────────────────────────────────
     * دو معنای متفاوت دارد و هر دو لازم‌اند: `active` یعنی حسابش مسدود
     * نیست، و `engaged` یعنی در ۳۰ روزِ گذشته واقعاً کاری کرده. عددِ اول
     * می‌گوید چند حساب داریم و دومی می‌گوید چند نفر از سامانه استفاده
     * می‌کنند — و مدیرِ محصول به دومی نیاز دارد.
     *
     * @return array<string, int>
     */
    private function people(): array
    {
        $since = now()->subDays(30);

        return [
            'total' => User::withoutGlobalScopes()->count(),
            'active' => User::withoutGlobalScopes()->where('is_active', true)->count(),
            /*
             * معیارِ «درگیر بودن» از خودِ فعالیت خوانده می‌شود و نه از یک
             * ستونِ `last_seen_at`: چنین ستونی باید در هر درخواست نوشته
             * می‌شد و برای عددی که ماهی یک بار نگاه می‌شود، هزینه‌ی
             * سنگینی است.
             */
            'engaged' => User::withoutGlobalScopes()
                ->where(fn ($q) => $q
                    ->whereHas('payments', fn ($p) => $p->where('created_at', '>=', $since))
                    ->orWhereHas('serviceRequests', fn ($r) => $r->where('created_at', '>=', $since)))
                ->count(),
            'openRequests' => ServiceRequest::withoutGlobalScopes()->open()->count(),
        ];
    }

    /** @return array<string, float|int|string> */
    private function money(): array
    {
        $period = Jalali::currentPeriod();

        $paid = Payment::withoutGlobalScopes()->where('status', PaymentStatus::Success->value);

        return [
            'periodLabel' => Jalali::periodLabel($period),
            'paymentsCount' => (clone $paid)->count(),
            'paymentsVolume' => (float) (clone $paid)->sum('amount'),
            'paymentsThisPeriod' => (float) (clone $paid)->where('period', $period)->sum('amount'),

            // بدهیِ معوقِ کلِ پلتفرم — مجموعِ ماندهٔ قبض‌های تسویه‌نشده
            'outstanding' => (float) Bill::withoutGlobalScopes()
                ->where('status', '!=', 'paid')
                ->sum(DB::raw('total_amount - paid_amount')),

            /*
             * درآمدِ **پلتفرم** (نه مجتمع‌ها): فقط اشتراک‌های پرداخت‌شده.
             * این تنها عددی است که به کسب‌وکارِ ما مربوط است؛ بقیه پولِ
             * ساختمان‌هاست که فقط از سامانه رد می‌شود.
             */
            'subscriptionRevenue' => (float) Subscription::withoutGlobalScopes()
                ->whereNotNull('paid_at')
                ->sum('amount'),
            'activeSubscriptions' => Subscription::withoutGlobalScopes()
                ->whereNotNull('paid_at')
                ->where('ends_at', '>=', now())
                ->count(),
        ];
    }

    /** @return array<string, int> */
    private function health(): array
    {
        return [
            'unresolvedErrors' => ErrorEvent::withoutGlobalScopes()->whereNull('resolved_at')->count(),
            'errorsToday' => ErrorEvent::withoutGlobalScopes()
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * رشدِ شش‌ماهه: مجتمع‌ها و کاربرانِ تازه در هر ماه.
     *
     * ─── چرا در PHP و نه با `GROUP BY` ─────────────────────────────────────
     * دوره‌ها **جلالی**‌اند و مرزِ ماهِ شمسی وسطِ ماهِ میلادی می‌افتد؛ هیچ
     * `GROUP BY MONTH()` در SQL این را درست نمی‌دهد. تعدادِ ردیف‌ها هم در
     * حدِ چند هزار است، پس گروه‌بندی در حافظه هزینه‌ای ندارد.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trend(): array
    {
        $from = now()->subMonths(self::TREND_MONTHS);

        $complexes = Complex::withoutGlobalScopes()
            ->where('created_at', '>=', $from)->pluck('created_at');
        $users = User::withoutGlobalScopes()
            ->where('created_at', '>=', $from)->pluck('created_at');

        $bucket = fn ($dates) => $dates
            ->groupBy(fn ($date) => Jalali::period($date))
            ->map->count();

        $complexBuckets = $bucket($complexes);
        $userBuckets = $bucket($users);

        $period = Jalali::currentPeriod();
        $months = [];

        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $key = Jalali::shiftPeriod($period, -$i);

            $months[] = [
                'period' => $key,
                'label' => Jalali::periodLabel($key),
                'complexes' => (int) ($complexBuckets[$key] ?? 0),
                'users' => (int) ($userBuckets[$key] ?? 0),
            ];
        }

        return $months;
    }
}
