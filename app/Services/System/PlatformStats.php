<?php

namespace App\Services\System;

use App\Enums\ComplexType;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
     * دادهٔ آزمایشی به‌طورِ پیش‌فرض بیرون است («داشبورد های آزمایشی» بندِ ۴.۲؛ مرحلهٔ ۱۳ · فاز ۱): هر عدد فقط مجتمع‌ها،
     * حساب‌ها و ردیف‌های عملیاتی را می‌شمارد، مگر سوپر ادمین صریحاً «شاملِ دادهٔ آزمایشی» را بخواهد.
     */
    private bool $includeDemo = false;

    /**
     * @return array<string, mixed>
     */
    public function all(bool $includeDemo = false): array
    {
        $this->includeDemo = $includeDemo;

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
        $complexes = $this->realComplexes(Complex::withoutGlobalScopes())->get(['id', 'is_active', 'type']);

        return [
            'total' => $complexes->count(),
            'active' => $complexes->where('is_active', true)->count(),
            'suspended' => $complexes->where('is_active', false)->count(),
            /*
             * تفکیکِ نوع (R67).
             *
             * ⚠️ بدونِ این عدد، ادمینِ کل نمی‌داند تعرفه‌ی تجاری اصلاً
             * مشتری دارد یا نه — و تصمیمِ قیمت‌گذاری بدونِ آن، حدس است.
             */
            'residential' => $complexes->where('type', ComplexType::Residential)->count(),
            'commercial' => $complexes->where('type', ComplexType::Commercial)->count(),
            'units' => $this->real(Unit::withoutGlobalScopes())->count(),
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
            'total' => $this->realUsers(User::withoutGlobalScopes())->count(),
            'active' => $this->realUsers(User::withoutGlobalScopes())->where('is_active', true)->count(),
            /*
             * معیارِ «درگیر بودن» از خودِ فعالیت خوانده می‌شود و نه از یک
             * ستونِ `last_seen_at`: چنین ستونی باید در هر درخواست نوشته
             * می‌شد و برای عددی که ماهی یک بار نگاه می‌شود، هزینه‌ی
             * سنگینی است.
             */
            'engaged' => $this->realUsers(User::withoutGlobalScopes())
                ->where(fn ($q) => $q
                    ->whereHas('payments', fn ($p) => $p->where('created_at', '>=', $since))
                    ->orWhereHas('serviceRequests', fn ($r) => $r->where('created_at', '>=', $since)))
                ->count(),
            'openRequests' => $this->real(ServiceRequest::withoutGlobalScopes())->open()->count(),
        ];
    }

    /** @return array<string, float|int|string> */
    private function money(): array
    {
        $period = Jalali::currentPeriod();

        $paid = $this->real(Payment::withoutGlobalScopes())->where('status', PaymentStatus::Success->value);

        return [
            'periodLabel' => Jalali::periodLabel($period),
            'paymentsCount' => (clone $paid)->count(),
            'paymentsVolume' => (float) (clone $paid)->sum('amount'),
            'paymentsThisPeriod' => (float) (clone $paid)->where('period', $period)->sum('amount'),

            // بدهیِ معوقِ کلِ پلتفرم — مجموعِ ماندهٔ قبض‌های تسویه‌نشده
            'outstanding' => (float) $this->real(Bill::withoutGlobalScopes())
                ->where('status', '!=', 'paid')
                ->sum(DB::raw('total_amount - paid_amount')),

            /*
             * درآمدِ **پلتفرم** (نه مجتمع‌ها): فقط اشتراک‌های پرداخت‌شده.
             * این تنها عددی است که به کسب‌وکارِ ما مربوط است؛ بقیه پولِ
             * ساختمان‌هاست که فقط از سامانه رد می‌شود.
             */
            'subscriptionRevenue' => (float) $this->real(Subscription::withoutGlobalScopes())
                ->whereNotNull('paid_at')
                ->sum('amount'),
            'activeSubscriptions' => $this->real(Subscription::withoutGlobalScopes())
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

        $complexes = $this->realComplexes(Complex::withoutGlobalScopes())
            ->where('created_at', '>=', $from)->pluck('created_at');
        $users = $this->realUsers(User::withoutGlobalScopes())
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

    /**
     * ردیف‌هایی که مالِ مجتمعِ آزمایشی نیستند. ردیفِ بی‌مجتمع (مثلاً اشتراکِ پیش از ساختِ مجتمع) عملیاتی است: `NOT IN`
     * روی `NULL` نتیجه‌ی نامعلوم می‌دهد و آن ردیف را بی‌صدا بیرون می‌انداخت.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function real(Builder $query, string $column = 'complex_id'): Builder
    {
        if ($this->includeDemo) {
            return $query;
        }

        $demo = Complex::withoutGlobalScopes()->select('id')->where('is_demo', true);

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereNotIn($column, $demo));
    }

    /**
     * @param  Builder<Complex>  $query
     * @return Builder<Complex>
     */
    private function realComplexes(Builder $query): Builder
    {
        return $this->includeDemo ? $query : $query->where('is_demo', false);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function realUsers(Builder $query): Builder
    {
        return $this->includeDemo ? $query : $query->where('is_demo', false);
    }
}
