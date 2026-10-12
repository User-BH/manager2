<?php

namespace App\Console\Commands;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Payment\TrackingCodes;
use App\Services\Subscription\ComplexPurchase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * همزمانیِ واقعیِ تکمیلِ خرید (مرحلهٔ ۹ · فاز ۵؛ خواستِ کارفرما).
 *
 * ⚠️ مثلِ `race:check`: همزمانیِ واقعی در PHPUnit ِ این پروژه ممکن نیست
 * (SQLite ِ درون‌حافظه‌ای، تک‌اتصال، در یک تراکنشِ باز). هر گامِ این دستور در
 * **پردازه‌ی مستقل با اتصالِ دیتابیسِ خودش** اجرا می‌شود؛ `CheckoutConcurrencyTest`
 * چند `complete` را با یک لحظه‌ی شروعِ مشترک هم‌زمان رها می‌کند.
 *
 *     php artisan race:checkout setup                 → {"intent":…,"user":…,"plan":…}
 *     php artisan race:checkout complete --intent= --tracking= --at=<ms>
 *     php artisan race:checkout verify --intent=      → ناوردایی‌ها، JSON
 *     php artisan race:checkout cleanup --intent=
 *
 * روی MySQL (کارِ «قیدهای دیتابیس» ِ CI) قفلِ ردیف واقعی است؛ روی SQLite ِ فایلی
 * قفلِ کلِ پایگاه‌داده و تلاشِ دوباره‌ی تراکنش جایش را می‌گیرد.
 */
class RaceCheckout extends Command
{
    protected $signature = 'race:checkout
                            {operation : setup | complete | verify | cleanup}
                            {--intent= : شناسه‌ی قصدِ خرید}
                            {--tracking=RACE-TRK : کدِ رهگیریِ تأییدشده}
                            {--at= : لحظه‌ی شروعِ مشترک (میلی‌ثانیه‌ی یونیکس)}
                            {--member : خریدار از قبل مستأجرِ مجتمعِ دیگری است (مرحلهٔ ۱۰ · فاز ۵)}';

    protected $description = 'تکمیلِ خرید در پردازه‌های هم‌زمان: هرگز دو مجتمع، دو اشتراک یا دو مدیر';

    public function handle(ComplexPurchase $purchase): int
    {
        return match ($this->argument('operation')) {
            'setup' => $this->doSetup($purchase),
            'complete' => $this->doComplete($purchase),
            'verify' => $this->doVerify(),
            'cleanup' => $this->doCleanup(),
            default => $this->fail('عملیاتِ ناشناخته. یکی از: setup، complete، verify، cleanup'),
        };
    }

