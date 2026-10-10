<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * برداشتِ **واقعاً هم‌زمان** از کیفِ شخصی — مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶ (آزمونِ ۶
 * ِ تصمیمِ کارفرما).
 *
 * چهار پردازه‌ی جدا (`race:check debit`) در یک لحظه‌ی مشترک از کیفی برداشت
 * می‌کنند که فقط برای دوتایشان پول دارد. PHPUnit با SQLite ِ درحافظه یک اتصال
 * دارد و «همزمانی» در یک پردازه نمایش است، نه آزمون؛ این‌جا پایگاه‌داده‌ی فایلی
 * (محلی) یا MySQL ِ CI.
 *
 * ناوردایی‌ها: دقیقاً دو برداشت، مانده صفر و نه منفی، آخرین `balance_after` با
 * جمعِ دفتر، و زنجیره‌ی `balance_after` بی شکاف (دوباره‌شماری یا ردیفِ گم‌شده
 * همین زنجیره را می‌شکند).
 */
class WalletConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private const CREDIT = 20000;

    private const DEBIT = 10000;

    private const PAY = 5000;

    private ?string $file = null;

    /** @var array<string, string> */
    private array $env = [];

    /** @var array{user: int, unit: int, complex: int, bill: int}|null */
    private ?array $data = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = ['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];

        if (config('database.default') === 'sqlite') {
            $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sakena-wallet-race-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file];
        }

        $migrate = $this->artisanProcess(['migrate', '--force']);
        $this->assertTrue($migrate->successful(), 'مهاجرتِ پایگاه‌داده‌ی آزمون نشد: '.$migrate->errorOutput());
    }

    protected function tearDown(): void
    {
        if ($this->data !== null) {
            $this->artisanProcess(['race:check', 'wallet-cleanup', '--user='.$this->data['user'], '--complex='.$this->data['complex']]);
        }
        if ($this->file !== null && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_concurrent_debits_never_overdraw_or_desync_the_ledger(): void
    {
        $setup = $this->artisanProcess(['race:check', 'wallet-setup', '--amount='.self::CREDIT]);
        $this->assertTrue($setup->successful(), $setup->errorOutput());
        $this->data = json_decode(trim($setup->output()), true);

        $at = (int) floor(microtime(true) * 1000) + 4000;
        $pool = Process::pool(function ($pool) use ($at) {
            for ($i = 0; $i < self::WORKERS; $i++) {
                $pool->path(base_path())->env($this->env)->timeout(180)->command([
                    PHP_BINARY, 'artisan', 'race:check', 'debit',
                    '--user='.$this->data['user'], '--unit='.$this->data['unit'],
                    '--amount='.self::DEBIT, '--at='.$at,
                ]);
            }
        })->start();

        $outputs = collect($pool->wait()->collect())
            ->map(fn ($result) => trim($result->output().' '.$result->errorOutput()))
            ->values()
            ->all();

        $verify = $this->artisanProcess([
            'race:check', 'wallet-verify', '--user='.$this->data['user'],
        ]);
        $this->assertTrue($verify->successful(), $verify->errorOutput());
        $state = json_decode(trim($verify->output()), true);

        $context = "\nخروجیِ پردازه‌ها:\n  ".implode("\n  ", $outputs)."\nوضعیت: ".json_encode($state);

        /*
         * ⚠️ SQLite نویسنده‌ی هم‌زمان را با «database is locked» رد می‌کند به‌جای
         * صبر؛ پس محلی ممکن است فقط یکی بنشیند. «دقیقاً دو» فقط با قفلِ ردیفِ
         * واقعی (MySQL ِ CI) ادعا می‌شود. ناوردایی‌ها در هر دو بی‌تخفیف‌اند.
         */
        $expected = config('database.default') === 'sqlite' ? [1, 2] : [2];
        $this->assertContains($state['debits'], $expected, 'تعدادِ برداشت‌ها با موجودی نمی‌خواند.'.$context);
        $this->assertEqualsWithDelta(self::CREDIT - $state['debits'] * self::DEBIT, $state['balance'], 0.01, 'مانده نادرست — کیف منفی یا دوباره‌شماری.'.$context);
        $this->assertGreaterThanOrEqual(0.0, $state['balance'], 'کیف منفی شد.'.$context);
        $this->assertEqualsWithDelta($state['ledgerSum'], $state['lastSnapshot'], 0.01, 'عکسِ لحظه‌ای با دفتر نمی‌خواند.'.$context);
        $this->assertSame(0, $state['chainBroken'], 'زنجیره‌ی balance_after شکاف دارد.'.$context);

        // هر «OK» یک ردیفِ برداشت است — نه بیشتر (دوباره‌شماری) نه کمتر (گم‌شدن)
        $ok = count(array_filter($outputs, fn (string $o) => str_contains($o, 'OK')));
        $this->assertSame($state['debits'], $ok, 'پردازه‌های موفق با ردیف‌های دفتر نمی‌خوانند.'.$context);
    }

    /**
     * چهار تکرارِ **هم‌زمانِ یک پرداخت** (همان `Idempotency-Key`، مثلِ دوبار-کلیک یا
     * retry ِ شبکه): یک برداشت و یک بار نشستن روی قبض — نه بیشتر.
     */
    public function test_concurrent_repeats_of_one_payment_debit_once(): void
    {
        $setup = $this->artisanProcess(['race:check', 'wallet-setup', '--amount='.self::CREDIT]);
        $this->assertTrue($setup->successful(), $setup->errorOutput());
        $this->data = json_decode(trim($setup->output()), true);

        $at = (int) floor(microtime(true) * 1000) + 4000;
        $pool = Process::pool(function ($pool) use ($at) {
            for ($i = 0; $i < self::WORKERS; $i++) {
                $pool->path(base_path())->env($this->env)->timeout(180)->command([
                    PHP_BINARY, 'artisan', 'race:check', 'wallet-pay',
                    '--user='.$this->data['user'], '--bill='.$this->data['bill'],
                    '--amount='.self::PAY, '--key=race-same-payment-key', '--at='.$at,
                ]);
            }
        })->start();

        $outputs = collect($pool->wait()->collect())
            ->map(fn ($result) => trim($result->output().' '.$result->errorOutput()))
            ->values()
            ->all();

        $verify = $this->artisanProcess([
            'race:check', 'wallet-verify', '--user='.$this->data['user'], '--bill='.$this->data['bill'],
        ]);
        $this->assertTrue($verify->successful(), $verify->errorOutput());
        $state = json_decode(trim($verify->output()), true);
        $context = "\nخروجیِ پردازه‌ها:\n  ".implode("\n  ", $outputs)."\nوضعیت: ".json_encode($state);

        // ⚠️ SQLite ممکن است همه را با «database is locked» رد کند؛ «دقیقاً یک» فقط روی MySQL
        $expected = config('database.default') === 'sqlite' ? [0, 1] : [1];
        $this->assertContains($state['debits'], $expected, 'تکرارِ یک پرداخت بیش از یک برداشت ساخت.'.$context);
        $this->assertEqualsWithDelta($state['debits'] * self::PAY, $state['billPaid'], 0.01, 'قبض با دفتر نمی‌خواند.'.$context);
        $this->assertEqualsWithDelta(self::CREDIT - $state['debits'] * self::PAY, $state['balance'], 0.01, $context);
        $this->assertEqualsWithDelta($state['ledgerSum'], $state['lastSnapshot'], 0.01, $context);
        $this->assertSame(0, $state['chainBroken'], $context);

        // هر پردازه‌ی موفق همان یک پرداخت را گزارش می‌کند، نه پرداختِ تازه
        foreach (array_filter($outputs, fn (string $o) => str_starts_with($o, 'PAID')) as $output) {
            $this->assertStringContainsString('PAID '.self::PAY, $output, $context);
        }
    }

    /** @param  list<string>  $arguments */
    private function artisanProcess(array $arguments): ProcessResult
    {
        return Process::path(base_path())->env($this->env)->timeout(180)
            ->run([PHP_BINARY, 'artisan', ...$arguments]);
    }
}
