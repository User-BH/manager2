<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * آمادگیِ سنجشِ بار (R66).
 *
 * ⚠️ این تست‌ها **بار نمی‌سنجند.** سنجشِ واقعیِ بار به سرورِ واقعی نیاز
 * دارد و وضعیتش NOT VERIFIED است (`docs/LOAD_TESTING.md`).
 *
 * چیزی که اینجا سنجیده می‌شود، درستیِ **ابزار و سندِ** آن اجراست: ابزار
 * باید بدونِ فریم‌ورک اجرا شود، و جدولِ سقفِ نرخ باید با کدِ واقعی بخواند —
 * وگرنه اپراتور با فرضِ غلط اجرا می‌کند و عددِ غلط می‌گیرد.
 */
class LoadReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = 'scripts/load-probe.php';

    private const RUNBOOK = 'docs/LOAD_TESTING.md';

    // ─────────────────────────────────────────────────────────────────────
    //  ابزار
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 ابزار باید روی ماشینی اجرا شود که **سرور نیست**.
     *
     * تولیدکننده‌ی بار روی همان ماشینِ برنامه، CPU را از خودِ برنامه
     * می‌دزدد و عددِ حاصل ترکیبِ دو بار است. پس اسکریپت باید کاملاً
     * خودبسنده بماند: نه `vendor/autoload`، نه بوتِ لاراول.
     */
    public function test_the_probe_needs_nothing_but_php(): void
    {
        $source = (string) file_get_contents(base_path(self::PROBE));

        foreach (['vendor/autoload', 'bootstrap/app.php', 'Illuminate\\', 'use App\\'] as $dependency) {
            $this->assertStringNotContainsString(
                $dependency,
                $source,
                "ابزارِ سنجشِ بار به «{$dependency}» وابسته شد؛ دیگر نمی‌شود روی ماشینِ دیگری اجرایش کرد.",
            );
        }
    }

    /**
     * ⚠️ ادعای «فایل هست» پوچ است.
     *
     * اسکریپت واقعاً با `php` خام اجرا می‌شود، هم‌زمانی می‌سازد، خطای
     * حمل‌ونقل را می‌شمارد و JSONِ معتبر می‌دهد. هدف عمداً پورتی است که
     * کسی رویش گوش نمی‌دهد، پس هیچ درخواستی به شبکه‌ی بیرون نمی‌رود.
     */
    public function test_the_probe_actually_runs_and_reports(): void
    {
        $output = [];
        $status = 0;

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path(self::PROBE))
            .' --url=http://127.0.0.1:1/nothing --concurrency=2 --duration=1 --timeout=2 --json',
            $output,
            $status,
        );

        $report = json_decode(implode("\n", $output), true);

        $this->assertIsArray($report, 'خروجیِ ابزار JSONِ معتبر نبود.');
        $this->assertSame('http://127.0.0.1:1/nothing', $report['url']);

        $step = $report['steps'][0];

        $this->assertSame(2, $step['concurrency']);
        $this->assertGreaterThan(0, $step['transport_errors'], 'اتصالِ ردشده باید خطای حمل‌ونقل شمرده شود.');
        $this->assertSame(0, $step['ok']);

        // ⚠️ شکستِ واقعی باید کدِ خروجیِ ناموفق بدهد، وگرنه در اسکریپت دیده نمی‌شود
        $this->assertSame(1, $status);
    }

    /**
     * 🔴 مهم‌ترین ویژگیِ این ابزار: ۴۲۹ **جدا** شمرده می‌شود.
     *
     * ردکردنِ درخواست ارزان‌تر از سرو‌کردنش است، پس مسیرِ محدودشده RPSِ
     * بالاتری نشان می‌دهد. اگر ۴۲۹ کنارِ بقیه‌ی ۴xx بنشیند — یا بدتر،
     * موفق شمرده شود — عددِ حاصل بهتر از واقعیت به نظر می‌رسد.
     */
    public function test_the_probe_counts_throttled_responses_apart(): void
    {
        /*
         * 🔴 نسخه‌ی اولِ این تست **ساختاری** بود و سه رشته را در سورس
         * می‌سنجید. خرابکاریِ «۴۲۹ را موفق بشمار» از کنارش رد شد، چون آن سه
         * رشته دست‌نخورده ماندند و هیچ‌کدام سطلِ `ok` را نگاه نمی‌کرد.
         *
         * حالا ابزار پاسخِ واقعیِ ۴۲۹ می‌گیرد و هر چهار سطل سنجیده می‌شود.
         */
        $throttled = $this->probeAgainstStatus(429);

        $this->assertGreaterThan(0, $throttled['throttled'], '۴۲۹ در سطلِ خودش شمرده نشد.');
        $this->assertSame(0, $throttled['ok'], '۴۲۹ نباید موفق شمرده شود؛ کاربر خطا گرفته است.');
        $this->assertSame(0, $throttled['client_errors'], '۴۲۹ نباید داخلِ سطلِ عمومیِ ۴xx بیفتد.');
        $this->assertSame(0, $throttled['server_errors']);
    }

    /** و بقیه‌ی دسته‌ها هم باید درست بیفتند، وگرنه سطلِ ۴۲۹ به‌تنهایی معنی ندارد. */
    public function test_the_probe_classifies_every_other_outcome(): void
    {
        $ok = $this->probeAgainstStatus(200);

        $this->assertGreaterThan(0, $ok['ok']);
        $this->assertSame(0, $ok['throttled']);
        $this->assertSame(0, $ok['server_errors']);

        $broken = $this->probeAgainstStatus(500);

        $this->assertGreaterThan(0, $broken['server_errors'], '۵xx باید شکستِ واقعی شمرده شود.');
        $this->assertSame(0, $broken['ok']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  سند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 جدولِ سقفِ نرخ باید با کدِ واقعی بخواند.
     *
     * ─── چرا این مهم‌ترین محافظِ این مرحله است ─────────────────────────────
     * اپراتور بر اساسِ آن جدول تصمیم می‌گیرد کدام مسیر را چطور بسنجد. سقفی
     * که در کد باشد و در سند نه، یعنی اجرایی که ۴۲۹ می‌گیرد و کسی
     * نمی‌فهمد چرا — و عددش را به‌حسابِ ظرفیت می‌گذارد.
     */
    public function test_every_rate_limit_appears_in_the_runbook(): void
    {
        $runbook = (string) file_get_contents(base_path(self::RUNBOOK));
        $missing = [];

        foreach ($this->definedLimiters() as $name) {
            if (! str_contains($runbook, '`'.$name.'`')) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'این سقف‌ها در کد هستند و در docs/LOAD_TESTING.md نیستند: '.implode('، ', $missing),
        );
    }

    /** و برعکس: سندی که سقفِ ازبین‌رفته را نام ببرد، اپراتور را گمراه می‌کند. */
    public function test_the_runbook_names_no_limit_that_was_removed(): void
    {
        /*
         * ⚠️ فقط جدولِ سقف‌ها، نه کلِ سند.
         *
         * نسخه‌ی اولِ این تست هر `| `نام`` را می‌گرفت و روی جدولِ
         * «کوئری بر ثانیه» — که همان شکل را دارد — دو خطای کاذب داد.
         * برشِ صریحِ همان بخش، تنها راهِ درستش است.
         */
        $table = $this->limitTable();
        $defined = $this->definedLimiters();

        preg_match_all('/^\| `([a-z][a-z-]+)`/m', $table, $matches);

        $this->assertNotEmpty($matches[1], 'جدولِ سقف‌ها در سند پیدا نشد؛ عنوانش عوض شده.');

        $stale = array_values(array_diff(array_unique($matches[1]), $defined));

        $this->assertSame([], $stale, 'سند سقفی را نام می‌برد که در کد وجود ندارد: '.implode('، ', $stale));
    }

    /** مسیرهایی که سند برای سنجش پیشنهاد می‌کند باید واقعاً وجود داشته باشند. */
    public function test_every_flow_in_the_runbook_points_at_a_real_route(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => '/'.ltrim($route->uri(), '/'))
            ->all();

        $flows = [
            '/api/v1/login',
            '/api/v1/dashboard',
            '/api/v1/wallet',
            '/api/v1/notifications',
            '/api/v1/search',
            '/api/v1/messenger',
            '/api/v1/documents/bills-bundle',
            '/up',
        ];

        foreach ($flows as $uri) {
            $this->assertContains($uri, $uris, "سندِ سنجشِ بار مسیرِ «{$uri}» را پیشنهاد می‌کند که وجود ندارد.");
        }
    }

    /** دستورهایی که سند برای پیش و پس از اجرا می‌دهد باید واقعاً باشند. */
    public function test_the_runbook_only_names_commands_that_exist(): void
    {
        $available = array_keys(app(Kernel::class)->all());

        foreach (['perf:baseline', 'alerts:scan', 'health:check', 'config:cache', 'route:cache'] as $command) {
            $this->assertContains($command, $available, "دستورِ «{$command}» در سند آمده ولی وجود ندارد.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  گران‌ترین مسیرِ بی‌سقف
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 اندازه‌گیریِ R65: داشبورد ۲۳ کوئری می‌زند — بیش از سه برابرِ
     * سنگین‌ترین مسیرِ بعدی — و تا R66 هیچ سقفی نداشت.
     *
     * هیچ سقفِ سراسری‌ای روی `/api` نیست، پس یک ساکنِ واردشده یا یک تبِ
     * گیرکرده می‌توانست همان مسیر را در حلقه بزند.
     */
    public function test_the_most_expensive_route_is_throttled(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate): bool => $candidate->uri() === 'api/v1/dashboard');

        $this->assertNotNull($route, 'مسیرِ داشبورد پیدا نشد.');
        $this->assertContains('throttle:dashboard', $route->gatherMiddleware());
    }

    /** ⚠️ و سقفش باید آن‌قدر بالا باشد که کاربرِ واقعی هرگز نبیندش. */
    public function test_the_dashboard_limit_leaves_room_for_real_use(): void
    {
        $limiter = RateLimiter::limiter('dashboard');

        $this->assertNotNull($limiter, 'سقفِ داشبورد ثبت نشده است.');

        $limits = $limiter(request());
        $limit = is_array($limits) ? $limits[0] : $limits;

        /*
         * فرانت `staleTime` سی‌ثانیه‌ای دارد و `refetchOnWindowFocus` خاموش
         * است، پس مصرفِ واقعی حداکثر دو بار در دقیقه است.
         */
        $this->assertGreaterThanOrEqual(30, $limit->maxAttempts, 'سقف آن‌قدر پایین است که کاربرِ واقعی به آن می‌خورد.');
        $this->assertLessThanOrEqual(120, $limit->maxAttempts, 'سقف آن‌قدر بالاست که جلوی حلقه را نمی‌گیرد.');
    }

    /**
     * ⚠️ ادعای ساختاری کافی نیست: باید ثابت شود سقف واقعاً **می‌بندد**.
     *
     * بدونِ این، برداشتنِ ثبتِ سقف از `AppServiceProvider` می‌توانست از تور
     * رد شود — میدل‌ورِ `throttle:dashboard` سرِ جایش می‌ماند و لاراول
     * سقفی پیدا نمی‌کرد.
     */
    public function test_the_dashboard_actually_starts_refusing(): void
    {
        $complex = Complex::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::ComplexAdmin->value,
            'complex_id' => $complex->id,
            'is_active' => true,
        ]);

        $limiter = RateLimiter::limiter('dashboard');
        $limits = $limiter(request());
        $allowed = (is_array($limits) ? $limits[0] : $limits)->maxAttempts;

        for ($i = 0; $i < $allowed; $i++) {
            $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk();
        }

        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertStatus(429);
    }

    /**
     * ابزار را روی سروری اجرا می‌کند که همیشه همان کدِ وضعیت را می‌دهد.
     *
     * ⚠️ سرورِ آزمایشی عمداً به برنامه ربطی ندارد: هدف سنجشِ **دسته‌بندیِ
     * خودِ ابزار** است، نه رفتارِ لاراول.
     *
     * @return array<string, mixed>
     */
    private function probeAgainstStatus(int $code): array
    {
        $port = $this->freePort();

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, base_path('tests/fixtures/status-server.php')],
            [1 => ['file', $this->devNull(), 'w'], 2 => ['file', $this->devNull(), 'w']],
            $pipes,
        );

        $this->assertIsResource($server, 'سرورِ آزمایشی بالا نیامد.');

        try {
            $this->waitForPort($port);

            $output = [];
            exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path(self::PROBE))
                .' --url='.escapeshellarg("http://127.0.0.1:{$port}/?code={$code}")
                .' --concurrency=2 --duration=1 --timeout=5 --json',
                $output,
            );

            $report = json_decode(implode("\n", $output), true);

            $this->assertIsArray($report, 'خروجیِ ابزار JSONِ معتبر نبود.');

            return $report['steps'][0];
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

        $this->assertIsResource($socket, 'پورتِ آزاد پیدا نشد.');

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitForPort(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        $this->fail("سرورِ آزمایشی روی پورتِ {$port} بالا نیامد.");
    }

    private function devNull(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }

    /** بخشِ «همه‌ی شانزده سقفِ سامانه» از سند. */
    private function limitTable(): string
    {
        $runbook = (string) file_get_contents(base_path(self::RUNBOOK));
        $start = strpos($runbook, '### همه‌ی');

        $this->assertNotFalse($start, 'عنوانِ جدولِ سقف‌ها پیدا نشد.');

        $end = strpos($runbook, '🔴 **سقف‌ها را', (int) $start);

        return substr($runbook, (int) $start, ($end === false ? null : $end - (int) $start));
    }

    /** @return list<string> */
    private function definedLimiters(): array
    {
        preg_match_all(
            "/RateLimiter::for\('([a-z-]+)'/",
            (string) file_get_contents(app_path('Providers/AppServiceProvider.php')),
            $matches,
        );

        $this->assertNotEmpty($matches[1], 'هیچ سقفی پیدا نشد؛ الگو کهنه شده است.');

        return array_values(array_unique($matches[1]));
    }
}
