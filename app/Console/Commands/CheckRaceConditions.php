<?php

namespace App\Console\Commands;

use App\Enums\ComplexType;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\Membership;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * اجرای یک عملیاتِ مالیِ تکی، برای آزمونِ همزمانی (R50).
 *
 * ─── چرا این یک دستورِ artisan است و نه یک تست ──────────────────────────────
 * ⚠️ همزمانیِ واقعی در PHPUnit این پروژه **ممکن نیست**. تست‌ها روی
 * SQLiteِ درون‌حافظه‌ای اجرا می‌شوند که تک‌اتصال است، و `RefreshDatabase`
 * همه‌چیز را در یک تراکنشِ باز می‌پیچد. یعنی دو «درخواستِ هم‌زمان» در تست،
 * در واقع دو فراخوانیِ پشتِ‌سرِهم روی یک اتصال‌اند — و رقابتی که باید
 * سنجیده شود اصلاً ساخته نمی‌شود.
 *
 * این دستور همان عملیات را در یک **پردازه‌ی مستقل** اجرا می‌کند. با
 * اجرای هم‌زمانِ چند نسخه‌ی آن، هر کدام اتصالِ دیتابیسِ خودش را دارد و
 * همزمانی واقعی می‌شود.
 *
 * ─── چرا اجرایش روی MySQL اهمیت دارد ───────────────────────────────────────
 * ⚠️ اندازه‌گیری شد که `lockForUpdate()` روی SQLite **هیچ SQLی تولید
 * نمی‌کند** — بندِ `for update` بی‌صدا حذف می‌شود. پس هر آزمونی روی SQLite،
 * کد را **بدونِ قفل** اجرا می‌کند.
 *
 * این بد نیست؛ در واقع سخت‌گیرانه‌تر است: اگر منطق روی SQLite هم درست
 * بماند، یعنی دفاعی فراتر از قفل دارد. ولی **درستیِ خودِ قفل** فقط روی
 * MySQL قابلِ اثبات است، و تا آن اجرا انجام نشود وضعیتش
 * **NOT VERIFIED** می‌ماند.
 *
 * ─── نحوه‌ی استفاده ────────────────────────────────────────────────────────
 * چند نسخه را هم‌زمان اجرا کنید (مثلاً با `xargs -P` یا اسکریپت):
 *
 *     php artisan race:check debit --user=5 --unit=1
 *     php artisan race:check settle --payment=7
 *
 * سپس با `verify` مطمئن شوید هیچ‌چیز خراب نشده:
 *
 *     php artisan race:check verify
 */
class CheckRaceConditions extends Command
{
    protected $signature = 'race:check
                            {operation : debit | settle | verify | wallet-setup | wallet-verify | wallet-cleanup | invite-setup | accept | invite-verify | invite-cleanup}
                            {--unit= : شناسه‌ی واحد (فقط «کجا»)}
                            {--user= : صاحبِ کیف}
                            {--payment= : شناسه‌ی پرداخت}
                            {--amount=10000 : مبلغ}
                            {--complex= : شناسه‌ی مجتمع (wallet-verify، wallet-cleanup)}
                            {--invitation= : شناسه‌ی دعوت (accept)}
                            {--at=0 : لحظه‌ی شروعِ مشترک به میلی‌ثانیه (برای هم‌زمانیِ واقعی)}';

    protected $description = 'اجرای یک عملیاتِ مالیِ تکی برای آزمونِ همزمانی (چند پردازه‌ای)';

    public function handle(WalletService $wallet, PaymentService $payments): int
    {
        return match ($this->argument('operation')) {
            'debit' => $this->debit($wallet),
            'settle' => $this->settle($payments),
            'verify' => $this->verify($wallet),
            'wallet-setup' => $this->walletSetup($wallet),
            'wallet-verify' => $this->walletVerify($wallet),
            'wallet-cleanup' => $this->walletCleanup(),
            'invite-setup' => $this->inviteSetup(),
            'accept' => $this->accept(),
            'invite-verify' => $this->inviteVerify(),
            'invite-cleanup' => $this->inviteCleanup(),
            default => $this->fail('عملیاتِ ناشناخته. یکی از: debit، settle، verify، wallet-setup، wallet-verify، wallet-cleanup، invite-setup، accept، invite-verify، invite-cleanup'),
        };
    }

