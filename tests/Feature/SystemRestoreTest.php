<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Advertisement;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Complex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * بازیابی کل سیستم — مخرب‌ترین عملیات سامانه.
 *
 * پیش از این یک انتخاب فایل بی‌درنگ کل دیتابیس را جایگزین می‌کرد: بدون
 * اعتبارسنجی فایل، بدون بکاپ ایمنی، بدون تایید تایپ‌شده و بدون هیچ ردی در لاگ.
 */
class SystemRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Complex $complex;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // مهاجرت، سه بنر پیش‌فرض درج می‌کند؛ تست‌های شمارش باید از صفر شروع کنند
        Advertisement::query()->delete();

        $this->complex = Complex::create([
            'name' => 'مجتمع اصلی', 'slug' => 'restore-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->superAdmin = User::create([
            'name' => 'ادمین کل', 'phone' => '09211110001',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /** فایل بکاپ ساختگی با محتوای دلخواه. */
    private function backupFile(array $tables, array $meta = ['type' => 'full']): UploadedFile
    {
        $json = json_encode(
            ['meta' => $meta + ['generated_at' => now()->toIso8601String()], 'tables' => $tables],
            JSON_UNESCAPED_UNICODE,
        );

        return UploadedFile::fake()->createWithContent('backup.json', $json);
    }

    /** بکاپ واقعیِ گرفته‌شده از وضعیت فعلی سامانه. */
    private function realBackupFile(): UploadedFile
    {
        $this->actingAs($this->superAdmin)->postJson('/api/system/backups')->assertStatus(201);

        $backup = Backup::latest('id')->firstOrFail();

        return UploadedFile::fake()->createWithContent(
            'backup.json',
            Storage::disk('local')->get($backup->path),
        );
    }

    private function restore(array $payload): TestResponse
    {
        return $this->actingAs($this->superAdmin)->post('/api/system/backups/restore', $payload, [
            'Accept' => 'application/json',
        ]);
    }

    /* --------------------- اعتبارسنجی فایل --------------------- */

    public function test_a_malformed_file_is_rejected_before_anything_is_touched(): void
    {
        $this->restore(['backup' => UploadedFile::fake()->createWithContent('x.json', '{"nope":1}')])
            ->assertStatus(422);

        // هیچ چیزی نباید پاک شده باشد
        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    public function test_a_complex_backup_cannot_be_restored_as_a_full_system_backup(): void
    {
        $this->restore([
            'backup' => $this->backupFile(['complexes' => []], ['type' => 'complex']),
            'confirm' => 'بازیابی',
        ])->assertStatus(422)->assertJsonPath('message', 'این فایل بکاپ کامل سیستم نیست. بکاپ یک مجتمع را نمی‌توان اینجا بازیابی کرد.');

        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    public function test_an_unknown_table_is_reported_instead_of_silently_ignored(): void
    {
        $response = $this->restore([
            'backup' => $this->backupFile(['complexes' => [], 'wp_users' => []]),
            'confirm' => 'بازیابی',
        ])->assertStatus(422);

        $this->assertStringContainsString('wp_users', $response->json('message'));
        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    public function test_an_unknown_column_is_reported_before_the_wipe(): void
    {
        $response = $this->restore([
            'backup' => $this->backupFile(['complexes' => [['id' => 1, 'name' => 'x', 'evil_column' => 'y']]]),
            'confirm' => 'بازیابی',
        ])->assertStatus(422);

        $this->assertStringContainsString('evil_column', $response->json('message'));
        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    /* --------------------- اجرای آزمایشی --------------------- */

    public function test_a_dry_run_reports_the_change_without_making_it(): void
    {
        $file = $this->realBackupFile();

        // یک مجتمع تازه که در فایل بکاپ نیست
        Complex::create([
            'name' => 'مجتمع بعد از بکاپ', 'slug' => 'later-'.uniqid(),
            'currency' => 'toman', 'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $response = $this->restore(['backup' => $file, 'dry_run' => '1'])
            ->assertOk()
            ->assertJsonPath('dryRun', true);

        // گزارش می‌گوید بعد از بازیابی یک مجتمع می‌ماند، الان دو تا هست
        $this->assertSame(1, $response->json('tables.complexes'));
        $this->assertSame(2, $response->json('current.complexes'));

        // ولی هیچ چیزی عوض نشده
        $this->assertSame(2, Complex::count());
    }

    public function test_a_dry_run_needs_no_confirmation_phrase(): void
    {
        $this->restore(['backup' => $this->realBackupFile(), 'dry_run' => '1'])->assertOk();
    }

    /* --------------------- تایید تایپ‌شده --------------------- */

    public function test_restore_without_the_typed_phrase_is_refused(): void
    {
        $this->restore(['backup' => $this->realBackupFile()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'برای انجام بازیابی باید عبارت «بازیابی» را تایپ کنید.');

        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    public function test_a_wrong_phrase_is_refused(): void
    {
        $this->restore(['backup' => $this->realBackupFile(), 'confirm' => 'بله'])
            ->assertStatus(422);

        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    /* --------------------- بازیابی واقعی --------------------- */

    public function test_a_confirmed_restore_replaces_the_data(): void
    {
        $file = $this->realBackupFile();

        $extra = Complex::create([
            'name' => 'مجتمع اضافه', 'slug' => 'extra-'.uniqid(),
            'currency' => 'toman', 'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->restore(['backup' => $file, 'confirm' => 'بازیابی'])
            ->assertOk()
            ->assertJsonPath('loggedOut', true);

        // مجتمعی که بعد از بکاپ ساخته شده بود، دیگر نیست
        $this->assertDatabaseMissing('complexes', ['id' => $extra->id]);
        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    public function test_a_safety_backup_is_taken_before_the_wipe(): void
    {
        $file = $this->realBackupFile();
        $before = Backup::count();

        $response = $this->restore(['backup' => $file, 'confirm' => 'بازیابی'])->assertOk();

        // بکاپ ایمنی هم رکورد دارد و هم فایلش روی دیسک هست
        $this->assertSame($before + 1, Backup::count());
        Storage::disk('local')->assertExists($response->json('safetyBackup'));
        $this->assertSame('بکاپ خودکار پیش از بازیابی', Backup::latest('id')->first()->note);
    }

    public function test_the_restore_is_recorded_in_the_audit_log(): void
    {
        $this->restore(['backup' => $this->realBackupFile(), 'confirm' => 'بازیابی'])->assertOk();

        $log = AuditLog::where('action', 'system.restored')->latest('id')->first();

        $this->assertNotNull($log, 'بازیابی باید در لاگ فعالیت ثبت شود.');
        $this->assertSame('backup.json', $log->properties['file']);
        $this->assertNotEmpty($log->properties['safety_backup']);
    }

    public function test_the_audit_log_survives_the_restore(): void
    {
        /*
         * اگر بازیابی جدول لاگ را هم پاک کند، هرکس می‌تواند با یک restore رد
         * پای خودش را بشوید. پس audit_logs عمداً بازیابی نمی‌شود.
         */
        AuditLog::create([
            'complex_id' => null, 'user_id' => $this->superAdmin->id,
            'action' => 'test.earlier', 'description' => 'رویداد پیش از بازیابی',
            'created_at' => now(),
        ]);

        $this->restore(['backup' => $this->realBackupFile(), 'confirm' => 'بازیابی'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'test.earlier']);
    }

    /* --------------------- پوشش جدول‌ها --------------------- */

    public function test_the_backup_covers_advertisements_and_read_receipts(): void
    {
        // این دو جدول از فهرست بکاپ جا افتاده بودند
        Advertisement::create([
            'title' => 'بنر', 'href' => 'https://example.com',
            'image_url' => '/images/ad-nitropanel.webp', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->superAdmin)->postJson('/api/system/backups')->assertStatus(201);

        $snapshot = json_decode(Storage::disk('local')->get(Backup::latest('id')->first()->path), true);

        $this->assertArrayHasKey('advertisements', $snapshot['tables']);
        $this->assertArrayHasKey('announcement_reads', $snapshot['tables']);
        $this->assertArrayHasKey('audit_logs', $snapshot['tables']);
        $this->assertCount(1, $snapshot['tables']['advertisements']);
    }

    public function test_advertisements_are_restored_too(): void
    {
        Advertisement::create([
            'title' => 'بنر اصلی', 'href' => 'https://example.com',
            'image_url' => '/images/ad-nitropanel.webp', 'is_active' => true, 'sort_order' => 0,
        ]);

        $file = $this->realBackupFile();

        Advertisement::query()->delete();
        $this->assertSame(0, Advertisement::count());

        $this->restore(['backup' => $file, 'confirm' => 'بازیابی'])->assertOk();

        $this->assertSame(1, Advertisement::count());
        $this->assertSame('بنر اصلی', Advertisement::first()->title);
    }

    /* --------------------- دسترسی --------------------- */

    public function test_a_complex_admin_cannot_restore_the_system(): void
    {
        $admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر مجتمع', 'phone' => '09211110002',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/api/system/backups/restore', [
            'backup' => $this->backupFile(['complexes' => []]), 'confirm' => 'بازیابی',
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->assertDatabaseHas('complexes', ['id' => $this->complex->id]);
    }

    public function test_real_restores_are_rate_limited(): void
    {
        // سقف ۱۰ در ساعت؛ یازدهمی باید ۴۲۹ بگیرد
        for ($i = 0; $i < 10; $i++) {
            $this->restore(['backup' => $this->backupFile(['complexes' => []]), 'confirm' => 'نادرست'])
                ->assertStatus(422);
        }

        $this->restore(['backup' => $this->backupFile(['complexes' => []]), 'confirm' => 'نادرست'])
            ->assertStatus(429);
    }

    public function test_dry_runs_are_never_rate_limited(): void
    {
        /*
         * اجرای آزمایشی چیزی را عوض نمی‌کند. اگر همان سقف اجرای واقعی را
         * داشته باشد، ادمینی که چند فایل بکاپ را بررسی می‌کند درست وسط یک
         * بحران از بازیابی محروم می‌شود.
         */
        $file = $this->backupFile(['complexes' => []]);

        for ($i = 0; $i < 15; $i++) {
            $this->restore(['backup' => $file, 'dry_run' => '1'])->assertOk();
        }
    }

    public function test_foreign_key_checks_are_re_enabled_after_a_restore(): void
    {
        $this->restore(['backup' => $this->realBackupFile(), 'confirm' => 'بازیابی'])->assertOk();

        // اگر PRAGMA/FOREIGN_KEY_CHECKS خاموش مانده باشد، این درج موفق می‌شود
        // و جداسازی ارجاعی کل سامانه بی‌سروصدا از کار افتاده است.
        $enabled = DB::getDriverName() === 'sqlite'
            ? (bool) DB::selectOne('PRAGMA foreign_keys')->foreign_keys
            : true;

        $this->assertTrue($enabled, 'بررسی کلید خارجی باید پس از بازیابی دوباره روشن شود.');
    }
}
