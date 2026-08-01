<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * یکپارچگیِ schema (R14).
 *
 * این‌ها قیدهایی‌اند که در سطحِ دیتابیس اعمال می‌شوند و شکستنشان یعنی داده‌ی
 * خراب یا از‌دست‌رفته — نه فقط یک خطای نمایشی.
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع اسکیما', 'slug' => 'schema-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);
    }

    /* ── تاریخچه‌ی مالی ─────────────────────────────────────────────────── */

    /**
     * حذفِ واحد نباید تاریخچه‌ی مالی‌اش را نابود کند.
     *
     * پیش از R14، `bills.unit_id` و `payments.unit_id` هر دو `cascadeOnDelete`
     * بودند؛ یعنی حذفِ یک واحد **همه‌ی قبض‌ها و پرداخت‌هایش را برای همیشه
     * پاک می‌کرد**. برای سامانه‌ای که پول جابه‌جا می‌کند، این یعنی نبودِ
     * سابقه‌ی حسابداری برای واحدی که فروخته یا تخلیه شده.
     */
    public function test_deleting_a_unit_keeps_its_financial_history(): void
    {
        [$unit, $bill, $payment] = $this->makeUnitWithHistory();

        $unit->delete();

        $this->assertTrue(
            Bill::withoutGlobalScopes()->whereKey($bill->id)->exists(),
            'قبضِ واحدِ حذف‌شده نباید پاک شود',
        );
        $this->assertTrue(
            Payment::withoutGlobalScopes()->whereKey($payment->id)->exists(),
            'پرداختِ واحدِ حذف‌شده نباید پاک شود',
        );
    }

    public function test_a_deleted_unit_disappears_from_normal_queries(): void
    {
        [$unit] = $this->makeUnitWithHistory();

        $unit->delete();

        // برای کاربر «حذف شده» است، حتی اگر ردیفش برای حسابداری بماند
        $this->assertNull(Unit::find($unit->id));
        $this->assertNotNull(Unit::withTrashed()->find($unit->id));
    }

    public function test_a_deleted_unit_can_be_restored_with_its_history(): void
    {
        [$unit, $bill] = $this->makeUnitWithHistory();

        $unit->delete();
        Unit::withTrashed()->find($unit->id)->restore();

        $this->assertNotNull(Unit::find($unit->id));
        $this->assertTrue(Bill::whereKey($bill->id)->exists());
    }

    /* ── قیدهای یکتایی ─────────────────────────────────────────────────── */

    public function test_a_unit_number_cannot_repeat_inside_one_complex(): void
    {
        $this->makeUnit('۵');

        $this->expectException(QueryException::class);
        $this->makeUnit('۵');
    }

    public function test_the_same_unit_number_is_allowed_in_another_complex(): void
    {
        $this->makeUnit('۵');

        $other = Complex::create([
            'name' => 'مجتمع دوم', 'slug' => 'schema2-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        // شماره‌ی واحد فقط درونِ یک مجتمع یکتاست، نه در کلِ سامانه
        $unit = Unit::create([
            'complex_id' => $other->id, 'unit_number' => '۵', 'floor' => 1, 'area' => 80,
        ]);

        $this->assertNotNull($unit->id);
    }

    public function test_a_unit_cannot_have_two_bills_for_the_same_period(): void
    {
        $unit = $this->makeUnit('۹');

        Bill::create([
            'complex_id' => $this->complex->id, 'unit_id' => $unit->id,
            'period' => '1405-02', 'total_amount' => 100, 'status' => 'unpaid',
            'due_date' => now()->addDays(5),
        ]);

        // دو قبض برای یک دوره یعنی ساکن دوبار بدهکار می‌شود
        $this->expectException(QueryException::class);
        Bill::create([
            'complex_id' => $this->complex->id, 'unit_id' => $unit->id,
            'period' => '1405-02', 'total_amount' => 200, 'status' => 'unpaid',
            'due_date' => now()->addDays(5),
        ]);
    }

    /* ── نوعِ داده‌ی پول ────────────────────────────────────────────────── */

    public function test_no_money_column_is_stored_as_a_float(): void
    {
        $offenders = [];

        foreach (File::files(database_path('migrations')) as $file) {
            $contents = File::get($file->getPathname());

            preg_match_all(
                '/->(float|double)\(\s*[\'"]([a-z_]*(amount|total|balance|price|value|fee|cost)[a-z_]*)[\'"]/i',
                $contents,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $offenders[] = $file->getFilename().' → '.$match[2].' ('.$match[1].')';
            }
        }

        /*
         * float برای پول یعنی خطای گردکردن: 0.1 + 0.2 در ممیزِ شناور دقیقاً
         * 0.3 نمی‌شود، و در یک سامانه‌ی مالی این تفاوت‌ها روی هم انباشته
         * می‌شوند تا ترازها بخوانند.
         */
        $this->assertSame([], $offenders, 'ستون پولی با نوع اعشاریِ شناور: '.implode('، ', $offenders));
    }

    /* ── قابلیت بازگشت مهاجرت‌ها ───────────────────────────────────────── */

    public function test_every_migration_can_be_rolled_back(): void
    {
        $missing = [];

        foreach (File::files(database_path('migrations')) as $file) {
            if (! str_contains(File::get($file->getPathname()), 'public function down')) {
                $missing[] = $file->getFilename();
            }
        }

        // مهاجرتِ بدونِ down یعنی استقرارِ خراب راهِ برگشت ندارد
        $this->assertSame([], $missing, 'مهاجرت بدون down(): '.implode('، ', $missing));
    }

    /* ── زمان ──────────────────────────────────────────────────────────── */

    public function test_timestamps_are_stored_in_utc(): void
    {
        /*
         * ذخیره باید UTC باشد و تبدیل به شمسی فقط هنگام نمایش. اگر ستون‌ها
         * با وقتِ محلی پر شوند، تغییرِ منطقه‌ی زمانیِ سرور همه‌ی تاریخ‌های
         * گذشته را جابه‌جا می‌کند.
         */
        $this->assertSame('UTC', config('app.timezone'));
    }

    /* ── ایندکسِ مسیرهای داغ ────────────────────────────────────────────── */

    public function test_hot_query_paths_are_indexed(): void
    {
        $expected = [
            'bills' => ['complex_id', 'period'],
            'payments' => ['complex_id', 'status'],
            'expenses' => ['complex_id', 'period'],
            'incomes' => ['complex_id', 'period'],
            'users' => ['complex_id', 'role'],
        ];

        $missing = [];

        foreach ($expected as $table => $columns) {
            if (! $this->hasIndexCovering($table, $columns)) {
                $missing[] = $table.'('.implode(', ', $columns).')';
            }
        }

        $this->assertSame([], $missing, 'ایندکسِ ترکیبیِ لازم وجود ندارد: '.implode('، ', $missing));
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexCovering(string $table, array $columns): bool
    {
        // SQLite در تست؛ نامِ ستون‌های هر ایندکس را از خودِ موتور می‌پرسیم
        $indexes = DB::select("PRAGMA index_list('{$table}')");

        foreach ($indexes as $index) {
            $info = DB::select("PRAGMA index_info('{$index->name}')");
            $indexed = array_map(fn ($row) => $row->name, $info);

            if (array_slice($indexed, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function makeUnit(string $number): Unit
    {
        return Unit::create([
            'complex_id' => $this->complex->id,
            'unit_number' => $number,
            'floor' => 1,
            'area' => 80,
        ]);
    }

    /**
     * @return array{0: Unit, 1: Bill, 2: Payment}
     */
    private function makeUnitWithHistory(): array
    {
        $unit = $this->makeUnit('۱');

        $resident = User::factory()->create([
            'role' => UserRole::Owner,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);

        $bill = Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $unit->id,
            'period' => '1405-01',
            'total_amount' => 500000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
        ]);

        $payment = Payment::create([
            'complex_id' => $this->complex->id,
            'bill_id' => $bill->id,
            'unit_id' => $unit->id,
            'user_id' => $resident->id,
            'amount' => 500000,
            'method' => 'receipt',
            'status' => PaymentStatus::Success,
        ]);

        return [$unit, $bill, $payment];
    }
}
