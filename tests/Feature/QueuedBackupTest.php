<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\BuildBackupJob;
use App\Models\Backup;
use App\Models\Complex;
use App\Models\User;
use App\Services\Backup\BackupBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * ساختِ بکاپ پس از انتقال به صف.
 *
 * قیدِ اصلی: کاربر **بلافاصله** پاسخ می‌گیرد و ردیفِ بکاپ را می‌بیند، حتی
 * پیش از آنکه فایل ساخته شود. پیش از R11 درخواست تا پایانِ ساختِ فایل باز
 * می‌ماند و روی داده‌ی واقعی به سقفِ زمان یا حافظه می‌خورد.
 */
class QueuedBackupTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->complex = Complex::create([
            'name' => 'مجتمع صف', 'slug' => 'queue-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);
        $this->admin = User::factory()->create([
            'role' => UserRole::ComplexAdmin,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);
        $this->superAdmin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_request_returns_immediately_and_queues_the_work(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/backups');

        // ۲۰۲ یعنی «پذیرفته شد» نه «تمام شد»
        $response->assertStatus(202)->assertJsonPath('backup.status', 'pending');

        Queue::assertPushed(BuildBackupJob::class);
    }

    public function test_the_row_exists_before_the_file_does(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->postJson('/api/v1/backups')->assertStatus(202);

        $backup = Backup::first();

        // کاربر باید ردیف را ببیند، وگرنه دکمه را می‌زند و هیچ اتفاقی نمی‌بیند
        $this->assertSame('pending', $backup->status);
        $this->assertNull($backup->path);
    }

    public function test_running_the_job_produces_the_file_and_completes_the_row(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/backups')->assertStatus(202);

        $backup = Backup::first();
        (new BuildBackupJob($backup->id))->handle(new BackupBuilder);

        $backup->refresh();
        $this->assertSame('completed', $backup->status);
        $this->assertNotNull($backup->path);
        Storage::disk('local')->assertExists($backup->path);
        $this->assertGreaterThan(0, $backup->size);
    }

    public function test_the_system_backup_contains_every_expected_table(): void
    {
        $this->actingAs($this->superAdmin)->postJson('/api/v1/system/backups')->assertStatus(202);

        $backup = Backup::first();
        (new BuildBackupJob($backup->id))->handle(new BackupBuilder);

        $contents = json_decode(Storage::disk('local')->get($backup->refresh()->path), true);

        // اگر جدولی از فهرست بیفتد، بازیابی ناقص می‌شود و کسی نمی‌فهمد
        foreach (BackupBuilder::SYSTEM_TABLES as $table) {
            $this->assertArrayHasKey($table, $contents['tables'], "جدول {$table} در بکاپ نیست");
        }
    }

    public function test_complex_backup_never_contains_password_hashes(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/backups')->assertStatus(202);

        $backup = Backup::first();
        (new BuildBackupJob($backup->id))->handle(new BackupBuilder);

        $raw = Storage::disk('local')->get($backup->refresh()->path);

        // فایلِ بکاپ دانلود می‌شود؛ هشِ رمز نباید از سیستم بیرون برود
        $this->assertStringNotContainsString('"password"', $raw);
    }

    public function test_a_failed_job_marks_the_row_failed_instead_of_deleting_it(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/backups')->assertStatus(202);
        $backup = Backup::first();

        (new BuildBackupJob($backup->id))->failed(new RuntimeException('دیسک پر است'));

        /*
         * ردیف باید بماند: حذفش یعنی کاربر دکمه را زده، ردیفی دیده، و بعد
         * بی‌هیچ توضیحی ناپدید شده.
         */
        $this->assertSame('failed', $backup->refresh()->status);
        $this->assertTrue(Backup::whereKey($backup->id)->exists());
    }

    public function test_a_deleted_backup_does_not_crash_the_job(): void
    {
        $job = new BuildBackupJob(999999);

        // رکورد بین صف‌شدن و اجرا حذف شده — نباید کلِ کارگر را بترکاند
        $job->handle(new BackupBuilder);

        $this->assertSame(0, Backup::count());
    }

    public function test_an_already_completed_backup_is_not_rebuilt(): void
    {
        $backup = Backup::create([
            'complex_id' => $this->complex->id,
            'type' => 'complex',
            'status' => 'completed',
            'disk' => 'local',
            'path' => 'backups/already-there.json',
            'size' => 10,
        ]);

        (new BuildBackupJob($backup->id))->handle(new BackupBuilder);

        // تلاشِ دوباره‌ی صف نباید فایلِ سالم را با نسخه‌ی تازه عوض کند
        $this->assertSame('backups/already-there.json', $backup->refresh()->path);
    }

    /**
     * مهلتِ نهایی باید در **آینده** باشد.
     *
     * این تست از یک باگِ واقعی آمده: ویژگی را `retryUntil` نام گذاشته بودم و
     * لاراول مقدارش (۳۶۰۰) را timestamp فرض کرد، یعنی سالِ ۱۹۷۰. هر Job پیش
     * از اولین اجرا با «attempted too many times» شکست می‌خورد و `handle()`
     * هرگز صدا زده نمی‌شد.
     *
     * صفِ `sync` این را نمی‌گیرد (چون اصلاً مهلت را نگاه نمی‌کند)؛ فقط با
     * کارگرِ واقعی دیده شد. این تست همان قید را بدونِ کارگر نگه می‌دارد.
     */
    public function test_retry_deadline_is_in_the_future(): void
    {
        $job = new BuildBackupJob(1);

        $this->assertTrue(
            $job->retryUntil() > now(),
            'مهلتِ نهایی در گذشته است؛ کارگر Jobها را پیش از اجرا شکست‌خورده اعلام می‌کند.',
        );
    }

    public function test_job_has_bounded_retries_and_timeout(): void
    {
        $job = new BuildBackupJob(1);

        // بدون سقف، یک Jobِ گیرکرده کارگر را برای همیشه اشغال می‌کند
        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff);
        $this->assertGreaterThan(0, $job->timeout);
    }
}
