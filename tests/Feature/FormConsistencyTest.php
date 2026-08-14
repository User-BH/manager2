<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * یکسانیِ فرم‌ها: React Hook Form + Zod (R40).
 *
 * ─── چرا نگهبانِ ساختاری و نه صرفاً تبدیلِ یک‌باره ─────────────────────────
 * تبدیلِ فرم‌های موجود کارِ یک‌بار است؛ نگه‌داشتنِ قاعده کارِ همیشه. بدونِ
 * این تست، فرمِ بعدی که کسی اضافه کند دوباره با `useState` و بررسیِ دستی
 * نوشته می‌شود و هیچ‌چیز جلویش را نمی‌گیرد.
 */
class FormConsistencyTest extends TestCase
{
    /**
     * فرم‌هایی که عمداً RHF ندارند — و دلیلشان.
     *
     * ⚠️ فهرست عمداً کوتاه و مستدل است. هر افزودنی به آن باید دلیلِ
     * نوشته‌شده داشته باشد، وگرنه استثنا تبدیل به قاعده می‌شود.
     *
     * @var array<string,string>
     */
    private const EXEMPT = [
        // فرمِ **مخفیِ** POST به درگاه بانکی؛ ورودی‌هایش hidden است و کاربر
        // هیچ‌چیزی در آن پر نمی‌کند. اعتبارسنجی معنایی ندارد.
        'AccountPage.tsx' => 'فرم مخفی ارسال به درگاه بانکی',

        // جعبه‌ی پیامِ چت: یک فیلدِ متنی که تنها قاعده‌اش «خالی نباشد» است.
        // RHF اینجا فقط سربار است، نه ساختار.
        'SupportChat.tsx' => 'جعبه‌ی پیام چت؛ بدون قاعده‌ی اعتبارسنجی',

        // جعبه‌ی جستجو: ورودی‌اش هیچ قاعده‌ای ندارد و نتیجه‌اش هم چیزی را
        // ثبت نمی‌کند.
        'SearchBox.tsx' => 'جستجو؛ نه اعتبارسنجی دارد نه ثبت',
    ];

    /**
     * @return array<int,string>
     */
    private function componentFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * هر فایلی که `<form>` دارد باید یا RHF داشته باشد یا در فهرستِ استثنا
     * باشد.
     */
    public function test_every_form_uses_react_hook_form(): void
    {
        $offenders = [];

        foreach ($this->componentFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, '<form')) {
                continue;
            }

            $name = basename($path);

            if (isset(self::EXEMPT[$name])) {
                continue;
            }

            /*
             * ⚠️ دنبالِ **فراخوانی** می‌گردیم، نه نامِ شناسه.
             *
             * `str_contains($source, 'useForm')` در پاسِ خرابکاری پوچ
             * درآمد: خطِ `import { useForm } from …` خودش آن نام را دارد،
             * پس حتی اگر فرم اصلاً از آن استفاده نکند تست سبز می‌ماند.
             */
            if (! preg_match('/=\s*useForm[<(]/', $source)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'این فرم‌ها بدونِ React Hook Form نوشته شده‌اند: '.implode(', ', $offenders),
        );
    }

    /**
     * ⚠️ `useForm` بدونِ `zodResolver` یعنی فرمی که اعتبارسنجی ندارد.
     *
     * ظاهرش یکسان است — همان `handleSubmit` و همان `formState` — ولی هیچ
     * قاعده‌ای اجرا نمی‌شود و کاربر هر چیزی بفرستد به سرور می‌رود. تنها
     * جایی که سرِ فرم می‌ماند، پیامِ خطای سرور است.
     */
    public function test_every_react_hook_form_has_a_zod_resolver(): void
    {
        $offenders = [];

        foreach ($this->componentFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (! preg_match('/=\s*useForm[<(]/', $source)) {
                continue;
            }

            /*
             * ⚠️ همان تله: `zodResolver` در خطِ import هم هست. آنچه اهمیت
             * دارد این است که واقعاً به‌عنوانِ `resolver` به فرم داده شود.
             */
            if (! preg_match('/resolver:\s*zodResolver\(/', $source)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'این فرم‌ها useForm دارند ولی اعتبارسنجی ندارند: '.implode(', ', $offenders),
        );
    }

    /**
     * ⚠️ دکمه‌ی ارسال نباید با «طولِ ورودی» غیرفعال شود.
     *
     * ─── چرا این یک باگِ تجربه‌ی کاربری است ────────────────────────────────
     * `disabled={phone.length < 11}` دکمه را خاموش نگه می‌دارد بی‌آنکه
     * بگوید **چرا**. کاربر فرم را پر می‌کند، دکمه همچنان خاکستری است، و
     * هیچ پیامی نمی‌بیند — چون پیام فقط پس از ارسال می‌آید و ارسالی در کار
     * نیست. صفحه‌خوان هم فقط «دکمه، غیرفعال» می‌گوید.
     *
     * درست: دکمه فعال بماند و پس از کلیک، پیامِ روشن زیرِ فیلدِ مشکل‌دار
     * بنشیند — که همان کاری است که `handleSubmit` خودش می‌کند.
     */
    public function test_submit_buttons_are_never_disabled_by_input_length(): void
    {
        $offenders = [];

        foreach ($this->componentFiles() as $path) {
            $source = (string) file_get_contents($path);

            /*
             * ⚠️ کامنت‌ها اول برداشته می‌شوند — سومین بار است که این تله را
             * می‌خورم (R37، R38، و اینجا). توضیحِ بالای کدِ اصلاح‌شده که
             * می‌نویسد «پیش از این `disabled={phone.length < 11}` بود…»
             * خودش الگو را دارد و تست روی کدِ درست قرمز می‌شود.
             */
            $code = preg_replace(['/\/\*.*?\*\//s', '/\{\/\*.*?\*\/\}/s'], '', $source) ?? $source;

            if (preg_match('/disabled=\{[^}]*\.length\s*[<>]/', $code)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'دکمه‌ای که با طولِ ورودی خاموش می‌شود: '.implode(', ', $offenders),
        );
    }

    /**
     * هر اسکیمای Zod باید پیامِ فارسی داشته باشد.
     *
     * پیامِ پیش‌فرضِ Zod انگلیسی است («String must contain at least 8
     * character(s)») و در فرمِ فارسی وسطِ صفحه می‌نشیند.
     */
    public function test_every_zod_rule_carries_a_persian_message(): void
    {
        $schemas = glob(resource_path('js/features/*/schemas/*.ts'))
            ?: [];

        $this->assertNotEmpty($schemas, 'هیچ اسکیمایی پیدا نشد؛ مسیر عوض شده؟');

        foreach ($schemas as $path) {
            $source = (string) file_get_contents($path);

            /*
             * قاعده‌هایی که پیام می‌پذیرند: `.min(`, `.max(`, `.regex(`.
             * هرکدام باید آرگومانِ دومِ متنی داشته باشد.
             */
            preg_match_all('/\.(min|max|regex)\(([^)]*)\)/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $this->assertMatchesRegularExpression(
                    '/[\x{0600}-\x{06FF}]/u',
                    $match[2],
                    basename($path)." — قاعده‌ی «{$match[1]}» پیامِ فارسی ندارد: {$match[0]}",
                );
            }
        }
    }
}
