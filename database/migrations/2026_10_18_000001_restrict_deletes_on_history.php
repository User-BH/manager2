<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حذفِ والد تاریخچه را نمی‌برد (مرحلهٔ ۱۰ · فاز ۱۴؛ بندِ ۷۹ ِ فایلِ معماری).
 *
 * پیش از این، کلیدهای مجتمع و واحد روی قبض، پرداخت، تخصیص، کیف، درآمد و هزینه، دوره‌های سکونت، گفت‌وگو و پیام
 * `cascadeOnDelete` بودند: یک `DELETE` ِ خام روی واحد (یا مجتمعی بی ردیفِ عضویت) کلِ تاریخچه‌ی مالی و پیام‌رسانیِ آن
 * را بی‌صدا پاک می‌کرد. رابط هیچ‌جا حذفِ سخت ندارد (واحد نرم حذف می‌شود، مجتمع تعلیق)، ولی قیدِ حیاتی نباید فقط در کد
 * باشد (بندِ ۷۸). حالا همین کلیدها `restrictOnDelete`اند: والدِ دارای تاریخچه با حذفِ سخت نمی‌رود.
 *
 * ثبتِ کاملِ تصمیم‌ها برای هر کلید: `DeleteSemanticsTest::REGISTER` و `docs/BACKEND_STRUCTURE.md`.
 *
 * ─── SQLite و MySQL ────────────────────────────────────────────────────────
 * • MySQL: فقط همان کلیدها برداشته و دوباره گذاشته می‌شوند، با `foreign_key_checks` خاموش تا افزودنِ کلید جدول را
 *   کپی نکند (داده از پیش با همان کلید سازگار است؛ فقط رفتارِ حذف عوض می‌شود).
 * • SQLite (محیطِ آزمون): تغییرِ کلید یعنی بازسازیِ جدول. همه‌ی کلیدهای جدول به همان ترتیب دوباره گذاشته می‌شوند تا
 *   `MigrationSafetyTest` همان متن را ببیند، و قیدهای CHECK ِ R52 که بازسازی می‌اندازد برمی‌گردند (همان درسِ
 *   `2026_10_08_000002_protect_subscription_history`).
 */
return new class extends Migration
{
    /** جدول ← ستون ← جدولِ والد */
    private const CHANGES = [
        'units' => ['complex_id' => 'complexes'],
        'bills' => ['complex_id' => 'complexes', 'unit_id' => 'units'],
        'payments' => ['complex_id' => 'complexes', 'unit_id' => 'units'],
        'payment_allocations' => ['complex_id' => 'complexes'],
        'wallet_transactions' => ['complex_id' => 'complexes', 'unit_id' => 'units'],
        'incomes' => ['complex_id' => 'complexes'],
        'expenses' => ['complex_id' => 'complexes'],
        'unit_user' => ['complex_id' => 'complexes', 'unit_id' => 'units', 'user_id' => 'users'],
        'conversations' => ['complex_id' => 'complexes'],
        'messages' => ['complex_id' => 'complexes'],
        'poll_votes' => ['user_id' => 'users'],
    ];

    public function up(): void
    {
        $this->apply('restrict');
    }

    public function down(): void
    {
        $this->apply('cascade');
    }

    private function apply(string $onDelete): void
    {
        foreach (self::CHANGES as $table => $columns) {
            DB::getDriverName() === 'sqlite'
                ? $this->rebuildSqlite($table, $columns, $onDelete)
                : $this->alterInPlace($table, $columns, $onDelete);
        }
    }

    /** @param  array<string, string>  $columns */
    private function alterInPlace(string $table, array $columns, string $onDelete): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($table, $columns, $onDelete): void {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach (array_keys($columns) as $column) {
                    $blueprint->dropForeign([$column]);
                }
            });
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $onDelete): void {
                foreach ($columns as $column => $parent) {
                    $blueprint->foreign($column)->references('id')->on($parent)->onDelete($onDelete);
                }
            });
        });
    }

    /** @param  array<string, string>  $columns */
    private function rebuildSqlite(string $table, array $columns, string $onDelete): void
    {
        $keys = Schema::getForeignKeys($table);
        $checks = $this->sqliteChecks($table);

        Schema::table($table, function (Blueprint $blueprint) use ($keys, $columns, $onDelete): void {
            foreach ($keys as $key) {
                $blueprint->dropForeign($key['columns']);
            }
            foreach ($keys as $key) {
                $column = $key['columns'][0];
                $rule = count($key['columns']) === 1 && isset($columns[$column]) ? $onDelete : (string) $key['on_delete'];
                $blueprint->foreign($key['columns'])->references($key['foreign_columns'])->on($key['foreign_table'])->onDelete($rule);
            }
        });

        foreach ($checks as $name => $condition) {
            if (! array_key_exists($name, $this->sqliteChecks($table))) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$condition})");
            }
        }
    }

    /**
     * قیدهای CHECK ِ نام‌دار در متنِ جدول، به ترتیب.
     *
     * @return array<string, string>
     */
    private function sqliteChecks(string $table): array
    {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        $checks = [];
        $offset = 0;

        while (preg_match('/CONSTRAINT\s+"?(chk_\w+)"?\s+CHECK\s*\(/i', $sql, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $i = $start;
            while ($i < strlen($sql) && $depth > 0) {
                $depth += match ($sql[$i]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $i++;
            }
            $checks[$match[1][0]] = substr($sql, $start, $i - $start - 1);
            $offset = $i;
        }

        return $checks;
    }
};
