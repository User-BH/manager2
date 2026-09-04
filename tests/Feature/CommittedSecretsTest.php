<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * هیچ رازی در گیت نباشد — گامِ ۸ خطِ انتشار (R71).
 *
 * ─── 🔴 شکافی که `.gitignore` نمی‌بندد ──────────────────────────────────────
 * `EnvironmentTest` می‌سنجد که فایل‌های `.env` در `.gitignore` باشند. ولی
 * **`.gitignore` فایلِ ازپیش‌ردیابی‌شده را از ردیابی خارج نمی‌کند.**
 *
 * ⚠️ یعنی اگر کسی یک بار `.env` یا کلیدِ خصوصی را کامیت کرده باشد و بعد
 * الگویش را به `.gitignore` اضافه کند، آن تست سبز می‌ماند و راز همچنان در
 * تاریخچه و در درختِ کاری است.
 *
 * این تست از **خودِ فهرستِ ردیابی‌شده‌ی گیت** می‌پرسد، نه از الگوها.
 *
 * ─── چرا اسکنِ امنیتیِ این پروژه همین است ───────────────────────────────────
 * ⚠️ CodeQL برای مخزنِ خصوصی به GitHub Advanced Security (پولی) نیاز دارد و
 * در دسترس نیست. بقیه‌ی سطحِ امنیتی از قبل با تست پوشش داده شده:
 * `InjectionSurfaceTest`، `AuthorizationAttackTest`، `UploadSecurityTest`،
 * `MassAssignmentTest`، `SecretHandlingTest`، `SecurityHeadersTest`.
 *
 * آنچه هیچ‌کدام نمی‌سنجیدند همین بود: **چه چیزی واقعاً کامیت شده است.**
 */
