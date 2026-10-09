<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * کیفِ پول مالِ **حسابِ شخص** است، نه واحد (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛
 * تصمیمِ کارفرما).
 *
 * پیش از این دفترِ کیف با `unit_id` کلید می‌خورد: مانده مالِ واحد بود، پس با
 * عوض‌شدنِ ساکن، ساکنِ تازه با پولِ ساکنِ قبلی قبض می‌پرداخت و ساکنِ رفته
 * پولش را گم می‌کرد. حالا هر ردیف صاحب (`user_id`) دارد؛ `unit_id` و
 * `complex_id` فقط «کجا» را نگه می‌دارند.
 *
 * ─── صاحبِ ردیف‌های موجود (production خالی است؛ برای هر نصبِ دیگر) ──────────
 * ۱. ردیفِ پرداخت ← پرداخت‌کننده (`payments.user_id`)
 * ۲. برداشتِ «پرداختِ قبض» ← کسی که پرداخت کرد (`created_by`)
 * ۳. بقیه ← مالکِ فعلیِ واحد، وگرنه ساکنِ فعلیِ واحد
 * اگر حتی یک ردیف صاحبِ قطعی نداشته باشد، مهاجرت **پیش از هر تغییری** می‌ایستد.
 *
 * @destructive فقط `user_id` اجباری می‌شود (`->change()`؛ در SQLite بازسازیِ جدول با
 * همان داده و بازگرداندنِ دو قیدِ CHECK ِ R52). هیچ ردیفی حذف یا عوض نمی‌شود جز پرشدنِ
 * ستونِ تازه.
 */
return new class extends Migration
{
    /** قیدهای R52 روی همین جدول (`2026_08_26_000002_add_financial_check_constraints`) */
    private const CHECKS = [
        ['chk_wallet_amount_positive', 'amount > 0'],
        ['chk_wallet_balance_non_negative', 'balance_after >= 0'],
    ];

    public function up(): void
    {
        $owners = $this->resolveOwners();

        $hadChecks = $this->existingChecks();

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('unit_id')->constrained('users')->restrictOnDelete();
        });

        foreach ($owners as $rowId => $userId) {
            DB::table('wallet_transactions')->where('id', $rowId)->update(['user_id' => $userId]);
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            // مانده‌ی هر «جیب» (صاحب، مجتمع) و صورت‌حسابش
            $table->index(['user_id', 'complex_id', 'id'], 'wallet_owner_pocket_index');
        });

        $this->restoreChecks($hadChecks);
    }

    public function down(): void
    {
        $hadChecks = $this->existingChecks();

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_owner_pocket_index');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        $this->restoreChecks($hadChecks);
    }

    /**
     * صاحبِ هر ردیفِ موجود — همه پیش از هر تغییرِ ساختاری.
     *
     * @return array<int, int>
     */
    private function resolveOwners(): array
    {
        $owners = [];
        $orphans = [];

        foreach (DB::table('wallet_transactions')->orderBy('id')->get() as $row) {
            $owner = null;

            if ($row->payment_id !== null) {
                $owner = DB::table('payments')->where('id', $row->payment_id)->value('user_id');
            }

            if ($owner === null && $row->source === 'bill_payment' && $row->created_by !== null) {
                $owner = $row->created_by;
            }

            if ($owner === null) {
                $owner = DB::table('unit_user')->where('unit_id', $row->unit_id)->where('is_current', true)
                    ->orderByRaw("CASE WHEN relation = 'owner' THEN 0 ELSE 1 END")->orderBy('id')
                    ->value('user_id');
            }

            if ($owner === null) {
                $orphans[] = $row->id;

                continue;
            }

            $owners[(int) $row->id] = (int) $owner;
        }

        if ($orphans !== []) {
            throw new RuntimeException(
                count($orphans).' ردیفِ کیف صاحبِ قطعی ندارد (شناسه‌ها: '.implode(', ', array_slice($orphans, 0, 20))
                .')؛ پیش از این مهاجرت باید تعیینِ تکلیف شوند. هیچ داده‌ای تغییر نکرد.',
            );
        }

        return $owners;
    }

    /** @return array<int, string> نامِ قیدهایی که اکنون هست */
    private function existingChecks(): array
    {
        return array_values(array_filter(
            array_column(self::CHECKS, 0),
            fn (string $name) => $this->hasCheck($name),
        ));
    }

    private function hasCheck(string $name): bool
    {
        return match (DB::getDriverName()) {
            'sqlite' => str_contains((string) DB::table('sqlite_master')->where('name', 'wallet_transactions')->value('sql'), $name),
            'mysql', 'mariadb' => DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'wallet_transactions')
                ->where('CONSTRAINT_NAME', $name)
                ->exists(),
            default => false,
        };
    }

    /**
     * SQLite برای تغییرِ ستون جدول را از نو می‌سازد و قیدهای CHECK را می‌اندازد
     * (همان درسِ `protect_subscription_history`)؛ هر قیدی که پیش از تغییر بود برمی‌گردد.
     *
     * @param  array<int, string>  $had
     */
    private function restoreChecks(array $had): void
    {
        foreach (self::CHECKS as [$name, $expression]) {
            if (in_array($name, $had, true) && ! $this->hasCheck($name)) {
                DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }
};
