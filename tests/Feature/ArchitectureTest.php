<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * قیدهای معماری که R9 برقرار کرد.
 *
 * این‌ها از آن دست چیزهایی‌اند که بی‌صدا برمی‌گردند: کسی عجله دارد، یک
 * `$request->validate()` وسطِ کنترلر می‌نویسد، تست‌ها سبز می‌مانند و شش ماه
 * بعد دوباره همان‌جاییم که بودیم. این تست‌ها همان بازگشت را می‌گیرند.
 */
class ArchitectureTest extends TestCase
{
    /** @return list<string> */
    private function controllerFiles(): array
    {
        return array_map(
            fn ($file) => (string) $file,
            File::allFiles(app_path('Http/Controllers')),
        );
    }

    /**
     * `wherePivot()` هرگز نباید درونِ `whereHas()`/`whereDoesntHave()` بیاید.
     *
     * ─── چرا این تست هست ───────────────────────────────────────────────────
     * این باگ **در تولید** رخ داد: کوئریِ «واجدینِ شرایطِ نظرسنجی» با
     * «Unknown column 'pivot'» می‌ترکید. `wherePivot` فقط روی خودِ رابطه‌ی
     * BelongsToMany تعریف شده؛ سازنده‌ای که `whereHas` به کلوژر می‌دهد
     * Builderِ مدلِ **مقصد** است، پس فراخوانی‌اش از راهِ `__call` بی‌صدا به
     * `where('pivot', ...)` تبدیل می‌شود.
     *
     * خطرناک بودنش در همین بی‌صدا بودن است: روی MySQL خطای SQL می‌دهد ولی
     * روی SQLiteِ تست فقط نتیجه‌ی خالی برمی‌گرداند، پس یک تستِ ساده هم
     * ممکن است سبز بماند. راهِ درست، نامِ کاملِ ستونِ جدولِ واسط است
     * (`unit_user.is_current`).
     */
    public function test_where_pivot_is_never_used_inside_where_has(): void
    {
        $offenders = [];

        $files = array_map(
            fn ($file) => (string) $file,
            array_merge(File::allFiles(app_path()), []),
        );

        foreach ($files as $file) {
            $contents = File::get($file);

            /*
             * از `whereHas(` تا انتهای همان فراخوانی را نمی‌شود با regex
             * دقیق گرفت، ولی نیازی هم نیست: وجودِ `wherePivot` در فاصله‌ی
             * کوتاهی پس از `whereHas`/`whereDoesntHave` به‌اندازه‌ی کافی
             * نشانه است و خطای مثبتِ کاذبش عملاً صفر.
             */
            if (preg_match('/where(?:Doesnt)?Has\((?:[^;]{0,400}?)wherePivot\(/s', $contents)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'wherePivot درون whereHas کار نمی‌کند؛ نام کامل ستون جدول واسط را بنویسید: '
                .implode('، ', $offenders),
        );
    }

    public function test_controllers_do_not_validate_inline(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $file) {
            $contents = File::get($file);

            if (str_contains($contents, '$request->validate(') || str_contains($contents, '$this->validate(')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'اعتبارسنجی باید در FormRequest باشد، نه داخل کنترلر: '.implode('، ', $offenders),
        );
    }

    /**
     * مجوزدهی نباید با `abort_*` انجام شود.
     *
     * فهرستِ استثناها عمدی و کوتاه است — هر کدام دلیلی دارد که در خودِ فایل
     * نوشته شده. اگر کسی موردِ تازه‌ای اضافه کند، این تست می‌شکند و مجبور
     * می‌شود یا Policy بنویسد یا دلیلش را اینجا مستند کند.
     */
    public function test_authorization_uses_policies_not_scattered_aborts(): void
    {
        $allowed = [
            // بازگشت از درگاه ممکن است بدونِ نشست برسد، پس authorize() کار نمی‌کند
            'SubscriptionCheckoutController.php' => 1,
            // «کاربرِ نشستِ نیمه‌کاره وجود ندارد» — قاعده‌ی کسب‌وکار، نه دسترسی
            'AuthController.php' => 0,
        ];

        $offenders = [];

        foreach ($this->controllerFiles() as $file) {
            $name = basename($file);
            $contents = File::get($file);

            // فقط ۴۰۳ها مجوزدهی‌اند؛ ۴۰۴ و ۴۲۲ چیزِ دیگری‌اند
            preg_match_all('/abort_(?:if|unless)\([^;]*?403/s', $contents, $matches);
            $found = count($matches[0]);
            $budget = $allowed[$name] ?? 0;

            if ($found > $budget) {
                $offenders[] = "{$name} ({$found} مورد، سقف {$budget})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'مجوزدهی باید در Policy باشد: '.implode('، ', $offenders),
        );
    }

    public function test_every_policy_is_discoverable_by_laravel(): void
    {
        // لاراول Policyها را با قرارداد نام پیدا می‌کند؛ اگر مدلِ متناظر
        // نباشد، Policy بی‌صدا هرگز صدا زده نمی‌شود.
        foreach (File::files(app_path('Policies')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $model = 'App\\Models\\'.str_replace('Policy', '', $file->getFilenameWithoutExtension());

            $this->assertTrue(
                class_exists($model),
                "برای {$file->getFilename()} مدلی به نام {$model} وجود ندارد؛ لاراول پیدایش نمی‌کند.",
            );
        }
    }

    /**
     * تستی که فایل آپلود می‌کند باید `Storage::fake` داشته باشد (R19).
     *
     * بدونِ آن، تست واقعاً در `storage/app/private` می‌نویسد و پوشه‌ی پروژه با
     * فایلِ زباله پر می‌شود — بی‌آنکه چیزی شکست بخورد یا کسی بفهمد. این
     * دقیقاً در جریانِ خودِ R19 یک بار اتفاق افتاد و چهار فایل به جا گذاشت.
     */
    public function test_upload_tests_fake_the_disk(): void
    {
        $offenders = [];

        foreach (File::allFiles(base_path('tests')) as $file) {
            // خودِ این فایل هر دو رشته را به‌عنوان متن دارد و باید کنار برود
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'ArchitectureTest.php') {
                continue;
            }

            $source = File::get($file->getPathname());

            if (str_contains($source, 'UploadedFile::fake')
                && ! str_contains($source, 'Storage::fake')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'این تست‌ها فایل آپلود می‌کنند ولی دیسک را fake نکرده‌اند؛ در storage واقعی می‌نویسند.',
        );
    }
}
