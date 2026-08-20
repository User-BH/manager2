<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\System\BackupController;
use App\Models\Backup;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Services\Backup\BackupBuilder;
use App\Support\Jalali;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

/**
 * بازیابی از فاجعه: رفت‌وبرگشتِ کاملِ بکاپ (R47).
 *
 * ─── شکافی که اندازه‌گیری شد ────────────────────────────────────────────────
 * ⚠️ ساختِ بکاپ تست داشت. بازیابی **هجده** تست داشت. ولی هیچ‌کدام از آن‌ها
 * فایلی را که **خودِ سامانه ساخته** بازیابی نمی‌کرد: تست‌های بازیابی
 * بارِ ورودی‌شان را دستی می‌ساختند.
 *
 * یعنی دو نیمه‌ی این سازوکار جدا آزموده شده بودند و کسی جفت‌شدنشان را
 * نسنجیده بود. اگر `BackupBuilder` شکلی بنویسد که مسیرِ بازیابی نپذیرد،
 * **هر ۱۹ تست سبز می‌مانند و هر بکاپِ واقعی بی‌ارزش است**.
 *
 * و این بدترین نوعِ خرابیِ ممکن است، چون دقیقاً در لحظه‌ای کشف می‌شود که
 * داده‌ای برای برگشتن به آن نمانده. بکاپی که هرگز بازیابی نشده، بکاپ
 * نیست — یک فایل است.
 */
class DisasterRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ دیسک جعل می‌شود تا تست در storageِ واقعی ننویسد.
         *
         * `ArchitectureTest` این قاعده را اجبار می‌کند و همان بود که
         * نبودنش را در نسخه‌ی اولِ این فایل گرفت: بکاپِ سیستم فایلِ
         * حجیمی است و هر اجرای تست یکی رویش می‌گذاشت.
         *
         * نامِ دیسک از `PrivateFiles` می‌آید نه رشته‌ی ثابت، تا اگر روزی
         * دیسکِ خصوصی عوض شود (R45) این جعل هم با آن برود.
         */
        Storage::fake(PrivateFiles::name());

        $this->superAdmin = User::factory()->create([
            'name' => 'ادمین کل',
            'phone' => '09120000000',
            'role' => UserRole::SuperAdmin,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    /**
     * یک صحنه‌ی واقعی: مجتمع، واحد، ساکن و قبض.
     *
     * @return array{complex: Complex, unit: Unit, resident: User, bill: Bill}
     */
    private function seedRealData(): array
    {
        $complex = Complex::factory()->create(['name' => 'مجتمع آفتاب']);

        $unit = Unit::factory()->create([
            'complex_id' => $complex->id,
            'unit_number' => '۱۲',
        ]);

        $resident = User::factory()->create([
            'complex_id' => $complex->id,
            'name' => 'ساکنِ واحد ۱۲',
            'phone' => '09121111111',
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $bill = Bill::create([
            'complex_id' => $complex->id,
            'unit_id' => $unit->id,
            'period' => Jalali::currentPeriod(),
            'base_amount' => 250000,
            'total_amount' => 250000,
            'paid_amount' => 50000,
            'due_date' => now()->addDays(10),
        ]);

        return compact('complex', 'unit', 'resident', 'bill');
    }

    /** فایلِ بکاپ را دقیقاً همان‌طور که سامانه می‌سازد، تولید می‌کند. */
    private function buildRealBackupFile(): UploadedFile
    {
        $record = Backup::create([
            'complex_id' => null,
            'type' => 'full',
            'status' => 'pending',
            'disk' => PrivateFiles::name(),
            'note' => 'بکاپ آزمونِ بازیابی',
            'created_by' => $this->superAdmin->id,
        ]);

        app(BackupBuilder::class)->fill($record);

        $record->refresh();

        $this->assertSame('completed', $record->status, 'ساختِ بکاپ کامل نشد.');

        /*
         * ⚠️ محتوا از همان دیسکی خوانده می‌شود که سازنده رویش نوشته —
         * نه از مسیرِ حدس‌زده‌شده. اگر روزی دیسک عوض شود (R45)، این تست
         * باید با آن برود، نه اینکه بی‌صدا فایلِ قدیمی را بخواند.
         */
        $contents = PrivateFiles::disk()->get($record->path);

        $this->assertNotEmpty($contents, 'فایلِ بکاپ خالی است.');

        return UploadedFile::fake()->createWithContent('backup.json', $contents);
    }

    private function restore(UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->actingAs($this->superAdmin)->post(
            '/api/system/backups/restore',
            array_merge(['backup' => $file, 'confirm' => 'بازیابی'], $extra),
            ['Accept' => 'application/json'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  رفت‌وبرگشتِ کامل
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهم‌ترین تستِ کلِ پروژه.
     *
     * داده‌ی واقعی ساخته می‌شود، سامانه از آن بکاپ می‌گیرد، داده نابود
     * می‌شود، و همان فایل بازیابی می‌شود. اگر این نگذرد، هیچ‌کدام از ۱۹
     * تستِ دیگرِ بکاپ ارزشی ندارند.
     */
    public function test_a_real_backup_can_actually_be_restored(): void
    {
        $seed = $this->seedRealData();
        $file = $this->buildRealBackupFile();

        // فاجعه: همه‌چیز از بین می‌رود
        DB::table('bills')->delete();
        DB::table('units')->delete();
        DB::table('complexes')->delete();

        $this->assertDatabaseCount('complexes', 0);

        $this->restore($file)->assertOk();

        // و همه‌چیز برمی‌گردد — با همان شناسه و همان مقدار
        $this->assertDatabaseHas('complexes', [
            'id' => $seed['complex']->id,
            'name' => 'مجتمع آفتاب',
        ]);

        $this->assertDatabaseHas('units', [
            'id' => $seed['unit']->id,
            'unit_number' => '۱۲',
        ]);

        $this->assertDatabaseHas('bills', [
            'id' => $seed['bill']->id,
            'total_amount' => 250000,
            'paid_amount' => 50000,
        ]);
    }

    /**
     * ⚠️ رابطه‌ها هم باید سالم برگردند، نه فقط ردیف‌ها.
     *
     * ─── چرا این جدا سنجیده می‌شود ─────────────────────────────────────────
     * بازیابی کلیدهای خارجی را موقتاً خاموش می‌کند تا بتواند جدول‌ها را به
     * هر ترتیبی پر کند. پس ردیف‌ها می‌توانند برگردند در حالی که به
     * والدِ اشتباه — یا به هیچ والدی — اشاره کنند، و دیتابیس هیچ اعتراضی
     * نکند. شمردنِ ردیف‌ها این را نمی‌گیرد.
     */
    public function test_relationships_survive_the_round_trip(): void
    {
        $seed = $this->seedRealData();
        $file = $this->buildRealBackupFile();

        DB::table('bills')->delete();
        DB::table('units')->delete();

        $this->restore($file)->assertOk();

        $bill = Bill::withoutGlobalScopes()->find($seed['bill']->id);

        $this->assertNotNull($bill, 'قبض برنگشت.');
        $this->assertSame($seed['unit']->id, $bill->unit_id, 'قبض به واحدِ دیگری وصل شد.');
        $this->assertSame($seed['complex']->id, $bill->complex_id, 'قبض به مجتمعِ دیگری وصل شد.');

        $unit = Unit::withoutGlobalScopes()->find($seed['unit']->id);

        $this->assertSame($seed['complex']->id, $unit->complex_id);
    }

    /**
     * ⚠️ ساکن باید بتواند پس از بازیابی وارد شود.
     *
     * ─── چرا شمردنِ ردیف کافی نیست ──────────────────────────────────────────
     * جدولِ `users` می‌تواند کامل برگردد ولی هشِ رمز در مسیر خراب شود —
     * مثلاً اگر جایی کدگذاری عوض شود یا ستونی جا بیفتد. آن‌وقت بازیابی
     * «موفق» گزارش می‌شود و **هیچ‌کس نمی‌تواند وارد شود**؛ خرابی‌ای که فقط
     * با تلاشِ واقعیِ ورود دیده می‌شود.
     */
    public function test_a_resident_can_still_log_in_after_the_restore(): void
    {
        $complex = Complex::factory()->create();

        $resident = User::factory()->create([
            'complex_id' => $complex->id,
            'phone' => '09122222222',
            'role' => UserRole::Owner,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $file = $this->buildRealBackupFile();

        DB::table('users')->where('id', $resident->id)->delete();

        $this->restore($file)->assertOk();

        $restored = User::withoutGlobalScopes()->where('phone', '09122222222')->first();

        $this->assertNotNull($restored, 'کاربر برنگشت.');
        $this->assertTrue(
            Hash::check('secret123', $restored->password),
            'هشِ رمز از رفت‌وبرگشت جان سالم به در نبرد؛ کاربر نمی‌تواند وارد شود.',
        );
    }

    /**
     * ⚠️ فایلِ ساخته‌شده باید رمزگذاری‌شده باشد.
     *
     * بکاپِ سیستم هشِ همه‌ی رمزها، کلیدِ درگاهِ بانکی و کلیدِ پیامک را در
     * خود دارد (R20). فایلی که رمز نشده باشد، اگر از سرور بیرون برود عملاً
     * کلِ سامانه است.
     */
    public function test_the_generated_file_is_encrypted_on_disk(): void
    {
        $this->seedRealData();

        $record = Backup::create([
            'complex_id' => null, 'type' => 'full', 'status' => 'pending',
            'disk' => PrivateFiles::name(), 'note' => 'آزمون', 'created_by' => $this->superAdmin->id,
        ]);

        app(BackupBuilder::class)->fill($record);

        $raw = PrivateFiles::disk()->get($record->refresh()->path);

        $this->assertStringNotContainsString(
            'مجتمع آفتاب',
            $raw,
            'نامِ مجتمع به‌صورتِ متنِ ساده در فایل است؛ فایل رمز نشده.',
        );

        $decoded = json_decode($raw, true);

        $this->assertTrue($decoded['encrypted'] ?? false, 'نشانه‌ی رمزگذاری در فایل نیست.');
    }

    /**
     * ⚠️ آزمایشِ خشک باید روی فایلِ واقعی هم کار کند.
     *
     * این تنها راهی است که ادمین می‌تواند **پیش از** نابودکردنِ داده مطمئن
     * شود فایل سالم است. اگر فقط روی بارِ دست‌ساز کار کند و روی فایلِ
     * واقعی نه، دقیقاً وقتی شکست می‌خورد که بیشترین اهمیت را دارد.
     */
    public function test_a_dry_run_accepts_a_real_backup_file(): void
    {
        $this->seedRealData();

        $file = $this->buildRealBackupFile();

        $response = $this->actingAs($this->superAdmin)->post(
            '/api/system/backups/restore',
            ['backup' => $file, 'dry_run' => true],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertTrue($response->json('dryRun'));
        $this->assertGreaterThan(0, $response->json('tables.complexes'));

        // و هیچ چیزی عوض نشده
        $this->assertDatabaseCount('complexes', 1);
    }

    /**
     * ⚠️ بکاپِ ایمنی که پیش از بازیابی گرفته می‌شود، باید خودش قابلِ
     * بازیابی باشد.
     *
     * ─── چرا این مهم‌ترین بکاپِ سامانه است ────────────────────────────────
     * آن فایل **تنها راهِ برگشت از یک بازیابیِ اشتباه** است. اگر خودش
     * ناقص یا غیرقابلِ‌خواندن باشد، ادمینی که فایلِ اشتباه را بازیابی
     * کرده، هیچ راهی برای برگشت ندارد — و دقیقاً در همان لحظه می‌فهمد.
     */
    public function test_the_safety_backup_taken_before_a_restore_is_itself_restorable(): void
    {
        $seed = $this->seedRealData();
        $file = $this->buildRealBackupFile();

        // یک بازیابی انجام می‌شود؛ همان‌جا بکاپِ ایمنی ساخته می‌شود
        $this->restore($file)->assertOk();

        $safety = Backup::withoutGlobalScopes()
            ->where('note', 'like', '%پیش از بازیابی%')
            ->latest('id')
            ->first();

        $this->assertNotNull($safety, 'بکاپِ ایمنی ساخته نشد.');

        $contents = PrivateFiles::disk()->get($safety->path);

        // فاجعه، این بار پس از بازیابی
        DB::table('bills')->delete();
        DB::table('complexes')->delete();

        $this->restore(UploadedFile::fake()->createWithContent('safety.json', $contents))->assertOk();

        $this->assertDatabaseHas('complexes', ['id' => $seed['complex']->id]);
        $this->assertDatabaseHas('bills', ['id' => $seed['bill']->id]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  واگراییِ دو فهرستِ جدول
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ دو نسخه‌ی مستقل از فهرستِ جدول‌ها وجود دارد و باید یکی بمانند.
     *
     * ─── خطری که این می‌گیرد ────────────────────────────────────────────────
     * `BackupBuilder::SYSTEM_TABLES` بکاپ‌های عادی و زمان‌بندی‌شده را
     * می‌سازد؛ `BackupController::BACKUP_TABLES` بکاپِ **ایمنیِ پیش از
     * بازیابی** را.
     *
     * اگر کسی جدولی به یکی اضافه کند و دیگری را فراموش، همه‌چیز سالم به
     * نظر می‌رسد — تا روزی که بازیابیِ اشتباهی انجام شود و معلوم شود
     * بکاپِ ایمنی، که تنها راهِ برگشت است، آن جدول را ندارد.
     */
    public function test_the_two_table_lists_do_not_drift_apart(): void
    {
        $controller = new ReflectionClass(BackupController::class);

        $backupTables = $controller->getConstant('BACKUP_TABLES');
        $restoreTables = $controller->getConstant('RESTORE_TABLES');

        $this->assertSame(
            BackupBuilder::SYSTEM_TABLES,
            $backupTables,
            'فهرستِ جدول‌های بکاپِ ایمنی با فهرستِ بکاپِ عادی فرق دارد؛ '
            .'یکی از دو مسیر جدولی را از دست می‌دهد.',
        );

        /*
         * ⚠️ `audit_logs` عمداً بازیابی نمی‌شود و این **باگ نیست**: اگر
         * بازیابی سیاهه‌ی ممیزی را هم پاک کند، هرکس می‌تواند با یک
         * بازیابی ردِ پای خودش را بشوید. پس تنها اختلافِ مجاز همین است و
         * صریح سنجیده می‌شود تا اختلافِ تازه‌ای پنهان نشود.
         */
        $this->assertSame(
            ['audit_logs'],
            array_values(array_diff($backupTables, $restoreTables)),
            'اختلافِ فهرستِ بکاپ و بازیابی چیزی جز audit_logs است.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($restoreTables, $backupTables)),
            'بازیابی جدولی را انتظار دارد که هرگز بکاپ نمی‌شود.',
        );
    }

    /**
     * ⚠️ هر جدولی که بکاپ می‌شود باید واقعاً در دیتابیس باشد.
     *
     * نامِ جدولی که حذف یا تغییرِ نام داده، بی‌صدا از بکاپ می‌افتد:
     * `Schema::hasTable()` رد می‌کندش و هیچ خطایی نمی‌دهد. نتیجه بکاپی
     * است که ناقص است و کسی نمی‌فهمد.
     */
    public function test_every_backed_up_table_exists(): void
    {
        foreach (BackupBuilder::SYSTEM_TABLES as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "جدولِ «{$table}» در فهرستِ بکاپ هست ولی در دیتابیس نیست.",
            );
        }
    }
}