    /** برداشت از کیف پول — دو اجرای هم‌زمان نباید موجودی را منفی کنند. */
    private function debit(WalletService $wallet): int
    {
        $unit = Unit::withoutGlobalScopes()->find((int) $this->option('unit'));
        $owner = User::withoutGlobalScopes()->find((int) $this->option('user'));

        if (! $unit || ! $owner) {
            $this->error('واحد یا صاحبِ کیف پیدا نشد. با --unit و --user شناسه بدهید.');

            return self::FAILURE;
        }

        // لحظه‌ی مشترک: همه‌ی پردازه‌ها با هم به قفل می‌رسند، نه پشتِ سرِ هم
        $at = (int) $this->option('at');
        while ($at > 0 && (int) floor(microtime(true) * 1000) < $at) {
            usleep(500);
        }

        try {
            // کیف مالِ حساب است (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶)
            $wallet->debit($owner, $unit, (float) $this->option('amount'), 'manual', null, 'آزمونِ همزمانی');
            $this->line('OK');

            return self::SUCCESS;
        } catch (Throwable $e) {
            // ⚠️ شکست اینجا **موفقیت** است: یعنی دومین برداشت جلویش گرفته شد
            $this->line('BLOCKED: '.str_replace("\n", ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * دادهٔ یک‌بارمصرف برای `WalletConcurrencyTest`: مجتمع، واحد، صاحبِ کیف و
     * شارژِ اولیه — از همان `WalletService`. خروجی JSON.
     */
    private function walletSetup(WalletService $wallet): int
    {
        $tag = Str::lower(Str::random(8));

        $complex = Complex::create([
            'name' => "مجتمعِ همزمانیِ کیف {$tag}", 'slug' => "race-wallet-{$tag}",
            'type' => ComplexType::Residential, 'payment_gateway' => 'none',
        ]);
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 80,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::OwnerOccupied,
        ]);
        $owner = User::create([
            'first_name' => 'آزمون', 'last_name' => 'کیف', 'name' => 'آزمون کیف',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => Str::random(32),
            'role' => UserRole::Owner, 'complex_id' => $complex->id, 'is_active' => true,
        ]);

        $wallet->credit($owner, $unit, (float) $this->option('amount'), WalletTransaction::SOURCE_ADJUSTMENT, null, 'شارژِ آزمونِ همزمانی');

        $this->line((string) json_encode(['user' => $owner->id, 'unit' => $unit->id, 'complex' => $complex->id]));

        return self::SUCCESS;
    }

    /** ناوردایی‌های همان یک جیب پس از هجوم — خروجی JSON. */
    private function walletVerify(WalletService $wallet): int
    {
        $owner = User::withoutGlobalScopes()->findOrFail((int) $this->option('user'));
        $complexId = (int) $this->option('complex');

        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $owner->id)->where('complex_id', $complexId)->orderBy('id')->get();

        // هر ردیف باید مانده‌ی پیشین ± مبلغِ خودش باشد؛ دوباره‌شماری همین‌جا می‌شکند
        $running = 0.0;
        $chainBroken = 0;
        foreach ($rows as $row) {
            $running += $row->direction === WalletTransaction::CREDIT ? (float) $row->amount : -(float) $row->amount;
            if (abs($running - (float) $row->balance_after) > 0.01) {
                $chainBroken++;
            }
        }

        $this->line((string) json_encode([
            'balance' => $wallet->balance($owner, $complexId),
            'ledgerSum' => round($running, 2),
            'lastSnapshot' => $rows->isEmpty() ? null : (float) $rows->last()->balance_after,
            'debits' => $rows->where('direction', WalletTransaction::DEBIT)->count(),
            'chainBroken' => $chainBroken,
        ]));

        return self::SUCCESS;
    }

    /**
     * پاک‌کردنِ دادهٔ آزمون. ⚠️ دفترِ کیف با Eloquent پاک‌شدنی نیست (`Immutable`)؛
     * این‌جا عمداً از درِ خامِ `DB::table` — فقط برای ردیف‌های همین آزمون.
     */
    private function walletCleanup(): int
    {
        $complexId = (int) $this->option('complex');
        $userId = (int) $this->option('user');

        DB::transaction(function () use ($complexId, $userId): void {
            DB::table('wallet_transactions')->where('complex_id', $complexId)->where('user_id', $userId)->delete();
            DB::table('units')->where('complex_id', $complexId)->delete();
            DB::table('users')->where('id', $userId)->delete();
            DB::table('audit_logs')->where('complex_id', $complexId)->delete();
            DB::table('complexes')->where('id', $complexId)->delete();
        }, attempts: 3);

        return self::SUCCESS;
    }

    /**
     * دادهٔ یک‌بارمصرف برای `InvitationConcurrencyTest` (مرحلهٔ ۹ · اصلاحاتِ پس از
     * فاز ۶): دو مجتمع، هر کدام با مدیر و یک واحد، یک حسابِ اولیه و دو دعوتِ زنده
     * به شماره‌اش — از همان `Membership::invite`. خروجی JSON.
     */
    private function inviteSetup(): int
    {
        $tag = Str::lower(Str::random(8));
        $phone = '0912'.random_int(1000000, 9999999);

        $complexes = [];
        $invitations = [];
        foreach (['a', 'b'] as $side) {
            $complex = Complex::create([
                'name' => "مجتمعِ همزمانیِ دعوت {$tag}-{$side}", 'slug' => "race-invite-{$tag}-{$side}",
                'type' => ComplexType::Residential, 'payment_gateway' => 'none',
            ]);
            $unit = Unit::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 80,
                'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::Vacant,
            ]);
            $manager = User::create([
                'first_name' => 'مدیر', 'last_name' => $side, 'name' => "مدیر {$side}",
                'phone' => '0913'.random_int(1000000, 9999999), 'password' => Str::random(32),
                'role' => UserRole::ComplexAdmin, 'complex_id' => $complex->id, 'is_active' => true,
            ]);
            [$invitation] = app(Membership::class)->invite($complex, $phone, UserRole::Tenant, $unit, $manager);

            $complexes[] = $complex->id;
            $invitations[] = $invitation->id;
        }

