<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * معماریِ آفلاین — و تصمیمی که گرفته **نشد** (R62).
 *
 * ─── 🔴 تصمیمِ اصلی ────────────────────────────────────────────────────────
 * فهرستِ ممیزی زنجیره‌ی `Offline → Pending → Online → Sync` می‌خواست، ولی
 * درست پیش از آن می‌گوید **«پیش از ساختنش باید پرسید لازم است یا نه»**.
 *
 * پاسخِ سنجیده‌شده برای این سامانه **نه** است: هر ۹۵ مسیرِ نوشتنی در ۳۰
 * حوزه بررسی شد و هیچ‌کدام در دسته‌ی «صف واقعاً کمک می‌کند» نیفتاد.
 *
 * ⚠️ ولی «نساختیم» به‌تنهایی یک تصمیم نیست، یک غفلت است — مگر اینکه
 * نوشته و سنجیده شود. این کلاس همان سنجش است.
 */
class OfflineBehaviourTest extends TestCase
{
    /**
     * 🔴 هر حوزه‌ی نوشتنی باید در جدولِ سند سطری داشته باشد.
     *
     * ⚠️ فهرست از **روتر** می‌آید، نه از فهرستِ دستی. حوزه‌ی تازه‌ای که
     * فردا اضافه شود این تست را قرمز می‌کند — پس تصمیمِ «آفلاین با این چه
     * کنیم؟» **گرفته می‌شود**، نه فراموش.
     *
     * همان الگوی `BackupManifest` در R58: سکوت مجاز نیست.
     */
    public function test_every_write_area_is_classified_in_the_document(): void
    {
        $document = (string) File::get(base_path('docs/OFFLINE.md'));

        $missing = [];

        foreach ($this->writeAreas() as $area) {
            if (! str_contains($document, '`'.$area.'`')) {
                $missing[] = $area;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'این حوزه‌های نوشتنی در جدولِ docs/OFFLINE.md نیستند: '.implode('، ', $missing)
            .'. برای هرکدام باید تصمیم گرفته شود که آفلاین چه می‌شود.',
        );
    }

    /**
     * ⚠️ متقارن: اگر الگو هر رشته‌ای را پیدا کند، تستِ بالا پوچ است.
     */
    public function test_the_document_check_would_notice_an_absent_area(): void
    {
        $document = (string) File::get(base_path('docs/OFFLINE.md'));

        $this->assertStringNotContainsString('`hich-hoze-i`', $document);
    }

    /**
     * 🔴 قیدِ صریحِ فایلِ ایرادات: عملیاتِ مالی هرگز صف نمی‌شود.
     *
     * ⚠️ این تست ساختاری است و عمداً: تنها راهِ اثباتِ «صف وجود ندارد»
     * این است که نشان دهیم **هیچ کدی برای صف نوشته نشده**. اگر روزی کسی
     * صفی اضافه کند، باید این تست را هم عوض کند — و آن لحظه مجبور می‌شود
     * قیدِ مالی را دوباره بخواند.
     */
    public function test_no_offline_write_queue_exists_in_the_frontend(): void
    {
        $suspicious = [];

        foreach ($this->frontendSources() as $file) {
            $code = (string) file_get_contents($file);

            foreach (['backgroundSync', 'SyncManager', 'registration.sync', 'outbox', 'pendingWrites'] as $needle) {
                if (str_contains($code, $needle)) {
                    $suspicious[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' ← '.$needle;
                }
            }
        }

        $this->assertSame(
            [],
            $suspicious,
            "نشانه‌ی صفِ آفلاین پیدا شد:\n".implode("\n", $suspicious)
            ."\n\nاگر عمدی است، docs/OFFLINE.md را عوض کنید — و قیدِ «پرداخت و هر "
            .'عملیاتِ مالی از صفِ آفلاین مستثنا» را دوباره بخوانید.',
        );
    }

    /** ⚠️ تصمیمِ «نساختیم» باید دلیلش نوشته باشد، نه فقط خودش. */
    public function test_the_document_explains_why_there_is_no_queue(): void
    {
        $document = (string) File::get(base_path('docs/OFFLINE.md'));

        foreach ([
            'صفِ آفلاین وجود ندارد',
            'اطمینانِ کاذب',
            'Overengineering',
            'navigator.onLine',
        ] as $needle) {
            $this->assertStringContainsString($needle, $document, "سند «{$needle}» را توضیح نمی‌دهد.");
        }
    }

    /**
     * ⚠️ نوشتن هرگز تلاشِ دوباره نمی‌شود.
     *
     * این ناوردا پیش از R62 هم برقرار بود، ولی حالا که «آفلاین» حالتِ
     * صریحی شده، برداشتنش وسوسه‌انگیزتر است: «کاربر آفلاین بود، بگذار
     * خودمان دوباره بفرستیم». آن یعنی دو پرداخت.
     */
    public function test_writes_are_never_retried_automatically(): void
    {
        $code = (string) File::get(resource_path('js/shared/lib/apiError.ts'));

        $this->assertStringContainsString(
            'if (!IDEMPOTENT_METHODS.has(method.toUpperCase())) return false',
            $code,
            'محافظِ «متدِ غیرِ idempotent تلاشِ دوباره نمی‌شود» برداشته شده است.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * حوزه‌های نوشتنیِ `/api/v1` — اولین بخشِ مسیر پس از پیشوند.
     *
     * @return list<string>
     */
    private function writeAreas(): array
    {
        $areas = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            if (! array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                continue;
            }

            $areas[] = explode('/', $uri)[2];
        }

        $areas = array_values(array_unique($areas));
        sort($areas);

        return $areas;
    }

    /** @return list<string> */
    private function frontendSources(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
