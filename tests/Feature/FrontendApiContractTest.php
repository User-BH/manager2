<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * هر مسیری که فرانت صدا می‌زند، باید در API وجود داشته باشد (R61).
 *
 * ─── 🔴 مسئله: Mixed App Versions ──────────────────────────────────────────
 * ⚠️ دو نیمه‌ی این سامانه جدا مستقر می‌شوند و جدا هم آزموده می‌شدند:
 * تست‌های PHP می‌گفتند «این مسیر کار می‌کند» و تست‌های JS می‌گفتند «این
 * کامپوننت درست رندر می‌شود». **هیچ‌کدام نمی‌پرسیدند آیا فرانت مسیری را
 * صدا می‌زند که وجود دارد.**
 *
 * یعنی تغییرِ نامِ یک مسیر در `routes/api.php` — یا حذفش — با کمالِ آرامش
 * از هر دو مجموعه رد می‌شد و در محصول ۴۰۴ می‌داد.
 *
 * ─── چرا از کد و نه از یک فهرستِ دستی ───────────────────────────────────────
 * فهرستِ دستی همان روزِ اول کهنه می‌شود. اینجا **هر دو طرف از کدِ واقعی**
 * می‌آیند: مسیرها از روتر، و فراخوانی‌ها از متنِ منبعِ فرانت.
 *
 * ⚠️ این جایگزینِ قراردادِ زمانِ اجرا (`X-App-Build`) نیست، مکملش است:
 * آن یکی تبِ **بازِ کاربر** را می‌گیرد، این یکی جلوی رفتنِ تغییرِ ناسازگار
 * به محصول را می‌گیرد.
 */
class FrontendApiContractTest extends TestCase
{
    /**
     * فراخوانی‌هایی که عمداً به روتر نمی‌خورند.
     *
     * ⚠️ فهرست باید **کوتاه** بماند و هر ردیفش دلیل داشته باشد؛ وگرنه
     * تبدیل می‌شود به جایی که هر شکستی زیرش پنهان شود.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // چیزی اینجا نیست — و اگر روزی لازم شد، با دلیلش بیاید.
    ];

    /**
     * 🔴 ادعای اصلی.
     */
    public function test_every_endpoint_the_frontend_calls_actually_exists(): void
    {
        $routes = $this->apiRoutePatterns();

        $this->assertNotEmpty($routes, 'هیچ مسیرِ APIی پیدا نشد؛ خودِ تست خراب است.');

        $calls = $this->frontendCalls();

        $this->assertGreaterThan(
            50,
            count($calls),
            'الگوی استخراج فراخوانی‌های فرانت را پیدا نکرد؛ خودِ تست خراب است.',
        );

        $missing = [];

        foreach ($calls as $call => $files) {
            if (isset(self::ALLOWED[$call])) {
                continue;
            }

            foreach ($routes as $pattern) {
                if ($this->pathMatches($call, $pattern)) {
                    continue 2;
                }
            }

            $missing[] = $call.'  ← '.implode('، ', array_unique($files));
        }

        $this->assertSame(
            [],
            $missing,
            "فرانت مسیرهایی را صدا می‌زند که در API نیستند:\n".implode("\n", $missing)
            ."\n\nیا مسیر را برگردانید، یا فراخوانیِ فرانت را اصلاح کنید. "
            .'رفتنِ این تغییر به محصول یعنی ۴۰۴ برای کاربر.',
        );
    }

    /**
     * ⚠️ متقارن: اگر الگوی تطبیق بیش از حد سخاوتمند باشد، تستِ بالا پوچ است.
     *
     * مسیری که قطعاً وجود ندارد باید رد شود — وگرنه «همه‌چیز جور در‌می‌آید»
     * و آن ادعا هیچ نمی‌گوید.
     */
    public function test_the_matcher_rejects_an_endpoint_that_does_not_exist(): void
    {
        $routes = $this->apiRoutePatterns();

        foreach (['/hich-jaa-nist', '/my-bills/{p}/hich', '/features/extra'] as $fake) {
            foreach ($routes as $pattern) {
                $this->assertFalse(
                    $this->pathMatches($fake, $pattern),
                    "مسیرِ ساختگیِ «{$fake}» با «{$pattern}» جور شد؛ الگو بیش از حد باز است.",
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  استخراج
    // ─────────────────────────────────────────────────────────────────────

    /**
     * مسیرهای ثبت‌شده‌ی `/api/v1`، نرمال‌شده.
     *
     * @return list<string>
     */
    private function apiRoutePatterns(): array
    {
        $patterns = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $patterns[] = $this->normalize('/'.substr($uri, strlen('api/v1')));
        }

        return array_values(array_unique($patterns));
    }

    /**
     * فراخوانی‌های `api(...)` در متنِ فرانت.
     *
     * @return array<string, list<string>> مسیرِ نرمال‌شده → فایل‌هایی که صدایش می‌زنند
     */
    private function frontendCalls(): array
    {
        $found = [];

        foreach ($this->frontendSources() as $file) {
            $code = (string) file_get_contents($file);

            // هم `api('/x')` و هم `api<T>(`/x/${id}`)`
            preg_match_all("/\bapi\s*(?:<[^>]*>)?\s*\(\s*(['\"`])([^'\"`]+)\\1/", $code, $matches, PREG_SET_ORDER);

            foreach ($matches as [, , $path]) {
                if (! str_starts_with($path, '/')) {
                    continue;
                }

                $found[$this->normalize($path)][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        return $found;
    }

    /**
     * یکسان‌سازیِ دو زبان.
     *
     * ⚠️ چهار تبدیل، و هرکدام یک حالتِ واقعی در همین مخزن دارد:
     *
     *   ① `?...`            حذفِ query          — `/notifications?limit=3`
     *   ② `${...}` → `{p}`  جای‌گیرِ کلاینت      — `/my-bills/${bill.id}`
     *   ③ `{x}`   → `{p}`   جای‌گیرِ روتر        — `api/v1/my-bills/{bill}`
     *   ④ `{p}`ِ چسبیده در انتها حذف            — `/messenger${query}`
     *
     * تبدیلِ ④ ظریف است: آنجا جای‌گیر یک **بخشِ مسیر** نیست، یک query
     * است که بی‌اسلش چسبیده. بدونِ این، آن فراخوانی هرگز جور نمی‌شد و
     * فهرستِ استثنا بی‌دلیل بلند می‌شد.
     */
    private function normalize(string $path): string
    {
        $path = (string) preg_replace('/\?.*$/', '', $path);
        $path = (string) preg_replace('/\$\{[^}]*\}/', '{p}', $path);
        $path = (string) preg_replace('/\{[^}]*\}/', '{p}', $path);
        $path = (string) preg_replace('#(?<!/)\{p\}$#', '', $path);

        return rtrim($path, '/') ?: '/';
    }

    /**
     * تطبیقِ بخش‌به‌بخش.
     *
     * ⚠️ `{p}` در **هر دو طرف** با هر چیزی جور می‌شود. لازم است، چون
     * `/join-requests/${id}/${action}` در کلاینت به دو مسیرِ واقعیِ
     * `.../approve` و `.../reject` می‌رود؛ کلاینت آن بخش را متغیر
     * می‌نویسد و روتر ثابت.
     */
    private function pathMatches(string $call, string $pattern): bool
    {
        $left = explode('/', trim($call, '/'));
        $right = explode('/', trim($pattern, '/'));

        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $i => $segment) {
            if ($segment === '{p}' || $right[$i] === '{p}') {
                continue;
            }

            if ($segment !== $right[$i]) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function frontendSources(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('js')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
