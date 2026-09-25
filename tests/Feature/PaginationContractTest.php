<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * هر فهرستی که سرور صفحه‌بندی می‌کند، در فرانت صفحه‌بند دارد (مرحلهٔ ۵ · فاز ۶ · بندِ ۵۰).
 *
 * 🔴 نقصِ اندازه‌گیری‌شده: هفت مسیر فهرستشان را با `paginate()` می‌دادند و
 * شش صفحه‌ی مصرف‌کننده فقط صفحه‌ی اول را می‌خواستند — واحدها، ساکنین،
 * قبض‌های من، اطلاعیه‌ها، درخواست‌ها و اعضا. سربرگ «۴۰ واحد ثبت شده»
 * می‌گفت و ۲۰ ردیف نشان می‌داد؛ بقیه جز با جست‌وجو پیدا نمی‌شدند. درخواست‌ها
 * حتی «صفحه‌ی ۱ از ۳» می‌نوشتند و راهی به صفحه‌ی ۲ نداشتند.
 *
 * ⚠️ فهرستِ مسیرها دستی نیست: از **کدِ کنترلرها** کشف می‌شود. مسیرِ
 * صفحه‌بندی‌شده‌ی تازه، بی‌آن‌که کسی یادش بماند، زیرِ همین قاعده است.
 */
class PaginationContractTest extends TestCase
{
    /** @return array<string, string> مسیر (بی‌پیشوند) ← کنترلر@متد */
    private function paginatedEndpoints(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);
            $reflection = new ReflectionMethod($class, $method);
            $lines = file((string) $reflection->getFileName());
            $body = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

            if (preg_match('/->(simple)?[pP]aginate\(/', $body)) {
                $found[substr($route->uri(), strlen('api/v1/'))] = $action;
            }
        }

        return $found;
    }

    /** @return list<string> فایل‌هایی که این مسیر را داخلِ یک `useQuery` می‌خوانند */
    private function readersOf(string $path): array
    {
        $readers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if (! preg_match('/\.tsx?$/', $file->getFilename())) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'useQuery')) {
                continue;
            }

            /*
             * ⚠️ هر رشته‌ی آدرسِ همین مسیر، **جز** آن‌که `method:` پشتش آمده —
             * فرمِ ثبت (`POST /units`) مصرف‌کننده‌ی فهرست نیست.
             *
             * 🔴 نسخه‌ی اول فقط آدرسِ درونِ `queryFn` را می‌دید؛ خرابکاری نشان داد
             * صفحه‌ای که آدرس را بیرون از `queryFn` در یک متغیر می‌سازد (همان
             * شکلِ قدیمیِ واحدها و ساکنین) اصلاً مصرف‌کننده شمرده نمی‌شد.
             */
            preg_match_all('#[\'"`]/'.preg_quote($path, '#').'(?:[?\'"`]|\$\{)#', $source, $hits, PREG_OFFSET_CAPTURE);

            foreach ($hits[0] as [, $offset]) {
                // ناوبری (`to="/my-bills"`، `path: '/units'`، `navigate(...)`) خواندنِ API نیست
                $before = substr($source, max(0, $offset - 16), min(16, $offset));
                if (preg_match('/(?:\bto|\bpath|\bhref)\s*[=:]\s*\{?\s*$|navigate\(\s*$/', $before)) {
                    continue;
                }

                if (! str_contains(substr($source, $offset, 160), 'method:')) {
                    $readers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('js')) + 1));
                    break;
                }
            }
        }

        return $readers;
    }

    public function test_every_paginated_list_is_actually_pageable_in_the_ui(): void
    {
        $endpoints = $this->paginatedEndpoints();

        // ضدِ پوچی: همان هفت مسیری که اندازه‌گیری پیدا کرد
        $this->assertGreaterThanOrEqual(7, count($endpoints), implode(', ', array_keys($endpoints)));

        $checked = 0;

        foreach (array_keys($endpoints) as $path) {
            foreach ($this->readersOf($path) as $reader) {
                // ⚠️ کامنت‌ها پاک می‌شوند: توضیحی که «page» را نام ببرد، ارسالش نیست
                $source = (string) preg_replace(
                    ['#/\*.*?\*/#s', '#(^|[^:])//.*$#m', '#\{/\*.*?\*/\}#s'],
                    ['', '$1', ''],
                    (string) file_get_contents(resource_path('js/'.$reader)),
                );

                $this->assertStringContainsString('<Pager', $source, "«{$reader}» فهرستِ صفحه‌بندی‌شده‌ی /{$path} را می‌خواند ولی صفحه‌بند ندارد؛ ردیف‌های صفحه‌ی دوم دیده نمی‌شوند.");

                // دو شکلِ رایج: `?page=${page}` یا `new URLSearchParams({ page: String(page) })`
                $this->assertMatchesRegularExpression(
                    '/page=\$\{page\}|\{\s*page:\s*String\(page\)/',
                    $source,
                    "«{$reader}» شماره‌ی صفحه را به /{$path} نمی‌فرستد.",
                );
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(7, $checked, 'مصرف‌کننده‌های فهرست پیدا نشدند؛ الگوی جست‌وجو کهنه است.');
    }
}