class CommittedSecretsTest extends TestCase
{
    /**
     * مسیرهایی که هرگز نباید ردیابی شوند.
     *
     * ⚠️ الگوها روی **کلِ مسیر** اجرا می‌شوند و عمداً تنگ‌اند: الگوی گشاد
     * (مثلاً هر فایلی که کلمه‌ی credentials در نامش باشد) مهاجرتِ
     * `encrypt_stored_credentials` را متهم می‌کند و محافظ را به نویز
     * تبدیل می‌کند — و محافظِ پرنویز، همان است که خاموشش می‌کنند.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenPaths(): array
    {
        return [
            'فایلِ محیطیِ واقعی' => ['#(^|/)\.env(\.|$)(?!.*example)#', 'رمزِ دیتابیس و APP_KEY در آن است'],
            'کلیدِ خصوصی' => ['#\.(pem|key|p12|pfx|jks|keystore)$#i', 'کلیدِ خصوصی یا گواهی'],
            'کلیدِ SSH' => ['#(^|/)id_(rsa|dsa|ecdsa|ed25519)$#', 'کلیدِ خصوصیِ SSH'],
            'دامپِ دیتابیس' => ['#\.(sql|sql\.gz|dump)$#i', 'دادهٔ واقعیِ ساکنین'],
            'دیتابیسِ محلی' => ['#\.sqlite3?$#i', 'دادهٔ واقعی یا آزمایشی'],
            'فایلِ اعتبارنامهٔ ابری' => ['#(^|/)(gcloud|aws|azure)[-_]?credentials?\.json$#i', 'اعتبارنامهٔ سرویسِ ابری'],
        ];
    }

    /**
     * الگوهای پرسیگنالِ محتوا.
     *
     * ⚠️ فقط چیزهایی که **تقریباً همیشه** راز واقعی‌اند. الگوی «هر رشته‌ی
     * بلندِ تصادفی» هزار خطای کاذب می‌سازد و اولین کاری که تیم می‌کند
     * نادیده‌گرفتنش است.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenContent(): array
    {
        return [
            'سرآیندِ کلیدِ خصوصی' => ['/-----BEGIN [A-Z ]*PRIVATE KEY-----/', 'کلیدِ خصوصی در متنِ فایل'],
            'شناسهٔ کلیدِ AWS' => ['/\bAKIA[0-9A-Z]{16}\b/', 'شناسهٔ کلیدِ دسترسیِ AWS'],
            'APP_KEY پرشده' => ['/APP_KEY=base64:[A-Za-z0-9+\/]{40,}/', 'کلیدِ رمزنگاریِ واقعیِ برنامه'],
            'توکنِ گیت‌هاب' => ['/\bgh[pousr]_[A-Za-z0-9]{36,}\b/', 'توکنِ دسترسیِ گیت‌هاب'],
        ];
    }

    /**
     * فایل‌های ردیابی‌شده — از خودِ گیت، نه از پیمایشِ پوشه.
     *
     * ⚠️ تفاوتش حیاتی است: پیمایشِ پوشه فایلِ نادیده‌گرفته‌شده را هم
     * می‌بیند و خطای کاذب می‌سازد؛ و مهم‌تر، فایلی که گیت ردیابی می‌کند
     * ولی روی دیسک نیست را از دست می‌دهد.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $output = [];
        $status = 0;

        exec('git -C '.escapeshellarg(base_path()).' ls-files 2>&1', $output, $status);

        $this->assertSame(0, $status, 'فهرستِ فایل‌های گیت خوانده نشد.');
        $this->assertNotEmpty($output, 'گیت هیچ فایلی برنگرداند؛ محافظ پوچ می‌شود.');

        return $output;
    }

    #[DataProvider('forbiddenPaths')]
    public function test_no_forbidden_path_is_tracked(string $pattern, string $why): void
    {
        $offenders = array_values(array_filter(
            $this->trackedFiles(),
            fn (string $path): bool => preg_match($pattern, $path) === 1,
        ));

        $this->assertSame(
            [],
            $offenders,
            "این فایل‌ها در گیت‌اند و نباید باشند ({$why}):\n  ".implode("\n  ", $offenders)
            ."\n⚠️ افزودن به .gitignore کافی نیست؛ باید با `git rm --cached` از ردیابی خارج شوند.",
        );
    }

    #[DataProvider('forbiddenContent')]
    public function test_no_tracked_file_contains_a_secret(string $pattern, string $why): void
    {
        $offenders = [];

        foreach ($this->trackedFiles() as $path) {
            $full = base_path($path);

            // فایلِ بزرگ یا دودویی خوانده نمی‌شود: نه رازِ متنی دارد نه ارزشِ وقت
            if (! is_file($full) || filesize($full) > 512_000) {
                continue;
            }

            $contents = (string) @file_get_contents($full);

            if ($contents !== '' && preg_match($pattern, $contents) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این فایل‌ها محتوای رازگونه دارند ({$why}):\n  ".implode("\n  ", $offenders),
        );
    }

    /**
     * ⚠️ محافظ باید ثابت کند که واقعاً می‌گیرد.
     *
     * بدونِ این، الگویی که اشتباه نوشته شده باشد (مثلاً همیشه خالی
     * برگرداند) هم سبز می‌ماند — و آن‌وقت اسکنِ امنیتی فقط یک اسمِ سبز در
     * گزارشِ CI است.
     */
    public function test_the_patterns_actually_match_a_secret(): void
    {
        $samples = [
            'سرآیندِ کلیدِ خصوصی' => "-----BEGIN RSA PRIVATE KEY-----\nMIIE...",
            'شناسهٔ کلیدِ AWS' => 'aws_access_key_id = AKIAIOSFODNN7EXAMPLE',
            'APP_KEY پرشده' => 'APP_KEY=base64:'.str_repeat('A', 43),
            'توکنِ گیت‌هاب' => 'token: ghp_'.str_repeat('a', 36),
        ];

        foreach (self::forbiddenContent() as $name => [$pattern]) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $samples[$name],
                "الگوی «{$name}» نمونه‌ی واقعیِ همان راز را نمی‌گیرد.",
            );
        }

        foreach ([['.env', 'فایلِ محیطیِ واقعی'], ['deploy/server.pem', 'کلیدِ خصوصی'], ['dump.sql', 'دامپِ دیتابیس']] as [$path, $name]) {
            $this->assertSame(
                1,
                preg_match(self::forbiddenPaths()[$name][0], $path),
                "الگوی «{$name}» مسیرِ «{$path}» را نمی‌گیرد.",
            );
        }
    }

    /** ⚠️ و نباید چیزی را بگیرد که راز نیست، وگرنه خاموشش می‌کنند. */
    public function test_the_patterns_leave_legitimate_files_alone(): void
    {
        $safe = [
            '.env.example',
            '.env.production.example',
            'database/migrations/2026_08_27_000001_encrypt_stored_credentials.php',
            'app/Support/SecretValue.php',
        ];

        foreach ($safe as $path) {
            foreach (self::forbiddenPaths() as $name => [$pattern]) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $path),
                    "الگوی «{$name}» فایلِ سالمِ «{$path}» را متهم کرد.",
                );
            }
        }
    }
}
