<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تعلیقِ عضویت در یک مجتمع (مرحلهٔ ۱۰ · فاز ۶؛ بندهای ۱۱، ۱۲ و ۷۴ ِ فایلِ معماری).
 *
 * ─── یافته ──────────────────────────────────────────────────────────────────
 * «غیرفعال کردن» در صفحه‌ی ساکنین `users.is_active` را می‌نوشت — ستونِ **حساب**. مدیرِ مجتمعِ الف با یک کلیک
 * ورودِ همان شخص را به مجتمعِ ب هم می‌بست، جایی که عضوِ سالم است (بندِ ۱۲: «A → access، B → deny» جدا از هم؛
 * بندِ ۷۴: حساب ممکن است در مجتمعِ دیگری فعال باشد). حالا تعلیق روی **عضویتِ همان مجتمع** است: برگشت‌پذیر،
 * با زمان و تعلیق‌کننده، و جای یکتای «عضویتِ زنده» (`active_key`) را نگه می‌دارد تا دعوت یا درخواستِ تازه
 * دورش نزند. `users.is_active` فقط برای ادمینِ کل می‌ماند (مسدودسازیِ حساب در کلِ سکو).
 *
 * ─── دادهٔ موجود ─────────────────────────────────────────────────────────────
 * حسابِ غیرفعالی که آخرین رویدادِ ثبت‌شده‌اش «resident.deactivated» ِ مدیر است — نه «member.*» ِ ادمینِ کل —
 * به تعلیقِ عضویت در همان مجتمع تبدیل و حسابش فعال می‌شود: همان قصدِ مدیر، بی بستنِ مجتمع‌های دیگر. هر حسابِ
 * غیرفعالِ دیگر (ادمینِ کل، یا بی ردِ audit) دست نمی‌خورد. `down()` همان حساب‌ها را دوباره غیرفعال می‌کند.
 */
