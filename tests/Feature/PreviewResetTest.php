<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

/**
 * ساختنِ پایگاه‌داده‌ی Preview **واقعاً** — در پردازه‌ی جدا با `APP_ENV=preview` و
 * پایگاه‌داده‌ی فایلی، همان‌طور که روی سرور اجرا می‌شود (مرحلهٔ ۹ · فاز ۸).
 *
 * درونِ PHPUnit `migrate:fresh` روی پایگاه‌داده‌ی آزمون معنا ندارد؛ این‌جا دستور با
 * محیطِ خودش اجرا و نتیجه مستقیم از فایلِ پایگاه‌داده خوانده می‌شود.
 */
class PreviewResetTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sakena-preview-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /** پایگاه‌داده‌ی خالی ← ساخته، برچسب‌خورده، با چهار حسابِ آزمایشی؛ اجرای دوباره هم مجاز. */
    public function test_reset_builds_a_marked_database_with_the_four_test_accounts(): void
    {
        $run = $this->artisan_('preview');
        $this->assertTrue($run->successful(), $run->output().$run->errorOutput());

        $db = $this->pdo();
        $this->assertSame('preview', $db->query("select value from settings where complex_id is null and key = 'database_role'")->fetchColumn());

        $roles = $db->query("select phone, role, complex_id from users where phone in ('09120000001','09120000002','09120000000','09121111101')")
            ->fetchAll(PDO::FETCH_ASSOC);
        $byPhone = array_column($roles, null, 'phone');
        $this->assertSame('super_admin', $byPhone['09120000001']['role']);
        $this->assertNull($byPhone['09120000002']['complex_id'], 'حسابِ ثبت‌نامِ اولیه نباید مجتمع داشته باشد.');
        $this->assertSame('complex_admin', $byPhone['09120000000']['role']);
        $this->assertNotNull($byPhone['09121111101']['complex_id'], 'حسابِ واحد مجتمع ندارد.');

        // اجرای دوباره روی پایگاه‌داده‌ی برچسب‌دار: مجاز، و باز همان چهار حساب
        $again = $this->artisan_('preview');
        $this->assertTrue($again->successful(), $again->errorOutput());
        $this->assertSame(4, (int) $this->pdo()->query("select count(*) from users where phone in ('09120000001','09120000002','09120000000','09121111101')")->fetchColumn());
    }

    /** پایگاه‌داده‌ی بی‌برچسبی که کاربر دارد (شاید محصول) دست نمی‌خورد. */
    public function test_reset_never_wipes_an_unmarked_database_with_users(): void
    {
        $this->assertTrue($this->process(['migrate', '--force'], 'local')->successful());
        $this->pdo()->exec("insert into users (name, phone, password, role, is_active, created_at, updated_at) values ('کاربرِ واقعی', '09129999999', 'x', 'owner', 1, datetime('now'), datetime('now'))");

        $run = $this->artisan_('preview');
        $this->assertFalse($run->successful(), 'پاک‌سازی روی پایگاه‌داده‌ی دارای کاربر اجرا شد.');
        $this->assertSame(1, (int) $this->pdo()->query("select count(*) from users where phone = '09129999999'")->fetchColumn());
    }

    /** روی محصول اجرا نمی‌شود — حتی با `--force`. */
    public function test_reset_refuses_on_production(): void
    {
        $run = $this->artisan_('production');

        $this->assertFalse($run->successful());
        $this->assertSame(0, filesize($this->file), 'روی محصول چیزی نوشته شد.');
    }

    private function artisan_(string $env): ProcessResult
    {
        return $this->process(['preview:reset', '--force', '--no-interaction'], $env);
    }

    /** @param  list<string>  $arguments */
    private function process(array $arguments, string $env): ProcessResult
    {
        return Process::path(base_path())->timeout(300)->env([
            'APP_ENV' => $env,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->file,
            'CACHE_STORE' => 'array',
            'CACHE_PREFIX' => 'sakena_preview_',
            'SESSION_COOKIE' => 'sakena_preview_session',
            'PREVIEW_ACCESS_KEY' => 'key-for-the-test',
            'QUEUE_CONNECTION' => 'sync',
        ])->run([PHP_BINARY, 'artisan', ...$arguments]);
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite:'.$this->file);
    }
}
