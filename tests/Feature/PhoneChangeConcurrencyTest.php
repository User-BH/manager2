<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * دو تأییدِ **واقعاً هم‌زمانِ** تغییر به یک شماره (مرحلهٔ ۹ · آزمونِ «دو درخواستِ
 * هم‌زمان برای یک شماره» ِ تصمیمِ کارفرما).
 *
 * بدترین حالت ساخته می‌شود: هر دو حساب درخواستِ زنده و کدِ معتبر دارند، پس هر دو
 * از سدِ کد می‌گذرند. ناوردایی: شماره فقط به **یک** حساب می‌رسد و هیچ دو حسابی
 * یک شماره ندارند. همان دلیلِ `WalletConcurrencyTest`: پردازه‌ی جدا و دیتابیسِ
 * فایلی/MySQL، نه SQLite ِ درحافظه‌ی PHPUnit.
 */
class PhoneChangeConcurrencyTest extends TestCase
{
    private ?string $file = null;

    /** @var array<string, string> */
    private array $env = [];

    /** @var array{users: list<int>, phone: string}|null */
    private ?array $data = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = ['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];

        if (config('database.default') === 'sqlite') {
            $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sakena-phone-race-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file];
        }

        $migrate = $this->artisanProcess(['migrate', '--force']);
        $this->assertTrue($migrate->successful(), 'مهاجرتِ پایگاه‌داده‌ی آزمون نشد: '.$migrate->errorOutput());
    }

    protected function tearDown(): void
    {
        if ($this->data !== null) {
            $this->artisanProcess([
                'race:check', 'phone-cleanup', '--user='.implode(',', $this->data['users']), '--phone='.$this->data['phone'],
            ]);
        }
        if ($this->file !== null && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_two_accounts_confirming_one_number_at_once_never_share_it(): void
    {
        $setup = $this->artisanProcess(['race:check', 'phone-setup']);
        $this->assertTrue($setup->successful(), $setup->errorOutput());
        $this->data = json_decode(trim($setup->output()), true);

        $at = (int) floor(microtime(true) * 1000) + 4000;
        $pool = Process::pool(function ($pool) use ($at) {
            foreach ($this->data['users'] as $user) {
                $pool->path(base_path())->env($this->env)->timeout(180)->command([
                    PHP_BINARY, 'artisan', 'race:check', 'phone-confirm', '--user='.$user, '--at='.$at,
                ]);
            }
        })->start();

        $outputs = collect($pool->wait()->collect())
            ->map(fn ($result) => trim($result->output().' '.$result->errorOutput()))
            ->values()
            ->all();

        $verify = $this->artisanProcess(['race:check', 'phone-verify', '--phone='.$this->data['phone']]);
        $this->assertTrue($verify->successful(), $verify->errorOutput());
        $state = json_decode(trim($verify->output()), true);
        $context = "\nخروجیِ پردازه‌ها:\n  ".implode("\n  ", $outputs)."\nوضعیت: ".json_encode($state);

        $ok = count(array_filter($outputs, fn (string $o) => str_contains($o, 'OK')));

        // ⚠️ SQLite ممکن است هر دو را با «database is locked» رد کند؛ «دقیقاً یک» فقط روی MySQL
        $expected = config('database.default') === 'sqlite' ? [0, 1] : [1];
        $this->assertContains($ok, $expected, 'شماره به بیش از یک حساب رسید، یا به هیچ‌کدام (MySQL).'.$context);
        $this->assertCount($ok, $state['owners'], 'تأییدهای موفق با صاحبانِ شماره نمی‌خوانند.'.$context);
        $this->assertLessThanOrEqual(1, count($state['owners']), 'دو حساب یک شماره دارند.'.$context);
        $this->assertSame($ok, $state['changedAudits'], 'ردِ حسابرسی با تغییرِ واقعی نمی‌خواند.'.$context);
    }

    /** @param  list<string>  $arguments */
    private function artisanProcess(array $arguments): ProcessResult
    {
        return Process::path(base_path())->env($this->env)->timeout(180)
            ->run([PHP_BINARY, 'artisan', ...$arguments]);
    }
}
