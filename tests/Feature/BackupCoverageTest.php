<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Backup\BackupBuilder;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\FileArchive;
use App\Services\Backup\OffsiteTarget;
use App\Services\Wallet\WalletService;
use App\Support\PrivateFiles;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * پوششِ بکاپ: چه چیزی واقعاً برمی‌گردد (R58).
 *
 * ─── 🔴 نقصی که این مرحله پیدا کرد ─────────────────────────────────────────
 * «بکاپِ کاملِ سیستم» کامل **نبود**. از ۴۶ جدولِ سامانه ۱۸ تا در فایل
 * می‌رفتند و ۲۸ تا هیچ‌جا نبودند. بدترینشان `wallet_transactions` بود:
 * موجودیِ کیفِ پول ستون نیست، از جمعِ همان دفتر مشتق می‌شود. یعنی بازیابی
 * از بکاپ، **موجودیِ هر ساکن را صفر می‌کرد** — بی‌آنکه خطایی بدهد.
 *
 * ⚠️ و هجده تستِ `DisasterRecoveryTest` هم سبز می‌ماندند، چون همه‌شان
 * می‌پرسیدند «آنچه بکاپ شده برمی‌گردد؟» و هیچ‌کدام «آیا آنچه باید بکاپ
 * شود، بکاپ شده؟».
 *
 * این کلاس همان سؤالِ دوم است.
 */
class BackupCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ دیسکِ خصوصی جعلی می‌شود.
         *
         * بدونِ این، تست‌ها فایل‌های واقعیِ ماشینِ توسعه را می‌بینند و
         * شمارشِ ورودی‌های آرشیو به وضعیتِ آن ماشین وابسته می‌شود — یعنی
         * تستی که روی یکی سبز است و روی CI قرمز. همان درسِ چهاربارِ
         * گذشته: «روی ماشینِ من کار می‌کند» تست نیست.
         */
        Storage::fake(PrivateFiles::name());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۱) طبقه‌بندیِ اجباری
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 محافظِ اصلیِ این مرحله.
     *
     * ریشه‌ی نقص این بود که فهرست فقط «آنچه هست» را می‌گفت. جدولِ تازه‌ای
     * که کسی می‌ساخت، بی‌سروصدا بیرون می‌ماند و هیچ‌چیز اعتراض نمی‌کرد.
     *
     * حالا هر جدولِ سامانه باید در یکی از دو فهرست باشد. طبقه‌بندی‌نشده
     * یعنی قرمز — پس تصمیم **گرفته می‌شود**، نه فراموش.
     */
    public function test_every_table_in_the_schema_is_classified(): void
    {
        $unclassified = array_values(array_diff(
            $this->schemaTables(),
            BackupManifest::classified(),
        ));

        $this->assertSame(
            [],
            $unclassified,
            'این جدول‌ها نه بکاپ می‌شوند نه صریح کنار گذاشته شده‌اند: '
            .implode('، ', $unclassified).'. '
            .'یکی از دو فهرستِ BackupManifest را کامل کنید — سکوت یعنی '
            .'روزِ بازیابی معلوم شود این داده هیچ‌وقت نسخه‌ی دومی نداشته.',
        );
    }

    public function test_no_table_is_both_backed_up_and_excluded(): void
    {
        $both = array_values(array_intersect(
            BackupManifest::TABLES,
            array_keys(BackupManifest::EXCLUDED),
        ));

        $this->assertSame([], $both, 'این جدول‌ها در هر دو فهرست‌اند: '.implode('، ', $both));
    }

    /**
     * ⚠️ دلیل اجباری است.
     *
     * فهرستی که فقط نام دارد، شش ماه بعد کسی نمی‌داند عمدی بوده یا
     * فراموشی — و آن‌وقت هیچ‌کس جرأتِ عوض‌کردنش را هم ندارد.
     */
    public function test_every_excluded_table_explains_itself(): void
    {
        foreach (BackupManifest::EXCLUDED as $table => $reason) {
            $this->assertGreaterThan(
                30,
                mb_strlen($reason),
                "دلیلِ کنارگذاشتنِ «{$table}» آن‌قدر کوتاه است که توضیح نیست.",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۲) اثباتِ رفتاری — دفترِ کیفِ پول
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 تستِ بازگشتیِ نقصِ اصلی.
     *
     * ⚠️ عمداً به فهرست نگاه نمی‌کند و **موجودی** را می‌سنجد: با فهرستِ
     * قدیمی این تست قرمز می‌شد چون موجودیِ ۲۵۰٬۰۰۰ تومانی پس از بازیابی
     * صفر می‌شد. دقیقاً همان چیزی که روی سرورِ واقعی رخ می‌داد.
     */
    public function test_the_wallet_ledger_survives_a_backup(): void
    {
        [$complex, $unit] = $this->seedComplex();

        app(WalletService::class)->credit($unit, 250000, WalletTransaction::SOURCE_ADJUSTMENT);

        $balanceBefore = app(WalletService::class)->balance($unit);
        $this->assertSame(250000.0, $balanceBefore, 'مقدمه‌ی تست درست ساخته نشد.');

        $snapshot = $this->buildSnapshot();

        $this->assertArrayHasKey(
            'wallet_transactions',
            $snapshot['tables'],
            'دفترِ کیفِ پول در فایلِ بکاپ نیست. بازیابی موجودیِ همه را صفر می‌کند.',
        );

        $this->assertCount(
            1,
            $snapshot['tables']['wallet_transactions'],
            'دفتر در فایل هست ولی خالی است.',
        );

        $this->assertEquals(
            250000,
            (float) $snapshot['tables']['wallet_transactions'][0]['amount'],
            'مبلغِ ثبت‌شده در بکاپ با دفتر یکی نیست.',
        );

        unset($complex);
    }

    /**
     * ⚠️ همان خانواده‌ی نقص: پرداخت بدونِ تخصیص یعنی قبض‌ها بازنشده
     * برمی‌گردند، هرچند پولش در سامانه هست.
     */
    public function test_payment_allocations_are_part_of_the_backup(): void
    {
        $this->assertContains(
            'payment_allocations',
            BackupManifest::TABLES,
            'تخصیصِ پرداخت به قبض بکاپ نمی‌شود؛ پس از بازیابی هر قبضِ '
            .'پرداخت‌شده دوباره بدهکار به نظر می‌رسد.',
        );

        $this->assertContains('message_units', BackupManifest::TABLES);
        $this->assertContains('service_requests', BackupManifest::TABLES);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۳) فایل‌ها
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ هر ستونی که مسیرِ فایل نگه می‌دارد باید پوششی داشته باشد.
     *
     * مهاجرت‌ها اسکن می‌شوند، نه فهرستِ دستی — وگرنه همان چرخه تکرار
     * می‌شد: ستونِ تازه اضافه شود و فایلش هرگز بکاپ نگیرد.
     */
    public function test_every_stored_file_path_column_is_accounted_for(): void
    {
        $declared = array_merge(
            array_keys(BackupManifest::PATH_COLUMNS),
            array_keys(BackupManifest::NON_FILE_PATH_COLUMNS),
        );

        foreach ($this->pathColumnsInSchema() as $column) {
            $this->assertContains(
                $column,
                $declared,
                "ستونِ «{$column}» شبیهِ مسیرِ فایل است ولی در BackupManifest "
                .'طبقه‌بندی نشده. اگر فایل نگه می‌دارد باید بکاپ شود؛ اگر نه، '
                .'در NON_FILE_PATH_COLUMNS با دلیلش بیاید.',
            );
        }

        foreach (BackupManifest::PATH_COLUMNS as $column => $root) {
            $this->assertTrue(
                array_key_exists($root, BackupManifest::FILE_ROOTS)
                    || array_key_exists($root, BackupManifest::EXCLUDED_ROOTS),
                "پوشه‌ی «{$root}» (از {$column}) نه آرشیو می‌شود نه صریح کنار گذاشته شده.",
            );
        }
    }

    public function test_the_archive_carries_the_uploaded_files(): void
    {
        $disk = PrivateFiles::disk();
        $disk->put('receipts/7/rasid.jpg', 'محتوای رسید');
        $disk->put('messages/7/peyvast.png', 'محتوای پیوست');
        $disk->put('requests/7/darkhast.pdf', 'محتوای درخواست');

        $archive = $this->buildArchive();

        $names = $this->entryNames($archive);

        $this->assertContains('receipts/7/rasid.jpg', $names);
        $this->assertContains('messages/7/peyvast.png', $names, 'پیوستِ پیام آرشیو نشد.');
        $this->assertContains('requests/7/darkhast.pdf', $names, 'پیوستِ درخواست آرشیو نشد.');
    }

    /**
     * ⚠️ آرشیوی که رمز ندارد، همان نشتی است که R20 برای دیتابیس بست.
     *
     * این فایل‌ها رسیدِ بانکیِ مردم‌اند و کلِ هدفِ این مرحله بیرون‌بردنشان
     * از سرور است.
     */
    public function test_the_archive_is_really_encrypted(): void
    {
        PrivateFiles::disk()->put('receipts/7/rasid.jpg', 'محتوای محرمانه');

        $path = PrivateFiles::disk()->path($this->buildArchive());

        $open = new ZipArchive;
        $open->open($path);

        $entry = $open->statName('receipts/7/rasid.jpg');
        $this->assertIsArray($entry);
        $this->assertSame(
            ZipArchive::EM_AES_256,
            $entry['encryption_method'],
            'ورودیِ آرشیو با AES-256 رمز نشده است.',
        );

        $this->assertFalse(
            @$open->getFromName('receipts/7/rasid.jpg'),
            'محتوا بدونِ رمز خوانده شد.',
        );

        $open->setPassword('ramz-e-eshtebah');
        $this->assertFalse(
            @$open->getFromName('receipts/7/rasid.jpg'),
            'رمزِ اشتباه محتوا را باز کرد.',
        );

        $open->setPassword(FileArchive::password());
        $this->assertSame('محتوای محرمانه', $open->getFromName('receipts/7/rasid.jpg'));

        $open->close();
    }

    /**
     * فهرست عمداً بی‌رمز است: اپراتوری که آرشیو را روی ذخیره‌سازِ خارجی
     * می‌بیند باید بتواند بفهمد داخلش چه هست و از کِی، بی‌آنکه یک بایت از
     * رسیدهای مردم خوانده شود.
     */
    public function test_the_manifest_is_readable_without_the_password(): void
    {
        PrivateFiles::disk()->put('ads/banner.png', 'x');

        $open = new ZipArchive;
        $open->open(PrivateFiles::disk()->path($this->buildArchive()));

        $manifest = json_decode((string) $open->getFromName('manifest.json'), true);
        $open->close();

        $this->assertIsArray($manifest);
        $this->assertSame('sakena-files', $manifest['format']);
        $this->assertSame(1, $manifest['roots']['ads']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۴) تمرینِ دوره‌ایِ بازیابی
    // ─────────────────────────────────────────────────────────────────────

    public function test_verify_passes_on_a_fresh_pair_of_backups(): void
    {
        $this->seedComplex();
        $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        $this->artisan('backups:verify')->assertExitCode(0);
    }

    /**
     * 🔴 اصلی‌ترین چیزی که این دستور باید بگیرد.
     *
     * `BackupCipher` به `APP_KEY` گره خورده (R20). اگر کلید عوض شود،
     * **هیچ بکاپی باز نمی‌شود** — و تا روزِ حادثه هیچ نشانه‌ای بیرون
     * نمی‌دهد. فهرستِ فایل‌ها همچنان پر و مرتب است.
     */
    public function test_verify_fails_when_the_key_no_longer_opens_the_backup(): void
    {
        $this->seedComplex();
        $backup = $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        // فایلی که با کلیدِ دیگری ساخته شده: ساختارش درست، محتوایش ناخوانا
        PrivateFiles::disk()->put((string) $backup->path, (string) json_encode([
            'format' => BackupCipher::FORMAT,
            'version' => BackupCipher::VERSION,
            'encrypted' => true,
            'payload' => 'eyJpdiI6Im5hLW1vdGViYXItYXN0In0=',
        ]));

        $this->artisan('backups:verify')->assertExitCode(1);
    }

    /**
     * ⚠️ اینجاست که عددِ RPO از حرف به سنجه تبدیل می‌شود.
     */
    public function test_verify_fails_when_the_newest_backup_is_older_than_the_rpo(): void
    {
        config(['backup.rpo_hours' => 24]);

        $this->seedComplex();
        $backup = $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        $this->artisan('backups:verify')->assertExitCode(0);

        $backup->forceFill(['created_at' => now()->subHours(50)])->save();

        $this->artisan('backups:verify')->assertExitCode(1);
    }

    /**
     * نبودِ مقصدِ خارجی خرابی نیست، **تنظیمِ انجام‌نشده** است — پس هشدار
     * می‌دهد و cron را هر شب قرمز نمی‌کند. ولی سکوت هم نمی‌کند.
     */
    public function test_an_unconfigured_offsite_target_warns_but_is_fatal_in_strict_mode(): void
    {
        config(['backup.offsite.disk' => null]);

        $this->seedComplex();
        $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        $this->artisan('backups:verify')->assertExitCode(0);
        $this->artisan('backups:verify --strict')->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۵) مقصدِ خارج از سرور
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_offsite_copy_is_byte_identical(): void
    {
        Storage::fake('test-offsite');
        config(['backup.offsite.disk' => 'test-offsite', 'backup.offsite.path' => 'sakena']);

        $this->seedComplex();
        $backup = $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        $this->artisan('backups:offsite')->assertExitCode(0);

        $name = 'sakena/'.basename((string) $backup->path);

        $this->assertTrue(Storage::disk('test-offsite')->exists($name), 'فایل به مقصد نرفت.');
        $this->assertSame(
            hash('sha256', (string) PrivateFiles::disk()->get((string) $backup->path)),
            hash('sha256', (string) Storage::disk('test-offsite')->get($name)),
            'محتوای مقصد با مبدأ یکی نیست.',
        );
    }

    /**
     * ⚠️ تله‌ی مرتب‌سازی.
     *
     * دو نوعِ فایل در یک پوشه‌اند و `backup-system-*` الفبایی پیش از
     * `files-*` می‌افتد. هرسِ «۳۰ تای آخر» روی فهرستِ درهم یعنی نگه‌داشتنِ
     * آرشیوهای فایل و پاک‌کردنِ **همه‌ی** بکاپ‌های دیتابیس.
     */
    public function test_offsite_pruning_keeps_each_kind_separately(): void
    {
        Storage::fake('test-offsite');
        config([
            'backup.offsite.disk' => 'test-offsite',
            'backup.offsite.path' => 'sakena',
            'backup.offsite.keep' => 2,
        ]);

        foreach (range(1, 4) as $i) {
            Storage::disk('test-offsite')->put("sakena/backup-system-2026010{$i}-000000-aaaaaa.json", 'db');
            Storage::disk('test-offsite')->put("sakena/files-2026010{$i}-000000.zip", 'files');
        }

        $this->seedComplex();
        $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        $this->artisan('backups:offsite')->assertExitCode(0);

        $remaining = collect(Storage::disk('test-offsite')->files('sakena'))
            ->map(fn (string $file) => basename($file));

        $this->assertGreaterThanOrEqual(
            2,
            $remaining->filter(fn (string $f) => str_starts_with($f, 'backup-system-'))->count(),
            'هرس همه‌ی بکاپ‌های دیتابیس را برد.',
        );

        $this->assertGreaterThanOrEqual(
            2,
            $remaining->filter(fn (string $f) => str_starts_with($f, 'files-'))->count(),
            'هرس همه‌ی آرشیوهای فایل را برد.',
        );
    }

    /**
     * ⚠️ «دیسکِ دوم» با «خارج از سرور» یکی نیست.
     *
     * اگر کسی مقصد را روی همان فایل‌سیستم بگذارد، احساسِ امنیت می‌کند و
     * در برابرِ مرگِ دیسک هیچ ندارد. این حالت باید **قرمز** باشد، نه سبز.
     */
    public function test_an_offsite_target_on_the_same_filesystem_is_reported(): void
    {
        config([
            'backup.offsite.disk' => 'same-device',
            'filesystems.disks.same-device' => [
                'driver' => 'local',
                'root' => storage_path('app/private'),
            ],
        ]);

        $this->assertTrue(
            OffsiteTarget::sameDeviceAsPrivate(),
            'مقصدی روی همان مسیرِ داده‌ها «خارج از سرور» تشخیص داده شد.',
        );

        $this->seedComplex();
        $this->makeDatabaseBackup();
        $this->makeFileArchiveRecord();

        $this->artisan('backups:verify')->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۶) زمان‌بند باید RPOی اعلام‌شده را برآورده کند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 چیزی که یک سند هرگز نمی‌تواند تضمین کند.
     *
     * ⚠️ RPO فقط وقتی معنا دارد که زمان‌بندِ واقعی آن را برآورده کند. اگر
     * کسی `backups:run` را از روزانه به هفتگی ببرد، سند همچنان «۲۴ ساعت»
     * می‌گوید و دروغ می‌شود. اینجا فاصله‌ی واقعیِ دو اجرا از عبارتِ cron
     * حساب می‌شود.
     */
    public function test_the_schedule_actually_meets_the_declared_rpo(): void
    {
        $pairs = [
            'backups:run' => (int) config('backup.rpo_hours'),
            'backups:files' => (int) config('backup.rpo_files_hours'),
        ];

        foreach ($pairs as $command => $rpoHours) {
            $gap = $this->longestGapHours($command);

            $this->assertNotNull($gap, "دستورِ «{$command}» در زمان‌بند نیست.");

            $this->assertLessThanOrEqual(
                $rpoHours,
                $gap,
                "«{$command}» هر {$gap} ساعت اجرا می‌شود ولی RPOی اعلام‌شده "
                ."{$rpoHours} ساعت است. یا زمان‌بند را تندتر کنید یا عددِ "
                .'config/backup.php را صادقانه بالا ببرید — ولی سند و رفتار '
                .'نباید از هم جدا بمانند.',
            );
        }
    }

    /**
     * ⚠️ هرس نباید پیش از ساختِ نسخه‌ی تازه اجرا شود.
     */
    public function test_pruning_runs_after_the_backup_it_prunes(): void
    {
        $backup = $this->firstRunAfterMidnight('backups:run');
        $prune = $this->firstRunAfterMidnight('backups:prune');

        $this->assertNotNull($backup);
        $this->assertNotNull($prune);
        $this->assertLessThan(
            $prune,
            $backup,
            'هرسِ بکاپ‌ها پیش از ساختِ نسخه‌ی تازه اجرا می‌شود؛ در بدترین '
            .'لحظه یعنی نسخه‌ی جایگزین هنوز ساخته نشده و قدیمی‌ها رفته‌اند.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۷) رویه‌ی مکتوب
    // ─────────────────────────────────────────────────────────────────────

    /**
     * هشت سناریوی فایلِ ایرادات، هرکدام باید رویه‌ی نوشته‌شده داشته باشند.
     */
    public function test_every_disaster_scenario_has_a_written_procedure(): void
    {
        $document = (string) file_get_contents(base_path('docs/DISASTER_RECOVERY.md'));

        $scenarios = [
            'دیتابیس',
            'سرور',
            'Redis',
            'صف',
            'ذخیره‌ساز',
            'استقرار',
            'مهاجرت',
            'اشتباهی حذف',
        ];

        foreach ($scenarios as $scenario) {
            $this->assertStringContainsString(
                $scenario,
                $document,
                "سناریوی «{$scenario}» رویه‌ی مکتوب ندارد.",
            );
        }
    }

    /**
     * ⚠️ عددِ سند و عددِ config نباید از هم جدا شوند.
     *
     * سندی که ۲۴ ساعت می‌گوید و تنظیماتی که ۷۲ ساعت است، بدتر از نداشتنِ
     * سند است: تصمیم بر پایه‌ی عددِ اشتباه گرفته می‌شود.
     */
    public function test_the_document_states_the_same_rpo_and_rto_as_the_config(): void
    {
        $document = (string) file_get_contents(base_path('docs/DISASTER_RECOVERY.md'));

        foreach (['rpo_hours', 'rpo_files_hours', 'rto_hours'] as $key) {
            /*
             * ⚠️ جست‌وجوی خودِ عدد کافی نیست: «۴» تقریباً در هر سندی پیدا
             * می‌شود و آن ادعا پوچ می‌ماند. پس عدد باید **کنارِ نامِ کلیدش**
             * بیاید — دقیقاً همان چیزی که اگر config عوض شود و سند نه،
             * می‌شکند.
             */
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($key, '/').'\s*=\s*'.((int) config("backup.{$key}")).'\b/u',
                $document,
                "عددِ «{$key}» در سندِ بازیابی نیامده یا با config یکی نیست.",
            );
        }
    }

    /**
     * ⚠️ فهرستِ پوشه‌ها نباید در دو جا دستی نوشته شود.
     *
     * تا R58 `uploads:prune-orphans` فهرستِ خودش را داشت و **دو پوشه را جا
     * انداخته بود**: پیوستِ پیام‌ها و درخواست‌های خدمات هرگز جمع نمی‌شدند.
     */
    public function test_orphan_pruning_reads_the_same_roots_as_the_archive(): void
    {
        $code = (string) file_get_contents(base_path('app/Console/Commands/PruneOrphanUploads.php'));

        $this->assertStringContainsString(
            'BackupManifest::FILE_ROOTS',
            $code,
            'هرسِ فایل‌های یتیم فهرستِ پوشه‌های خودش را دارد و با آرشیو واگرا می‌شود.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  کمکی‌ها
    // ─────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function schemaTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $name) => str_starts_with($name, 'sqlite_'))
            ->values()
            ->all();
    }

    /**
     * ستون‌هایی که در مهاجرت‌ها شبیهِ مسیرِ فایل تعریف شده‌اند.
     *
     * @return list<string>
     */
    private function pathColumnsInSchema(): array
    {
        $found = [];

        foreach ($this->schemaTables() as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (preg_match('/(^|_)(path|image_path|attachment_path)$/', $column)) {
                    $found[] = "{$table}.{$column}";
                }
            }
        }

        return $found;
    }

    /** @return array{0: Complex, 1: Unit} */
    private function seedComplex(): array
    {
        $complex = Complex::factory()->create();
        $unit = Unit::factory()->create(['complex_id' => $complex->id]);

        User::factory()->create(['complex_id' => $complex->id]);

        return [$complex, $unit];
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(): array
    {
        $backup = $this->makeDatabaseBackup();

        $payload = BackupCipher::open(
            (string) PrivateFiles::disk()->get((string) $backup->path),
        );

        $this->assertIsArray($payload);

        return $payload;
    }

    private function makeDatabaseBackup(): Backup
    {
        return app(BackupBuilder::class)->fill(Backup::create([
            'complex_id' => null,
            'type' => 'full',
            'status' => 'pending',
            'disk' => PrivateFiles::name(),
            'note' => 'تست',
        ]));
    }

    /** مسیرِ آرشیوِ ساخته‌شده. */
    private function buildArchive(): string
    {
        return (string) $this->makeFileArchiveRecord()->path;
    }

    private function makeFileArchiveRecord(): Backup
    {
        return app(FileArchive::class)->fill(Backup::create([
            'complex_id' => null,
            'type' => 'files',
            'status' => 'pending',
            'disk' => PrivateFiles::name(),
            'note' => 'تست',
        ]));
    }

    /** @return list<string> */
    private function entryNames(string $archivePath): array
    {
        $zip = new ZipArchive;
        $zip->open(PrivateFiles::disk()->path($archivePath));

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $zip->close();

        return $names;
    }

    /**
     * بیشترین فاصله‌ی دو اجرای پیاپیِ یک دستور، برحسب ساعت.
     *
     * از خودِ عبارتِ cron حساب می‌شود، نه از نامِ متدِ زمان‌بندی — چون
     * `weeklyOn` و `dailyAt` هر دو در نهایت یک رشته‌ی cron می‌شوند و
     * همان رشته چیزی است که واقعاً اجرا می‌شود.
     */
    private function longestGapHours(string $command): ?int
    {
        $expression = $this->expressionFor($command);

        if ($expression === null) {
            return null;
        }

        $cron = new CronExpression($expression);
        $runs = $cron->getMultipleRunDates(6, 'now', false, false);

        $gap = 0;

        for ($i = 1; $i < count($runs); $i++) {
            $gap = max($gap, (int) round(
                ($runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp()) / 3600,
            ));
        }

        return $gap;
    }

    /** ساعتِ اولین اجرای یک دستور پس از نیمه‌شب. */
    private function firstRunAfterMidnight(string $command): ?float
    {
        $expression = $this->expressionFor($command);

        if ($expression === null) {
            return null;
        }

        [$minute, $hour] = explode(' ', $expression);

        return (float) $hour + ((float) $minute / 60);
    }

    private function expressionFor(string $command): ?string
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, "artisan' {$command}")
                || str_contains((string) $event->command, "artisan\" {$command}")
                || str_contains((string) $event->command, " {$command}")) {
                return $event->expression;
            }
        }

        return null;
    }
}
