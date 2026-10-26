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

    /**
     * پایگاه‌داده‌ی خالی ← ساخته، برچسب‌خورده، با چهار حسابِ آزمایشیِ «حفره ها۲» (۲۰۲۶-۱۰-۲۶): ثبت‌نامِ اولیه،
     * تک‌مجتمعی، چندمجتمعی و سوپر ادمینِ آزمایشی؛ نام کاربری، بی شماره، نشانگرِ آزمایشی؛ رمزِ هر حساب فقط یک بار در
     * خروجی. اجرای دوباره هم مجاز.
     */
    public function test_reset_builds_a_marked_database_with_the_four_test_accounts(): void
    {
        $run = $this->artisan_('preview');
        $this->assertTrue($run->successful(), $run->output().$run->errorOutput());

        $db = $this->pdo();
        $this->assertSame('preview', $db->query("select value from settings where complex_id is null and key = 'database_role'")->fetchColumn());

        $accounts = array_column($db->query('select id, username, phone, role, complex_id, is_demo from users where username is not null')
            ->fetchAll(PDO::FETCH_ASSOC), null, 'username');
        $this->assertSame(['demo.admin', 'demo.initial', 'demo.multi', 'demo.single'], array_keys($this->sorted($accounts)));
        $this->assertSame('super_admin', $accounts['demo.admin']['role']);
        $this->assertSame('complex_admin', $accounts['demo.multi']['role']);
        $this->assertSame('owner', $accounts['demo.single']['role']);
        $this->assertSame('owner', $accounts['demo.initial']['role']);
        $this->assertNull($accounts['demo.initial']['complex_id'], 'حسابِ اولیه مجتمع گرفت.');
        foreach ($accounts as $username => $row) {
            $this->assertNull($row['phone'], "حسابِ آزمایشیِ {$username} شماره‌ی موبایل دارد.");
            $this->assertSame(1, (int) $row['is_demo'], "حسابِ {$username} نشانگرِ آزمایشی ندارد.");
            $this->assertStringContainsString($username, $run->output(), "رمزِ {$username} یک بار چاپ نشد.");
        }

        $memberships = fn (string $username): int => (int) $db->query("select count(*) from complex_memberships where status = 'active' and user_id = ".(int) $accounts[$username]['id'])->fetchColumn();
        $this->assertSame(5, $memberships('demo.multi'), 'حسابِ چندمجتمعی باید پنج عضویتِ مستقل داشته باشد.');
        $this->assertSame(1, $memberships('demo.single'), 'حسابِ تک‌مجتمعی باید دقیقاً یک عضویت داشته باشد.');
        $this->assertSame(0, $memberships('demo.initial'), 'حسابِ اولیه نباید عضویت داشته باشد.');
        $this->assertSame(0, (int) $db->query('select count(*) from users where is_demo = 0')->fetchColumn(), 'حسابِ بی‌نشانگر در Preview ساخته شد.');

        // اجرای دوباره روی پایگاه‌داده‌ی برچسب‌دار: مجاز، و باز همان چهار حساب
        $again = $this->artisan_('preview');
        $this->assertTrue($again->successful(), $again->errorOutput());
        $this->assertSame(4, (int) $this->pdo()->query('select count(*) from users where username is not null')->fetchColumn());
    }

    /**
     * `preview:seed` چیزی پاک نمی‌کند و رمزِ تغییرکرده را برنمی‌گرداند («داشبورد های آزمایشی» بندِ ۶.۳)؛ چیزِ تکراری
     * هم نمی‌سازد.
     */
    public function test_seeding_again_keeps_changed_passwords_and_creates_no_duplicates(): void
    {
        $this->assertTrue($this->artisan_('preview')->successful());

        $db = $this->pdo();
        $db->exec("update users set password = 'changed-by-the-reviewer' where username = 'demo.single'");
        $counts = fn (): array => [
            (int) $this->pdo()->query('select count(*) from users')->fetchColumn(),
            (int) $this->pdo()->query('select count(*) from complexes')->fetchColumn(),
            (int) $this->pdo()->query('select count(*) from complex_memberships')->fetchColumn(),
            (int) $this->pdo()->query('select count(*) from bills')->fetchColumn(),
        ];
        $before = $counts();

        $seed = $this->process(['preview:seed', '--no-interaction'], 'preview');
        $this->assertTrue($seed->successful(), $seed->output().$seed->errorOutput());

        $this->assertSame('changed-by-the-reviewer', $this->pdo()->query("select password from users where username = 'demo.single'")->fetchColumn());
        $this->assertSame($before, $counts(), 'اجرای دوباره چیزِ تکراری ساخت.');
        $this->assertStringNotContainsString('demo.single', $seed->output(), 'رمزِ حسابِ موجود دوباره چاپ شد.');
    }

    /**
     * «حفره ها۲» بندهای ۴.۱ و ۴.۶، روی پردازه‌ی واقعی: `demo.initial` ِ غیرفعال‌مانده (ساختارِ ۲۰۲۶-۱۰-۲۵) را
     * `preview:seed` خودبه‌خود فعال نمی‌کند و فقط راهِ صریح را می‌گوید؛ `preview:reset-account` همان یک حساب را برمی‌گرداند،
     * رمزِ تغییرکرده را نگه می‌دارد و به حساب‌های دیگر دست نمی‌زند. بیرون از Preview و برای نامِ ناشناخته رد.
     */
    public function test_one_account_is_restored_explicitly_and_keeps_its_changed_password(): void
    {
        $this->assertTrue($this->artisan_('preview')->successful());
        $db = $this->pdo();
        $db->exec("update users set is_active = 0, password = 'changed-by-the-reviewer' where username = 'demo.initial'");
        $others = fn (): array => $this->pdo()->query("select username, password, is_active, complex_id from users where username in ('demo.single', 'demo.multi', 'demo.admin') order by username")->fetchAll(PDO::FETCH_ASSOC);
        $before = $others();

        $seed = $this->process(['preview:seed', '--no-interaction'], 'preview');
        $this->assertTrue($seed->successful(), $seed->errorOutput());
        $this->assertStringContainsString('preview:reset-account demo.initial', $seed->output());
        $this->assertSame(0, (int) $this->pdo()->query("select is_active from users where username = 'demo.initial'")->fetchColumn(), 'seed حسابِ غیرفعال را بی‌اجازه فعال کرد.');

        foreach (['production', 'local'] as $env) {
            $this->assertFalse($this->process(['preview:reset-account', 'demo.initial', '--no-interaction'], $env)->successful(), "روی {$env} اجرا شد.");
        }
        $this->assertFalse($this->process(['preview:reset-account', 'someone.else', '--no-interaction'], 'preview')->successful());

        $restore = $this->process(['preview:reset-account', 'demo.initial', '--no-interaction'], 'preview');
        $this->assertTrue($restore->successful(), $restore->output().$restore->errorOutput());
        $row = $this->pdo()->query("select is_active, password, complex_id from users where username = 'demo.initial'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertSame('changed-by-the-reviewer', $row['password'], 'بازگرداندن رمز را بی‌اجازه عوض کرد.');
        $this->assertNull($row['complex_id']);
        $this->assertSame($before, $others(), 'بازگرداندنِ یک حساب به حساب‌های دیگر دست زد.');
    }

    /** `preview:seed` روی محصول و روی پایگاه‌داده‌ی بی‌برچسب کاری نمی‌کند. */
    public function test_seeding_refuses_outside_a_marked_preview(): void
    {
        $this->assertFalse($this->process(['preview:seed', '--no-interaction'], 'production')->successful());
        $this->assertTrue($this->process(['migrate', '--force'], 'local')->successful());
        $this->assertFalse($this->process(['preview:seed', '--no-interaction'], 'preview')->successful());
        $this->assertSame(0, (int) $this->pdo()->query('select count(*) from users')->fetchColumn());
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array<string, mixed>
     */
    private function sorted(array $rows): array
    {
        ksort($rows);

        return $rows;
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
