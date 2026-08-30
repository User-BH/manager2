<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PrivateFiles;
use App\Support\Uploads;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * سطحِ تزریق: SSRF، پیمایشِ مسیر، اجرای فرمان و آپلود (R56).
 *
 * ─── چه چیزی اندازه‌گیری شد، پیش از نوشتنِ هر تستی ───────────────────────────
 * برنامه‌ی مرحله می‌گفت «`mimes:` فقط پسوند و MIMEِ اعلامی را می‌بیند». سنجش
 * نشان داد این **نیمه‌درست** است:
 *
 *     PHPِ خام با پسوندِ jpg      mime=text/x-php        → رد شد ✅
 *     HTML با پسوندِ png          mime=text/html         → رد شد ✅
 *     SVG با اسکریپت             mime=image/svg+xml     → رد شد ✅
 *     PHP با هدرِ جعلیِ JPEG      mime=image/jpeg        → ⚠️ پذیرفته شد
 *
 * یعنی لاراول محتوا را با `finfo` می‌خواند (نه پسوند را)، ولی **polyglot** —
 * فایلی که هم هدرِ JPEG دارد و هم کدِ PHP — از آن رد می‌شود.
 *
 * ⚠️ و آن polyglot در این پروژه بی‌خطر است، به سه دلیلِ سنجیده‌شده که این
 * تست‌ها هر سه را قفل می‌کنند: نامِ ذخیره **تصادفی** است (نامِ کلاینت هرگز
 * نامِ فایل نمی‌شود)، نوعِ محتوا هنگامِ سرو از یک فهرستِ سفید می‌آید، و
 * فایل‌های خصوصی اصلاً از مسیرِ وب سرو نمی‌شوند.
 *
 * چیزی که سمتِ کد نمی‌شود تضمینش کرد — «PHP در پوشه‌ی آپلود اجرا نشود» — به
 * [`SERVER_HARDENING.md`](../../docs/SERVER_HARDENING.md) رفت.
 */
class InjectionSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'complex_id' => null,
            'name' => 'ادمینِ کل',
            'phone' => '09350000001',
            'role' => UserRole::SuperAdmin,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    /** بدنه‌ی کاملِ تنظیماتِ فوتر، با یک مقدارِ جایگزین‌شده. */
    private function footerPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'contact' => [
                'title' => 'ارتباط با ما',
                'address' => 'تهران',
                'phone' => '02100000000',
                'email' => 'info@example.com',
                'mapEmbedUrl' => 'https://maps.google.com/maps?q=1,1&output=embed',
                'showAddress' => true,
                'showPhone' => true,
                'showEmail' => true,
                'showMap' => true,
            ],
            'social' => [
                ['id' => 'instagram', 'label' => 'اینستاگرام', 'href' => 'https://instagram.com/x', 'enabled' => true],
            ],
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① آدرسی که در صفحه‌ی عمومی رندر می‌شود
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousSchemes(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'data با HTML' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file محلی' => ['file:///etc/passwd'],
            'بدونِ طرح' => ['//evil.example.com/x'],
        ];
    }

    /**
     * ⚠️ لینکِ شبکه‌های اجتماعی در `<a href>`ِ فوترِ صفحه‌ی فرود می‌نشیند.
     *
     * ─── چرا این نقص باز مانده بود ──────────────────────────────────────
     * دقیقاً همین محافظت از قبل روی `href`ِ **بنرِ تبلیغاتی** بود، با
     * توضیحی که می‌گفت «`javascript:...` در href می‌نشست و صفحه‌ی فرودِ
     * عمومی به ناقل XSS تبدیل می‌شد».
     *
     * ولی همان رفع به همسایه‌اش نرسیده بود: فوترِ **همان صفحه** فقط
     * `['nullable', 'string', 'max:300']` داشت.
     */
    #[DataProvider('dangerousSchemes')]
    public function test_a_social_link_must_be_an_http_url(string $href): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/site-settings', $this->footerPayload([
                'social' => [['href' => $href]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('social.0.href');
    }

    /**
     * ⚠️ آدرسِ نقشه در `<iframe src>` می‌نشیند — که بدتر است.
     *
     * `data:text/html,...` در `src`ِ یک iframe، سندِ کاملِ مهاجم را داخلِ
     * صفحه بالا می‌آورد.
     */
    #[DataProvider('dangerousSchemes')]
    public function test_the_map_url_must_be_an_http_url(string $url): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/site-settings', $this->footerPayload([
                'contact' => ['mapEmbedUrl' => $url],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact.mapEmbedUrl');
    }

    /** و فرمِ عادی باید همچنان کار کند — وگرنه محافظت، خرابی است. */
    public function test_a_normal_footer_still_saves(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/site-settings', $this->footerPayload())
            ->assertOk()
            ->assertJsonPath('footer.social.0.href', 'https://instagram.com/x');
    }

    /**
     * ⚠️ شبکه‌ی اجتماعیِ خاموش با `href: ''` فرستاده می‌شود.
     *
     * `nullable` فقط `null` را رد می‌کند نه `''`؛ بدونِ تبدیلِ پیش از
     * اعتبارسنجی، افزودنِ قاعده‌ی `url` همان فرمِ عادی را می‌شکست.
     */
    public function test_an_empty_link_is_still_allowed(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/site-settings', $this->footerPayload([
                'contact' => ['mapEmbedUrl' => ''],
                'social' => [['href' => '', 'enabled' => false]],
            ]))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② آپلود — آنچه `mimes:` می‌گیرد و آنچه نمی‌گیرد
    // ─────────────────────────────────────────────────────────────────────

    /** فایلِ واقعی روی دیسک، چون `UploadedFile::fake()` نوع را از پسوند می‌سازد. */
    private function realFile(string $name, string $content): UploadedFile
    {
        $dir = storage_path('framework/testing/injection');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function onePixelPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    private function passesUploadRules(UploadedFile $file): bool
    {
        return Validator::make(
            ['f' => $file],
            ['f' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096']],
        )->passes();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenUploads(): array
    {
        return [
            'PHPِ خام با پسوندِ jpg' => ['evil.jpg', '<?php system($_GET["c"]); ?>'],
            'HTML با پسوندِ png' => ['x.png', '<html><script>alert(1)</script></html>'],
            'SVG با اسکریپت' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'اسکریپتِ شل با پسوندِ pdf' => ['x.pdf', "#!/bin/sh\nrm -rf /\n"],
        ];
    }

    /**
     * ⚠️ ادعا این است که `mimes:` **محتوا** را می‌بیند، نه پسوند را.
     *
     * اگر روزی کسی این قاعده را به `mimetypes:` (که به هدرِ اعلامیِ کلاینت
     * تکیه می‌کند) یا به بررسیِ پسوند عوض کند، این تست قرمز می‌شود.
     */
    #[DataProvider('forbiddenUploads')]
    public function test_the_upload_rules_read_the_content_not_the_extension(string $name, string $content): void
    {
        $this->assertFalse(
            $this->passesUploadRules($this->realFile($name, $content)),
            "فایلِ «{$name}» با محتوای غیرتصویری پذیرفته شد.",
        );
    }

    /** و تصویرِ واقعی باید بگذرد، وگرنه ادعای بالا فقط «همه‌چیز رد می‌شود» است. */
    public function test_a_real_image_still_passes(): void
    {
        $this->assertTrue($this->passesUploadRules($this->realFile('real.png', $this->onePixelPng())));
    }

    /**
     * ⚠️ **نقصِ شناخته‌شده، عمداً مستند:** polyglot از اعتبارسنجی رد می‌شود.
     *
     * فایلی که با `\xFF\xD8\xFF\xE0JFIF` شروع شود، از دیدِ `finfo` یک JPEG
     * است — هر چه بعدش بیاید. این تست همان واقعیت را **ثبت** می‌کند تا
     * کسی به‌غلط فکر نکند اعتبارسنجی کافی است.
     *
     * دفاعِ واقعی سه لایه‌ی بعدی است که در تست‌های پایین قفل شده‌اند، و
     * لایه‌ی سرور در `SERVER_HARDENING.md`.
     */
    public function test_a_polyglot_passes_validation_and_that_is_why_the_other_layers_exist(): void
    {
        $polyglot = $this->realFile(
            'polyglot.jpg',
            "\xFF\xD8\xFF\xE0".'JFIF'.'<?php system($_GET["c"]); ?>',
        );

        $this->assertSame('image/jpeg', $polyglot->getMimeType());

        $this->assertTrue(
            $this->passesUploadRules($polyglot),
            'اگر این روزی رد شود، خبرِ خوبی است — ولی توضیحِ بالا و لایه‌های '
            .'بعدی باید بازخوانی شوند.',
        );
    }

    /**
     * ⚠️ نامِ کلاینت هرگز نامِ فایلِ ذخیره‌شده نمی‌شود.
     *
     * نام از سمتِ فرستنده کاملاً کنترل‌پذیر است. اگر همان نام روی دیسک
     * بنشیند، مهاجم پسوند و مسیر را انتخاب کرده است.
     */
    public function test_an_uploaded_file_never_keeps_the_client_name(): void
    {
        Storage::fake(PrivateFiles::name());

        $file = $this->realFile('polyglot.jpg', "\xFF\xD8\xFF\xE0".'JFIF'.'<?php echo 1;');

        $stored = Uploads::keepIf($file, 'receipts', fn (string $path) => $path);

        $this->assertStringStartsWith('receipts/', $stored);
        $this->assertStringNotContainsString('polyglot', $stored, 'نامِ کلاینت روی دیسک نشست.');
        $this->assertSame('jpg', pathinfo($stored, PATHINFO_EXTENSION));
        $this->assertGreaterThan(20, strlen(basename($stored, '.jpg')), 'نامِ ذخیره تصادفی نیست.');
    }

    /**
     * ⚠️ نوعِ محتوا هنگامِ سرو از **فهرستِ سفید** می‌آید، نه از حدسِ محتوا.
     *
     * پسوندِ ناشناخته باید `octet-stream` شود: مرورگر دانلودش می‌کند و
     * هرگز اجرا/رندرش نمی‌کند.
     */
    public function test_an_unknown_extension_is_served_as_an_opaque_download(): void
    {
        Storage::fake(PrivateFiles::name());
        PrivateFiles::disk()->put('receipts/x.php', '<?php echo 1;');

        $response = Uploads::serve('receipts/x.php');

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    /** و پسوندِ مجاز باید نوعِ درستِ خودش را بگیرد. */
    public function test_an_allowed_extension_keeps_its_type(): void
    {
        Storage::fake(PrivateFiles::name());
        PrivateFiles::disk()->put('receipts/x.png', $this->onePixelPng());

        $this->assertSame(
            'image/png',
            Uploads::serve('receipts/x.png')->headers->get('Content-Type'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ پیمایشِ مسیر
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ دیسکِ خصوصی `'serve' => true` دارد، یعنی لاراول دو مسیرِ
     * `GET/PUT storage/{path}` می‌سازد که **هیچ میدل‌وری ندارند**.
     *
     * ─── چرا این تست نوشته شد ───────────────────────────────────────────
     * `storage/app/private` رسیدِ پرداخت، پیوستِ پیام و بکاپ را نگه
     * می‌دارد. دیدنِ آن دو مسیر در `route:list` بدونِ هیچ میدل‌وری، نگرانی
     * جدی بود. سنجش نشان داد امضای URL محافظشان است:
     *
     *     GET  /storage/receipts/secret.txt   → 403
     *     GET  /storage/../../../.env         → 403
     *     PUT  /storage/evil.php              → 403 (فایلی ساخته نشد)
     *
     * ولی این یک **پیش‌فرضِ لاراول** است، نه تصمیمِ ما — و یک تغییرِ
     * پیکربندی یا نسخه می‌تواند عوضش کند. پس قفل می‌شود.
     */
    public function test_the_private_storage_route_refuses_an_unsigned_request(): void
    {
        PrivateFiles::disk()->put('receipts/secret.txt', 'محتوای خصوصی');

        $this->get('/storage/receipts/secret.txt')->assertForbidden();
        $this->put('/storage/evil.php')->assertForbidden();

        $this->assertFalse(
            PrivateFiles::disk()->exists('evil.php'),
            'درخواستِ PUTِ بی‌امضا فایل ساخت.',
        );
    }

    /** و پیمایشِ مسیر هم از همان‌جا رد می‌شود. */
    public function test_a_traversal_path_is_refused(): void
    {
        foreach (['../../../.env', '..%2F..%2F.env', 'receipts/../../../.env'] as $path) {
            $this->get('/storage/'.$path)->assertForbidden();
        }
    }

    /**
     * ⚠️ و خودِ آداپتورِ دیسک هم بیرون از ریشه‌اش را نمی‌خواند.
     *
     * این لایه‌ی دوم است: اگر روزی مسیری بدونِ امضا هم ساخته شود، خواندنِ
     * `../` باز هم استثنا می‌دهد نه فایل.
     */
    public function test_the_disk_itself_blocks_a_path_outside_its_root(): void
    {
        /*
         * ⚠️ آداپتور **استثنا می‌اندازد**، نه اینکه `false` برگرداند.
         *
         * اولین شکلِ این تست `assertFalse` بود و با
         * `PathTraversalDetected` ترکید. رفتارِ واقعی از آنچه انتظار داشتم
         * سخت‌گیرتر است، و ادعا با همان تنظیم شد — نه برعکس.
         *
         * ⚠️ `'throw' => false` در پیکربندیِ دیسک این را خاموش نمی‌کند؛ آن
         * پرچم فقط خطاهای ورودی/خروجی را می‌بلعد.
         */
        $this->expectException(PathTraversalDetected::class);

        PrivateFiles::disk()->exists('../../../.env');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ اجرای فرمان و SSRF — ساختاری
    // ─────────────────────────────────────────────────────────────────────

    /**
     * متنِ همه‌ی فایل‌های PHPِ برنامه، **بدونِ کامنت**.
     *
     * ⚠️ پاک‌سازی اجباری است: همین فایل‌ها در توضیحشان از `exec` و
     * `shell_exec` نام می‌برند. دو الگوی جدا لازم است — یکی‌کردنشان با
     * پرچمِ `s` باعث می‌شود `//.*` تا آخرِ فایل را ببلعد.
     *
     * @return array<string, string>
     */
    private function applicationSources(): array
    {
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
            $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));

            $out[$relative] = $code;
        }

        return $out;
    }

    /**
     * ⚠️ هیچ‌جای برنامه فرمانِ سیستمی اجرا نمی‌کند.
     *
     * اندازه‌گیریِ امروز صفر مورد پیدا کرد. این تست آن صفر را نگه می‌دارد:
     * اولین `exec()`ی که کسی اضافه کند باید تصمیمِ آگاهانه باشد، نه چیزی
     * که در یک مرورِ کد از قلم بیفتد.
     *
     * بکاپ و بازیابی هم عمداً با PHP نوشته شده‌اند، نه با `mysqldump` —
     * پس هیچ رشته‌ای به پوسته نمی‌رود.
     */
    public function test_the_application_never_runs_a_shell_command(): void
    {
        $forbidden = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'];

        foreach ($this->applicationSources() as $path => $code) {
            foreach ($forbidden as $function) {
                $this->assertSame(
                    0,
                    preg_match('/(?<![a-zA-Z0-9_>$])'.$function.'\s*\(/', $code),
                    "«{$path}» تابعِ «{$function}()» را صدا می‌زند. اجرای فرمانِ "
                    .'سیستمی در این پروژه مجاز نیست؛ اگر واقعاً لازم است، '
                    .'باید اینجا با دلیلش ثبت شود.',
                );
            }
        }
    }

    /**
     * ⚠️ هیچ درخواستِ بیرونی‌ای آدرسش را از ورودیِ کاربر نمی‌گیرد (SSRF).
     *
     * اندازه‌گیری: هفت نقطه `Http::` صدا می‌زنند و **همه‌شان** آدرسِ ثابت
     * یا `config()` دارند:
     *
     *     MellatGateway   → const WSDL / STARTPAY (bpm.shaparak.ir)
     *     SamanGateway    → const TOKEN_URL / VERIFY_URL
     *     IppanelSms      → const ENDPOINT
     *     KavenegarSms    → آدرسِ ثابت با کلید در مسیر
     *     MelipayamakSms  → آدرسِ ثابت
     *     HealthAlerter   → config('health.alert.webhook') — فقط از `.env`
     *
     * ⚠️ نکته‌ی مهم این است که `gateway_config` و `sms_config` از پنل
     * قابلِ ویرایش‌اند، ولی **آدرس** جزوشان نیست — فقط کلید و شناسه. اگر
     * روزی آدرس هم پیکربندی‌پذیر شود، مدیرِ یک مجتمع می‌تواند سرور را به
     * `169.254.169.254` یا `localhost` بفرستد.
     */
    public function test_no_outbound_request_takes_its_url_from_user_input(): void
    {
        /*
         * ⚠️ الگوی اول پوچ بود و خرابکاری لوش داد.
         *
         * `([^,)]+)` روی `request()->input('webhook')` سرِ اولین پرانتزِ
         * بسته می‌ایستاد و فقط `request(` را می‌گرفت — که با هیچ‌کدام از
         * نشانه‌های ورودیِ کاربر نمی‌خورد. یعنی جایگذاریِ عمدیِ یک آدرسِ
         * کاربرمحور از دستش در رفت.
         *
         * شکلِ تازه یک سطح پرانتزِ تودرتو را می‌پذیرد، پس آرگومانِ اول را
         * **کامل** می‌گیرد.
         */
        $firstArgument = '/->(?:get|post|put|patch|delete|send)\(\s*((?:[^,()]|\([^()]*\))+)/';

        $checked = 0;

        foreach ($this->applicationSources() as $path => $code) {
            foreach (explode('Http::', $code) as $index => $chunk) {
                if ($index === 0) {
                    continue;
                }

                $statement = explode(';', $chunk)[0];

                if (preg_match($firstArgument, $statement, $match) !== 1) {
                    continue;
                }

                $checked++;
                $target = trim($match[1]);

                $this->assertSame(
                    0,
                    preg_match('/\$request|->input\(|->query\(|request\(\)/', $target),
                    "«{$path}» آدرسِ درخواستِ بیرونی را از ورودیِ کاربر می‌گیرد: {$target}",
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            5,
            $checked,
            'تعدادِ فراخوانی‌های سنجیده‌شده مشکوک است؛ احتمالاً الگو دیگر چیزی نمی‌گیرد.',
        );
    }
    // ─────────────────────────────────────────────────────────────────────
    //  ⑤ نیمه‌ی سرور — که کد نمی‌تواند تضمینش کند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ سه چیز هست که هیچ تستی نمی‌تواند اثباتشان کند.
     *
     * اجرا نشدنِ PHP در پوشه‌ی آپلود، بسته بودنِ پورتِ دیتابیس، و
     * نوشتنی‌نبودنِ فایل‌های کد — هر سه تصمیمِ **سرور**اند نه برنامه.
     *
     * خروجیِ آن نیمه یک سند است، نه کد. و چون سند بی‌دروازه کهنه می‌شود،
     * این تست دستِ‌کم مطمئن می‌شود سرفصل‌هایش پاک نشده‌اند.
     *
     * ⚠️ این ادعا نمی‌کند که چک‌لیست **اجرا شده**؛ فقط اینکه نوشته شده و
     * کامل است. اجرایش کارِ ادمینِ سرور است و وضعیتش NOT VERIFIED می‌ماند.
     */
    public function test_the_server_checklist_covers_every_required_area(): void
    {
        $path = base_path('docs/SERVER_HARDENING.md');

        $this->assertFileExists($path);

        $checklist = (string) file_get_contents($path);

        $areas = [
            'آپلود' => 'آپلودها',
            'مجوزِ فایل' => 'مجوزهای فایل',
            'دیتابیس و Redis' => 'دیتابیس و Redis',
            'SSH' => 'SSH',
            'TLS' => 'TLS',
            'php.ini' => 'php.ini',
            'لاگ' => 'لاگ',
            'به‌روزرسانی' => 'به‌روزرسانی',
        ];

        foreach ($areas as $label => $heading) {
            $this->assertStringContainsString(
                $heading,
                $checklist,
                "چک‌لیستِ سرور بخشِ «{$label}» را ندارد.",
            );
        }

        $this->assertGreaterThanOrEqual(
            20,
            substr_count($checklist, '- [ ]'),
            'چک‌لیست بندهای اجرایی‌اش را از دست داده است.',
        );
    }

    /**
     * و دو دستوری که چک‌لیست توصیه می‌کند باید واقعاً وجود داشته باشند.
     *
     * ⚠️ همان تله‌ی R42: `deploy.yml` نسخه‌ی اولش `php artisan backup:run`
     * می‌نوشت — دستوری که هرگز وجود نداشت. سندی که دستورِ ناموجود توصیه
     * کند، بدتر از سندِ نداشته است.
     */
    public function test_the_commands_the_checklist_recommends_exist(): void
    {
        $available = array_keys(app(Kernel::class)->all());

        preg_match_all(
            '/php artisan ([a-z][a-z0-9:_-]*)/',
            (string) file_get_contents(base_path('docs/SERVER_HARDENING.md')),
            $matches,
        );

        $this->assertNotEmpty($matches[1], 'چک‌لیست هیچ دستوری توصیه نمی‌کند؛ الگو خراب است.');

        foreach (array_unique($matches[1]) as $command) {
            $this->assertContains(
                $command,
                $available,
                "چک‌لیستِ سرور «php artisan {$command}» را توصیه می‌کند ولی چنین دستوری وجود ندارد.",
            );
        }
    }
}
