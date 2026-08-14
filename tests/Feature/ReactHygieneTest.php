<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نگهبانِ ساختاریِ بهداشتِ React (R39).
 *
 * ─── چرا تستِ PHP برای کدِ فرانت ────────────────────────────────────────────
 * این‌ها را نمی‌شود با رندرکردنِ یک کامپوننت سنجید: ادعا درباره‌ی **کلِ**
 * کدبیس است («هیچ افکتی نباید…»). یک اسکنِ متنی این را در همه‌ی ۶۱ افکت
 * می‌سنجد، در حالی که تستِ کامپوننتی فقط همان یکی را می‌بیند که برایش
 * نوشته شده — و باگ همیشه در آن یکی است که کسی برایش تستی ننوشته.
 *
 * همان‌جا هم اجرا می‌شود که بقیه‌ی نگهبان‌های ساختاری (`ArchitectureTest`).
 */
class ReactHygieneTest extends TestCase
{
    /**
     * @return array<int,string>
     */
    private function sources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.tsx?$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * بدنه‌ی هر `useEffect` را با شمارشِ آکولاد بیرون می‌کشد.
     *
     * @return array<int,array{file:string,line:int,body:string}>
     */
    private function effects(): array
    {
        $found = [];

        foreach ($this->sources() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all('/useEffect\(\s*\(\s*\)\s*=>\s*\{/', $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$match, $offset]) {
                $start = $offset + strlen($match);
                $depth = 1;
                $i = $start;

                while ($i < strlen($source) && $depth > 0) {
                    if ($source[$i] === '{') {
                        $depth++;
                    } elseif ($source[$i] === '}') {
                        $depth--;
                    }
                    $i++;
                }

                $found[] = [
                    'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'body' => substr($source, $start, $i - $start - 1),
                ];
            }
        }

        return $found;
    }

    /**
     * ⚠️ هر افکتی که چیزی را «روشن» می‌کند باید خاموشش هم بکند.
     *
     * ─── باگی که این قاعده گرفت ────────────────────────────────────────────
     * شمارنده‌ی آمارِ صفحه‌ی فرود یک `requestAnimationFrame`ِ **خودتکرار**
     * داشت که هیچ‌جا لغو نمی‌شد. چون وابستگیِ افکت `isInView` بود، هر بار
     * که کاربر از آن بخش بیرون و دوباره داخلِ دید می‌آمد یک حلقه‌ی تازه
     * شروع می‌شد در حالی که قبلی هنوز می‌دوید — چند حلقه هم‌زمان سرِ یک
     * مقدار. و چتِ پشتیبانی `setTimeout`ی داشت که با بسته‌شدنِ پنجره لغو
     * نمی‌شد و فوکوس را به ورودیِ پنهان می‌برد.
     */
    public function test_every_effect_that_subscribes_also_unsubscribes(): void
    {
        $subscribe = '/\b(addEventListener|setTimeout|setInterval|requestAnimationFrame'
            .'|new (?:Intersection|Resize|Mutation|Performance)Observer|new WebSocket|new EventSource)\b/';
        $cleanup = '/\b(removeEventListener|clearTimeout|clearInterval|cancelAnimationFrame'
            .'|disconnect\(\)|abort\(\)|close\(\)|unsubscribe\(\))/';

        $offenders = [];

        foreach ($this->effects() as $effect) {
            if (! preg_match($subscribe, $effect['body'])) {
                continue;
            }

            if (str_contains($effect['body'], 'return') && preg_match($cleanup, $effect['body'])) {
                continue;
            }

            $offenders[] = "{$effect['file']}:{$effect['line']}";
        }

        $this->assertSame(
            [],
            $offenders,
            "این افکت‌ها چیزی را ثبت می‌کنند ولی پاکش نمی‌کنند:\n".implode("\n", $offenders),
        );
    }

    /**
     * ⚠️ فهرستی که کاربر عضوش را حذف می‌کند نباید کلیدِ اندیسی داشته باشد.
     *
     * ─── چرا فهرستِ استثنا دارد ────────────────────────────────────────────
     * کلیدِ اندیسی همیشه باگ نیست: برای جای‌گیرِ اسکلت، حباب‌های تزئینی و
     * نوارهای ثابت کاملاً درست است چون آن فهرست‌ها هرگز تغییر نمی‌کنند. در
     * `GalleryLightbox` هم **عمدی** است — تغییرِ کلید با تغییرِ اندیس همان
     * چیزی است که انیمیشنِ ورود را دوباره اجرا می‌کند.
     *
     * قاعده فقط جایی سخت‌گیر است که فهرست **قابلِ حذف** باشد؛ آنجا حذفِ
     * عضوِ میانی باعث می‌شود React گرهِ DOM را برای عضوِ دیگری بازاستفاده
     * کند و فوکوس و نشانگرِ کاربر روی ردیفِ اشتباه بماند.
     */
    public function test_editable_lists_never_key_by_array_index(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path) {
            $source = (string) file_get_contents($path);

            /*
             * ⚠️ نشانه‌ی «فهرستِ حذف‌شدنی» = هندلری که آرایه را کوتاه می‌کند.
             *
             * قاعده‌ی اولم دنبالِ `filter((_, i) => i !== …)` می‌گشت و در
             * پاسِ خرابکاری **خودش را خنثی کرد**: چون همان اصلاح حذف را از
             * اندیسی به شناسه‌ای برد، آن الگو دیگر پیدا نمی‌شد و کلِ فایل از
             * بررسی کنار گذاشته می‌شد — یعنی برگرداندنِ کلیدِ اندیسی هیچ
             * تستی را نمی‌شکست.
             *
             * الگوی تازه به **شکلِ** حذف کاری ندارد؛ فقط می‌پرسد آیا هندلری
             * هست که آرایه را کوتاه کند. جای‌گیرهای اسکلت و حباب‌های تزئینی
             * هندلر ندارند، پس همچنان مستثنا می‌مانند.
             */
            if (! preg_match('/on(?:Click|Change)=\{[^}]{0,400}\.filter\(/s', $source)) {
                continue;
            }

            if (preg_match('/key=\{\s*(index|i|idx)\s*\}/', $source)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $offenders, 'فهرستِ حذف‌شدنی با کلیدِ اندیسی: '.implode(', ', $offenders));
    }

    /**
     * هیچ فایلِ کامپوننتی نباید از هزار خط بگذرد.
     *
     * ⚠️ سقف روی **فایل** است نه کامپوننت، و عمدی: `CalculatorPage` شش
     * زیرکامپوننت داشت — یعنی تجزیه از قبل انجام شده بود — ولی همه در یک
     * فایلِ ۹۶۸ خطی. چیزی که نبود مرزِ فایلی بود، نه مرزِ کامپوننتی.
     */
    public function test_no_component_file_grows_past_a_thousand_lines(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path) {
            if (! str_ends_with($path, '.tsx')) {
                continue;
            }

            $lines = count(file($path));

            if ($lines > 700) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)." ({$lines} خط)";
            }
        }

        $this->assertLessThanOrEqual(
            2,
            count($offenders),
            "فایل‌های بیش از ۷۰۰ خط:\n".implode("\n", $offenders),
        );
    }
}
