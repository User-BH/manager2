<?php

namespace Tests\Feature;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * همزمانیِ **واقعیِ** تکمیلِ خرید (مرحلهٔ ۹ · فاز ۵؛ خواستِ صریحِ کارفرما).
 *
 * دو یا چند درخواستِ هم‌زمان برای یک قصدِ خرید نباید دو مجتمع، دو اشتراک، دو
 * مدیر یا دو پرداختِ موفق بسازند.
 *
 * ─── چرا پردازه و نه دو فراخوانی ─────────────────────────────────────────
 * ⚠️ `CheckoutIntentTest::test_completing_twice_is_idempotent` دو فراخوانیِ
 * **پشتِ‌سرِهم** است — رقابتی ساخته نمی‌شود. اینجا هر `complete` در پردازه‌ی
 * مستقل با اتصالِ دیتابیسِ خودش اجرا می‌شود (`race:checkout`) و همه در یک لحظه‌ی
 * از پیش تعیین‌شده رها می‌شوند. RefreshDatabase نیست، چون پردازه‌ها تراکنشِ بازِ
 * تست را نمی‌بینند.
 *
 * ⚠️ کشِ پردازه‌ها `array` است، پس قفلِ کدِ رهگیری (`TrackingCodes::exclusively`)
 * **بینِ پردازه‌ها مشترک نیست** — عمداً سخت‌گیرانه‌تر: تنها محافظ همان قفلِ ردیف،
 * بازخوانیِ وضعیت زیرِ قفل و قیدهای یکتای پایگاه‌داده است.
 *
 * روی SQLite ِ فایلی (کارِ بک‌اند) قفلِ کلِ پایگاه‌داده و تلاشِ دوباره‌ی تراکنش؛
 * روی MySQL (کارِ «قیدهای دیتابیس») قفلِ ردیفِ واقعی.
 */
class CheckoutConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private ?string $file = null;

    /** @var array<string, string> */
    private array $env = [];

    private ?int $intent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = ['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];

        // SQLite ِ درون‌حافظه‌ای میانِ پردازه‌ها مشترک نیست: پایگاه‌داده‌ی فایلیِ یک‌بارمصرف
        if (config('database.default') === 'sqlite') {
            $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sakena-race-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file];
        }

        $migrate = $this->artisanProcess(['migrate', '--force']);
        $this->assertTrue($migrate->successful(), 'مهاجرتِ پایگاه‌داده‌ی آزمون نشد: '.$migrate->errorOutput());
    }

    protected function tearDown(): void
    {
        if ($this->intent !== null) {
            $this->artisanProcess(['race:checkout', 'cleanup', '--intent='.$this->intent]);
        }
        if ($this->file !== null && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    /** @param  list<string>  $arguments */
    private function artisanProcess(array $arguments): ProcessResult
    {
        return Process::path(base_path())->env($this->env)->timeout(180)
            ->run([PHP_BINARY, 'artisan', ...$arguments]);
    }

    private function setupIntent(): int
    {
        $setup = $this->artisanProcess(['race:checkout', 'setup']);
        $this->assertTrue($setup->successful(), $setup->errorOutput());

        return $this->intent = (int) json_decode(trim($setup->output()), true)['intent'];
    }

    /**
     * @param  list<string>  $trackings  رهگیریِ هر پردازه
     * @return list<string>
     */
    private function race(int $intent, array $trackings): array
    {
        // لحظه‌ی شروعِ مشترک: پس از بالا آمدنِ همه‌ی پردازه‌ها
        $at = (int) floor(microtime(true) * 1000) + 4000;

        $pool = Process::pool(function ($pool) use ($intent, $trackings, $at) {
            foreach ($trackings as $tracking) {
                $pool->path(base_path())->env($this->env)->timeout(180)->command([
                    PHP_BINARY, 'artisan', 'race:checkout', 'complete',
                    '--intent='.$intent, '--tracking='.$tracking, '--at='.$at,
                ]);
            }
        })->start();

        return collect($pool->wait()->collect())
            ->map(fn ($result) => trim($result->output().' '.$result->errorOutput()))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function invariants(int $intent): array
    {
        $verify = $this->artisanProcess(['race:checkout', 'verify', '--intent='.$intent]);
        $this->assertTrue($verify->successful(), $verify->errorOutput());

        return json_decode(trim($verify->output()), true);
    }

    /** @param  list<string>  $outputs */
    private function assertExactlyOneOfEverything(array $state, array $outputs): void
    {
        $context = "\nخروجیِ پردازه‌ها:\n  ".implode("\n  ", $outputs)."\nوضعیت: ".json_encode($state, JSON_UNESCAPED_UNICODE);

        $this->assertSame('completed', $state['status'], 'قصد تکمیل نشد.'.$context);
        $this->assertSame(1, $state['complexes'], 'بیش از یک مجتمع (یا هیچ) ساخته شد.'.$context);
        $this->assertSame(1, $state['subscriptions'], 'بیش از یک اشتراک با این رهگیری.'.$context);
        $this->assertSame(1, $state['subscriptionsOfComplex'], 'مجتمع بیش از یک اشتراک گرفت.'.$context);
        $this->assertSame(1, $state['managers'], 'خریدار مدیر نشد.'.$context);
        $this->assertSame($state['intentComplex'], $state['userComplex'], 'خریدار مدیرِ مجتمعِ دیگری شد.'.$context);
        $this->assertTrue($state['linked'], 'قصد به اشتراک وصل نشد.'.$context);
        $this->assertSame(1, $state['completedIntentsWithTracking'], 'یک رهگیری بیش از یک پرداختِ موفق شد.'.$context);

        $completed = count(array_filter($outputs, fn (string $o) => str_contains($o, 'RESULT:completed')));
        $this->assertGreaterThanOrEqual(1, $completed, 'هیچ پردازه‌ای تکمیل را گزارش نکرد.'.$context);
    }

    /** همان callback، چند بار در یک لحظه (refresh، تکرارِ درگاه، دو تب) */
    public function test_concurrent_duplicate_callbacks_create_exactly_one_of_everything(): void
    {
        $intent = $this->setupIntent();

        $outputs = $this->race($intent, array_fill(0, self::WORKERS, 'RACE-SAME'));

        $this->assertExactlyOneOfEverything($this->invariants($intent), $outputs);
    }

    /** بازپخش با رهگیری‌های متفاوت برای همان قصد: باز هم فقط یک پرداختِ موفق */
    public function test_concurrent_replays_with_different_tracking_codes_still_complete_once(): void
    {
        $intent = $this->setupIntent();

        $trackings = array_map(fn (int $i) => "RACE-DIFF-{$i}", range(1, self::WORKERS));
        $outputs = $this->race($intent, $trackings);
        $state = $this->invariants($intent);

        $this->assertExactlyOneOfEverything($state, $outputs);
    }
}