        $user = User::create([
            'first_name' => 'آزمون', 'last_name' => 'دعوت', 'name' => 'آزمون دعوت',
            'phone' => $phone, 'password' => Str::random(32),
            'role' => UserRole::Owner, 'complex_id' => null, 'is_active' => true,
        ]);

        $this->line((string) json_encode(['user' => $user->id, 'complexes' => $complexes, 'invitations' => $invitations]));

        return self::SUCCESS;
    }

    /** پذیرشِ یک دعوت — دو پذیرشِ هم‌زمان نباید دو عضویت بسازند. */
    private function accept(): int
    {
        $invitation = ComplexInvitation::find((int) $this->option('invitation'));
        $user = User::withoutGlobalScopes()->find((int) $this->option('user'));

        if (! $invitation || ! $user) {
            $this->error('دعوت یا کاربر پیدا نشد. با --invitation و --user شناسه بدهید.');

            return self::FAILURE;
        }

        $at = (int) $this->option('at');
        while ($at > 0 && (int) floor(microtime(true) * 1000) < $at) {
            usleep(500);
        }

        try {
            app(Membership::class)->accept($invitation, $user);
            $this->line('OK');

            return self::SUCCESS;
        } catch (Throwable $e) {
            // ⚠️ شکست این‌جا **موفقیت** است: پذیرشِ دوم جلویش گرفته شد
            $this->line('BLOCKED: '.str_replace("\n", ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /** وضعیتِ عضویتِ حسابِ آزمون پس از هجوم — خروجی JSON. */
    private function inviteVerify(): int
    {
        $user = User::withoutGlobalScopes()->findOrFail((int) $this->option('user'));

        $this->line((string) json_encode([
            'complex' => $user->complex_id,
            'tenures' => UnitTenure::withoutGlobalScopes()->where('user_id', $user->id)->count(),
            'openTenures' => UnitTenure::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('end_date')->count(),
            'tenureComplexes' => Unit::withoutGlobalScopes()
                ->whereIn('id', UnitTenure::withoutGlobalScopes()->where('user_id', $user->id)->select('unit_id'))
                ->distinct()->pluck('complex_id')->all(),
            'accepted' => ComplexInvitation::where('phone', $user->phone)->where('status', ComplexInvitation::ACCEPTED)->pluck('complex_id')->all(),
            'live' => ComplexInvitation::where('phone', $user->phone)->whereNotNull('live_key')->count(),
        ]));

        return self::SUCCESS;
    }

    /** پاک‌کردنِ دادهٔ آزمونِ دعوت — فقط ردیف‌های همین مجتمع‌ها و همین حساب. */
    private function inviteCleanup(): int
    {
        $userId = (int) $this->option('user');
        $complexIds = array_map('intval', explode(',', (string) $this->option('complex')));

        DB::transaction(function () use ($userId, $complexIds): void {
            DB::table((new UnitTenure)->getTable())->where('user_id', $userId)->delete();
            DB::table('complex_invitations')->whereIn('complex_id', $complexIds)->delete();
            DB::table('users')->where('id', $userId)->orWhereIn('complex_id', $complexIds)->delete();
            DB::table('units')->whereIn('complex_id', $complexIds)->delete();
            DB::table('audit_logs')->whereIn('complex_id', $complexIds)->delete();
            DB::table('complexes')->whereIn('id', $complexIds)->delete();
        }, attempts: 3);

        return self::SUCCESS;
    }

    /** تسویه‌ی پرداخت — چند بازگشتِ هم‌زمانِ درگاه نباید دوبار بنشیند. */
    private function settle(PaymentService $payments): int
    {
        $payment = Payment::withoutGlobalScopes()->find((int) $this->option('payment'));

        if (! $payment) {
            $this->error('پرداخت پیدا نشد. با --payment شناسه بدهید.');

            return self::FAILURE;
        }

        try {
            /*
             * ⚠️ همان الگوی `GatewayController` بازسازی می‌شود: تراکنشِ
             * بیرونی + قفل + بازخوانیِ وضعیت. `attempts` روی تراکنشِ
             * **بیرونی** است، چون در تراکنشِ تودرتو لاراول اصلاً تلاشِ
             * دوباره نمی‌کند.
             */
            $done = DB::transaction(function () use ($payment, $payments): bool {
                $fresh = Payment::withoutGlobalScopes()->lockForUpdate()->find($payment->id);

                if (! $fresh || $fresh->status !== PaymentStatus::Pending) {
                    return false;
                }

                $payments->settle($fresh);

                return true;
            }, attempts: 3);

            $this->line($done ? 'SETTLED' : 'SKIPPED');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('FAILED: '.str_replace("\n", ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * ⚠️ مهم‌ترین بخش: پس از هجومِ هم‌زمان، ناوردایی‌ها هنوز برقرارند؟
     *
     * این‌ها چیزهایی‌اند که اگر رقابتی رخ داده باشد، حتماً می‌شکنند — حتی
     * اگر هیچ خطایی در لاگ نباشد.
     */
    private function verify(WalletService $wallet): int
    {
        $problems = [];

        // ① هیچ جیبی (صاحب، مجتمع) نباید موجودیِ منفی داشته باشد
        $pockets = WalletTransaction::withoutGlobalScopes()
            ->select('user_id', 'complex_id')->distinct()->get();

        foreach ($pockets as $pocket) {
            $owner = User::withoutGlobalScopes()->findOrFail($pocket->user_id);
            $balance = $wallet->balance($owner, (int) $pocket->complex_id);
            $label = "کیفِ کاربر #{$pocket->user_id} در مجتمع #{$pocket->complex_id}";

            if ($balance < 0) {
                $problems[] = "{$label} موجودیِ منفی دارد: {$balance}";
            }

            /*
             * ② `balance_after` آخرین ردیفِ دفتر باید با جمعِ کلِ دفتر بخواند.
             *
             * ⚠️ این دقیقاً همان چیزی است که با رقابت می‌شکند: دو نوشتنِ
             * هم‌زمان هر دو مانده‌ی یکسانی می‌خوانند و هر دو `balance_after`
             * را بر آن مبنا می‌نویسند، پس عکسِ لحظه‌ای با دفتر واگرا می‌شود
             * بی‌آنکه خطایی رخ دهد.
             */
            $last = WalletTransaction::withoutGlobalScopes()
                ->where('user_id', $pocket->user_id)
                ->where('complex_id', $pocket->complex_id)
                ->latest('id')
                ->first();

            if ($last && abs((float) $last->balance_after - $balance) > 0.01) {
                $problems[] = "{$label}: balance_after={$last->balance_after} ولی جمعِ دفتر={$balance}";
            }
        }

        // ③ هیچ قبضی نباید بیش از مبلغِ کل پرداخت شده باشد
        foreach (Bill::withoutGlobalScopes()->get() as $bill) {
            if ((float) $bill->paid_amount > (float) $bill->total_amount + 0.01) {
                $problems[] = "قبض #{$bill->id}: پرداخت‌شده {$bill->paid_amount} > کل {$bill->total_amount}";
            }
        }

        // ④ هیچ قبضی نباید بیش از یک رسیدِ در انتظار داشته باشد
        $duplicates = Payment::withoutGlobalScopes()
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('bill_id')
            ->selectRaw('bill_id, count(*) as total')
            ->groupBy('bill_id')
            ->having('total', '>', 1)
            ->pluck('total', 'bill_id');

        foreach ($duplicates as $billId => $total) {
            $problems[] = "قبض #{$billId}: {$total} پرداختِ در انتظار (باید یکی باشد)";
        }

        if ($problems === []) {
            $this->info('همه‌ی ناوردایی‌ها برقرارند.');
            $this->line('  درایور: '.DB::getDriverName().
                (DB::getDriverName() === 'sqlite' ? '  ⚠️ روی SQLite قفلِ ردیفی اعمال نمی‌شود' : ''));

            return self::SUCCESS;
        }

        $this->error('یکپارچگیِ داده شکسته است:');

        foreach ($problems as $problem) {
            $this->line('  • '.$problem);
        }

        return self::FAILURE;
    }
}