    private function doSetup(ComplexPurchase $purchase): int
    {
        $tag = Str::lower(Str::random(8));

        $plan = Plan::create([
            'name' => 'آزمونِ همزمانی', 'slug' => "race-{$tag}", 'complex_type' => ComplexType::Residential,
            'price' => 100000, 'months' => 1, 'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'آزمون', 'last_name' => 'همزمانی', 'name' => 'آزمون همزمانی',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => Str::random(32),
            'role' => UserRole::Owner, 'complex_id' => null, 'is_active' => true,
        ]);
        /*
         * مرحلهٔ ۱۰ · فاز ۵ (تصمیمِ کارفرما): خریدارِ عضوِ مجتمعِ دیگر — همان هم‌زمانی، و این‌بار عضویتِ
         * قبلی و زمینه‌ی فعالش هم باید دست‌نخورده بمانند.
         */
        $home = null;
        if ($this->option('member')) {
            $home = Complex::create(['name' => "خانه‌ی همزمانی {$tag}", 'slug' => "race-home-{$tag}", 'type' => ComplexType::Residential, 'payment_gateway' => 'none']);
            app(MembershipLedger::class)->start($user, $home, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
        }

        $intent = $purchase->begin($user, $plan, "مجتمع همزمانی {$tag}", ComplexType::Residential, null);
        $intent->markGatewayRequested('sandbox', "RACE-{$tag}");

        $this->line((string) json_encode(['intent' => $intent->id, 'user' => $user->id, 'plan' => $plan->id, 'home' => $home?->id]));

        return self::SUCCESS;
    }

    /** همان کاری که بازگشتِ درگاه پس از تأیید می‌کند — همان قفل و همان سرویس */
    private function doComplete(ComplexPurchase $purchase): int
    {
        $intent = CheckoutIntent::find((int) $this->option('intent'));
        if (! $intent) {
            $this->error('قصد پیدا نشد.');

            return self::FAILURE;
        }

        // همه با هم از یک لحظه شروع می‌کنند، نه به ترتیبِ بالا آمدنِ پردازه‌ها
        $at = (int) $this->option('at');
        while ($at > 0 && (int) floor(microtime(true) * 1000) < $at) {
            usleep(500);
        }

        $tracking = (string) $this->option('tracking');

        try {
            $result = TrackingCodes::exclusively($tracking, fn (): CheckoutIntent => $purchase->complete($intent, $tracking));
            $this->line('RESULT:'.$result->status);

            return self::SUCCESS;
        } catch (Throwable $e) {
            // شکستِ یک پردازه خطا نیست؛ ناوردایی‌ها در verify سنجیده می‌شوند
            $this->line('ERROR:'.str_replace("\n", ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }

    private function doVerify(): int
    {
        $intent = CheckoutIntent::findOrFail((int) $this->option('intent'));
        $user = User::withoutGlobalScopes()->find($intent->user_id);

        $this->line((string) json_encode([
            'status' => $intent->status,
            'complexes' => Complex::where('name', $intent->complex_name)->count(),
            'subscriptions' => Subscription::withoutGlobalScopes()->where('tracking_code', $intent->tracking_code ?? '—')->count(),
            'subscriptionsOfComplex' => $intent->complex_id
                ? Subscription::withoutGlobalScopes()->where('complex_id', $intent->complex_id)->count()
                : 0,
            'managers' => User::withoutGlobalScopes()->whereKey($intent->user_id)->where('role', UserRole::ComplexAdmin->value)->count(),
            // مرحلهٔ ۱۰ · فاز ۲: یک خرید، یک عضویتِ فعالِ مدیر
            'activeMemberships' => DB::table('complex_memberships')->where('user_id', $intent->user_id)->where('status', 'active')->count(),
            'userComplex' => $user?->complex_id,
            'userRole' => $user?->role->value,
            // عضویتِ مدیرِ مجتمعِ خریده‌شده، و عضویت‌های فعالِ دیگرِ همان حساب (۱۰/۵)
            'purchaseMemberships' => DB::table('complex_memberships')->where('user_id', $intent->user_id)->where('status', 'active')
                ->where('complex_id', $intent->complex_id ?? 0)->where('role', UserRole::ComplexAdmin->value)->count(),
            'otherMemberships' => DB::table('complex_memberships')->where('user_id', $intent->user_id)->where('status', 'active')
                ->where('complex_id', '!=', $intent->complex_id ?? 0)->pluck('role', 'complex_id')->all(),
            'intentComplex' => $intent->complex_id,
            'linked' => $intent->subscription_id !== null,
            'completedIntentsWithTracking' => CheckoutIntent::where('tracking_code', $intent->tracking_code ?? '—')->where('status', CheckoutIntent::COMPLETED)->count(),
        ]));

        return self::SUCCESS;
    }

    /** فقط داده‌ی همین آزمون، به ترتیبِ کلیدهای خارجی */
    private function doCleanup(): int
    {
        $intent = CheckoutIntent::find((int) $this->option('intent'));
        if (! $intent) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($intent): void {
            $complexId = $intent->complex_id;
            $userId = $intent->user_id;
            $planId = $intent->plan_id;

            $intent->delete();
            // مجتمعِ «خانه»‌ی خریدارِ عضو (`--member`) هم دادهٔ همین آزمون است
            $homes = DB::table('complex_memberships')->where('user_id', $userId)
                ->where('complex_id', '!=', $complexId ?? 0)->pluck('complex_id')->all();
            // دادهٔ خودِ همین آزمون؛ عضویت پیش از حساب و مجتمع (کلیدِ restrict)
            DB::table('complex_memberships')->where('user_id', $userId)->delete();
            User::withoutGlobalScopes()->whereKey($userId)->update(['complex_id' => null]);
            if ($complexId) {
                Subscription::withoutGlobalScopes()->where('complex_id', $complexId)->delete();
                DB::table('audit_logs')->where('complex_id', $complexId)->delete();
                Complex::whereKey($complexId)->delete();
            }
            Complex::whereKey($homes)->where('slug', 'like', 'race-home-%')->delete();
            DB::table('audit_logs')->where('subject_type', CheckoutIntent::class)->where('subject_id', $intent->id)->delete();
            User::withoutGlobalScopes()->whereKey($userId)->delete();
            Plan::whereKey($planId)->delete();
        }, attempts: 3);

        return self::SUCCESS;
    }
}