return new class extends Migration
{
    /*
     * همه‌ی قیدهای جدول، به ترتیبِ مهاجرتِ ۱۰/۲: بازسازیِ جدول در SQLite همه را می‌اندازد و `MigrationSafetyTest`
     * متنِ `CREATE TABLE` را می‌سنجد، پس قیدِ نقش هم — که این مهاجرت عوضش نمی‌کند — به همان ترتیب برمی‌گردد.
     */
    private const OLD = [
        ['chk_memberships_status', "status in ('active', 'ended')"],
        ['chk_memberships_role', "role in ('owner', 'tenant', 'complex_admin')"],
        ['chk_memberships_lifecycle', "(status = 'active' and ended_at is null and active_key is not null) or (status = 'ended' and ended_at is not null and active_key is null)"],
    ];

    private const NEW = [
        ['chk_memberships_status', "status in ('active', 'suspended', 'ended')"],
        ['chk_memberships_role', "role in ('owner', 'tenant', 'complex_admin')"],
        // زنده (فعال یا معلق): بی پایان و با کلیدِ یکتا؛ پایان‌یافته: با زمانِ پایان و بی کلید
        ['chk_memberships_lifecycle', "(status in ('active', 'suspended') and ended_at is null and active_key is not null) or (status = 'ended' and ended_at is not null and active_key is null)"],
        // معلق یعنی زمانِ تعلیق دارد؛ فعال یعنی ندارد (پایان‌یافته هر کدام، به‌عنوانِ سابقه)
        ['chk_memberships_suspension', "(status <> 'suspended' or suspended_at is not null) and (status <> 'active' or suspended_at is null)"],
    ];

    public function up(): void
    {
        Schema::table('complex_memberships', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('started_at');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('users')->nullOnDelete();
        });

        /*
         * ⚠️ ترتیب مهم است: روی SQLite افزودنِ ستونِ کلیدِ خارجی جدول را از نو می‌سازد و قیدهایی که پس از ساخت
         * افزوده شده بودند می‌افتند (اجرای محلیِ ۱۰/۶ — `SchemaConstraintTest` قیدهای ۱۰/۲ را دیگر پیدا نکرد).
         * پس قیدها **پس از** هر بازسازی، و فقط اگر نیستند، گذاشته می‌شوند.
         */
        $this->dropConstraints(self::OLD);
        $this->ensureConstraints(self::NEW);
        $this->backfill();
    }

    public function down(): void
    {
        // تعلیقِ عضویت به همان غیرفعالیِ حساب برمی‌گردد که پیش از این فاز بود
        $suspended = DB::table('complex_memberships')->where('status', 'suspended')->pluck('user_id')->unique();
        DB::table('users')->whereIn('id', $suspended)->update(['is_active' => false]);
        DB::table('complex_memberships')->where('status', 'suspended')
            ->update(['status' => 'active', 'suspended_at' => null, 'suspended_by' => null]);

        $this->dropConstraints(self::NEW);

        Schema::table('complex_memberships', function (Blueprint $table) {
            $table->dropForeign(['suspended_by']);
            $table->dropColumn(['suspended_at', 'suspended_by']);
        });

        $this->ensureConstraints(self::OLD);
    }

    private function backfill(): void
    {
        $candidates = DB::table('users')
            ->where('is_active', false)
            ->whereNotNull('complex_id')
            ->get(['id', 'complex_id']);

        foreach ($candidates as $user) {
            $last = DB::table('audit_logs')
                ->where('subject_type', 'App\\Models\\User')
                ->where('subject_id', $user->id)
                ->whereIn('action', ['resident.deactivated', 'resident.activated', 'member.updated', 'member.deactivated'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->first(['action', 'user_id', 'created_at']);

            if ($last === null || $last->action !== 'resident.deactivated') {
                continue;
            }

            $updated = DB::table('complex_memberships')
                ->where('user_id', $user->id)
                ->where('complex_id', $user->complex_id)
                ->where('status', 'active')
                ->update([
                    'status' => 'suspended',
                    'suspended_at' => $last->created_at,
                    'suspended_by' => $last->user_id,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                continue;
            }

            // زمینه‌ی حساب دیگر عضویتِ معلق نیست: تازه‌ترین عضویتِ فعالِ دیگر، وگرنه حالتِ اولیه
            $next = DB::table('complex_memberships')->where('user_id', $user->id)->where('status', 'active')
                ->orderByDesc('started_at')->orderByDesc('id')->first(['complex_id', 'role']);

            DB::table('users')->where('id', $user->id)->update([
                'is_active' => true,
                'complex_id' => $next?->complex_id,
                'role' => $next->role ?? 'owner',
            ]);
        }
    }

    /** @param  list<array{0: string, 1: string}>  $constraints */
    private function dropConstraints(array $constraints): void
    {
        foreach ($constraints as [$name]) {
            if (! $this->hasConstraint($name)) {
                continue;
            }
            try {
                DB::statement("ALTER TABLE complex_memberships DROP CHECK {$name}");
            } catch (Throwable) {
                // SQLite و MariaDB همین را با نامِ دیگری می‌خواهند
                DB::statement("ALTER TABLE complex_memberships DROP CONSTRAINT {$name}");
            }
        }
    }

    /**
     * ⚠️ خطا بلعیده نمی‌شود: دادهٔ ناسازگار با قیدِ تازه باید مهاجرت را بایستاند، نه بی‌صدا بی‌قید بماند.
     *
     * @param  list<array{0: string, 1: string}>  $constraints
     */
    private function ensureConstraints(array $constraints): void
    {
        if (! $this->supportsAddedChecks()) {
            return;
        }

        foreach ($constraints as [$name, $condition]) {
            if (! $this->hasConstraint($name)) {
                DB::statement("ALTER TABLE complex_memberships ADD CONSTRAINT {$name} CHECK ({$condition})");
            }
        }
    }

    private function hasConstraint(string $name): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'complex_memberships')->value('sql');

            return stripos($sql, $name) !== false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'complex_memberships')
            ->where('constraint_name', $name)
            ->exists();
    }

    private function supportsAddedChecks(): bool
    {
        $probe = 'probe_membership_suspension_support';

        try {
            DB::statement("DROP TABLE IF EXISTS {$probe}");
            DB::statement("CREATE TABLE {$probe} (n integer)");
            DB::statement("ALTER TABLE {$probe} ADD CONSTRAINT chk_probe_suspension CHECK (n > 0)");

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            try {
                DB::statement("DROP TABLE IF EXISTS {$probe}");
            } catch (Throwable) {
                // جدولِ یک‌بارمصرف
            }
        }
    }
};
