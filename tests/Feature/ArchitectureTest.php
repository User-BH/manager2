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
}
