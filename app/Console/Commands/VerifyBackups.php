<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\FileArchive;
use App\Services\Backup\OffsiteTarget;
use App\Services\Backup\SnapshotValidator;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Throwable;
use ZipArchive;

/**
 * تمرینِ دوره‌ایِ بازیابی (R58).
 *
 * ─── چرا وجودِ فایل کافی نیست ──────────────────────────────────────────────
 * ⚠️ بکاپ به سه شکل می‌میرد و **هیچ‌کدام دیده نمی‌شوند** تا روزِ حادثه:
 *
 *   ① `APP_KEY` عوض شده و هیچ فایلی دیگر باز نمی‌شود
 *      (`BackupCipher` به کلید گره خورده — R20)
 *   ② schema جلو رفته و فایلِ دیروز با جدول‌های امروز نمی‌خواند
 *   ③ زمان‌بند خاموش شده و تازه‌ترین نسخه مالِ ماه‌ها پیش است
 *
 * هر سه با نگاه‌کردن به فهرستِ فایل‌ها سالم به نظر می‌رسند.
 *
 * ─── ⚠️ مرزِ صریحِ این دستور ───────────────────────────────────────────────
 * این دستور **درج نمی‌کند**. فایل را باز می‌کند، رمزگشایی می‌کند و با
 * schemaی امروز می‌سنجد — با **همان تابعی** که بازیابیِ واقعی استفاده
 * می‌کند (`SnapshotValidator`) — ولی ردیفی را جایی نمی‌نویسد.
 *
 * رفت‌وبرگشتِ کاملِ درج در `DisasterRecoveryTest` در CI سنجیده می‌شود.
 * اجرایش روی سرورِ محصول یعنی ساختنِ یک دیتابیسِ موقتِ هم‌اندازه‌ی محصول —
 * که خودش ریسکِ جداگانه‌ای است.
 *
 * این تفکیک عمدی است و در `docs/DISASTER_RECOVERY.md` نوشته شده.
 */
class VerifyBackups extends Command
{
    protected $signature = 'backups:verify
                            {--strict : نبودِ مقصدِ خارجی هم شکست حساب شود}';

    protected $description = 'سنجشِ اینکه تازه‌ترین بکاپ‌ها واقعاً قابلِ بازیابی‌اند';

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->checkDatabaseBackup();
        $this->checkFileArchive();
        $this->checkOffsite();

        foreach ($this->warnings as $warning) {
            $this->warn('⚠️  '.$warning);
        }

        if ($this->problems === []) {
            $this->info('تازه‌ترین بکاپ‌ها باز می‌شوند و با schemaی امروز می‌خوانند.');

            return self::SUCCESS;
        }

        foreach ($this->problems as $problem) {
            $this->error('✗ '.$problem);
        }

        return self::FAILURE;
    }

    private function checkDatabaseBackup(): void
    {
        $backup = $this->newest('full');

        if ($backup === null) {
            $this->problems[] = 'هیچ بکاپِ کاملِ سالمی وجود ندارد.';

            return;
        }

        if ($this->tooOld($backup)) {
            return;
        }

        if (! PrivateFiles::disk()->exists((string) $backup->path)) {
            $this->problems[] = "فایلِ بکاپ «{$backup->path}» روی دیسک نیست، هرچند رکوردش هست.";

            return;
        }

        try {
            $payload = BackupCipher::open((string) PrivateFiles::disk()->get((string) $backup->path));
        } catch (Throwable $e) {
            // ① تقریباً همیشه یعنی APP_KEY عوض شده
            $this->problems[] = 'بازکردنِ بکاپ ناموفق بود: '.$e->getMessage();

            return;
        }

        // ② همان سنجشی که بازیابیِ واقعی انجام می‌دهد
        $error = SnapshotValidator::validate($payload);

        if ($error !== null) {
            $this->problems[] = 'بکاپ با schemaی امروز نمی‌خواند: '.$error;

            return;
        }

        $missing = array_values(array_diff(
            BackupManifest::TABLES,
            array_keys($payload['tables'] ?? []),
        ));

        if ($missing !== []) {
            /*
             * ⚠️ هشدار است نه خطا: جدولی که هنگامِ ساختِ آن بکاپ هنوز وجود
             * نداشته، طبیعی است که در فایل نباشد. ولی اگر روی بکاپِ **دیروز**
             * هم دیده شود، یعنی `Schema::hasTable` بی‌صدا ردش کرده.
             */
            $this->warnings[] = 'این جدول‌ها در فایلِ بکاپ نیستند: '.implode('، ', $missing);
        }

        $this->line(sprintf(
            '  دیتابیس: %s — %d جدول',
            basename((string) $backup->path),
            count($payload['tables'] ?? []),
        ));
    }

    private function checkFileArchive(): void
    {
        $backup = $this->newest('files');

        if ($backup === null) {
            $this->problems[] = 'هیچ آرشیوِ سالمی از فایل‌های کاربران وجود ندارد.';

            return;
        }

        if ($this->tooOld($backup)) {
            return;
        }

        $name = PrivateFiles::name();

        if (config("filesystems.disks.{$name}.driver") !== 'local') {
            /*
             * ZipArchive مسیرِ فایل‌سیستم می‌خواهد و دانلودِ کلِ آرشیو برای یک
             * سنجشِ دوره‌ای گران است. پس اینجا **ادعای نسنجیده نمی‌کنیم** و
             * صریح می‌گوییم سنجیده نشد.
             */
            $this->warnings[] = 'آرشیوِ فایل‌ها روی دیسکِ غیرمحلی است و بازشدنش سنجیده نشد.';

            return;
        }

        $zip = new ZipArchive;

        if ($zip->open(PrivateFiles::disk()->path((string) $backup->path)) !== true) {
            $this->problems[] = "آرشیوِ «{$backup->path}» باز نمی‌شود.";

            return;
        }

        $zip->setPassword(FileArchive::password());

        $manifest = $zip->getFromName('manifest.json');
        $entries = $zip->numFiles;

        /*
         * ⚠️ فهرست بی‌رمز است، پس خواندنش رمز را **نمی‌سنجد**. یک ورودیِ
         * واقعی هم خوانده می‌شود تا معلوم شود کلیدِ امروز آرشیو را باز
         * می‌کند — وگرنه این سنجش دقیقاً همان چیزی را جا می‌انداخت که
         * برایش ساخته شده.
         */
        $probe = null;

        for ($i = 0; $i < $entries; $i++) {
            $entryName = $zip->getNameIndex($i);

            if (is_string($entryName) && $entryName !== 'manifest.json') {
                $probe = $entryName;
                break;
            }
        }

        $unlocked = $probe === null ? null : @$zip->getFromName($probe);

        $zip->close();

        if (! is_string($manifest) || json_decode($manifest, true) === null) {
            $this->problems[] = 'فهرستِ داخلِ آرشیوِ فایل‌ها خوانده نشد.';

            return;
        }

        if ($probe !== null && $unlocked === false) {
            $this->problems[] = 'رمزِ آرشیوِ فایل‌ها باز نمی‌کند؛ محتمل‌ترین علت عوض‌شدنِ APP_KEY است.';

            return;
        }

        if ($probe === null) {
            $this->warnings[] = 'آرشیوِ فایل‌ها هیچ فایلی جز فهرست ندارد.';
        }

        $this->line(sprintf(
            '  فایل‌ها: %s — %d ورودی',
            basename((string) $backup->path),
            max(0, $entries - 1),
        ));
    }

    private function checkOffsite(): void
    {
        if (! OffsiteTarget::configured()) {
            $message = 'مقصدِ خارج از سرور تنظیم نشده است؛ مرگِ دیسک یعنی از دست رفتنِ داده و همه‌ی بکاپ‌ها با هم.';

            if ($this->option('strict')) {
                $this->problems[] = $message;
            } else {
                $this->warnings[] = $message;
            }

            return;
        }

        if (OffsiteTarget::sameDeviceAsPrivate() === true) {
            // ⚠️ «دیسکِ دوم» با «خارج از سرور» یکی نیست
            $this->problems[] = 'مقصدِ خارجی روی همان فایل‌سیستمِ داده‌هاست؛ در برابرِ مرگِ دیسک هیچ محافظتی ندارد.';

            return;
        }

        try {
            $target = OffsiteTarget::disk();
        } catch (Throwable $e) {
            $this->problems[] = $e->getMessage();

            return;
        }

        foreach (['full', 'files'] as $type) {
            $backup = $this->newest($type);

            if ($backup === null) {
                continue;
            }

            $expected = OffsiteTarget::path(basename((string) $backup->path));

            if (! $target->exists($expected)) {
                $this->problems[] = "تازه‌ترین بکاپِ «{$type}» هنوز به مقصدِ خارجی نرفته است.";

                continue;
            }

            $this->line("  خارجی: {$expected}");
        }
    }

    private function newest(string $type): ?Backup
    {
        return Backup::whereNull('complex_id')
            ->where('type', $type)
            ->where('status', 'completed')
            ->whereNotNull('path')
            ->latest('id')
            ->first();
    }

    /**
     * آیا این بکاپ از RPOی اعلام‌شده کهنه‌تر است؟
     *
     * ⚠️ همین‌جاست که عددِ `config/backup.php` از حرف به سنجه تبدیل می‌شود.
     */
    private function tooOld(Backup $backup): bool
    {
        $rpo = $backup->type === 'files'
            ? max(1, (int) config('backup.rpo_files_hours', 168))
            : max(1, (int) config('backup.rpo_hours', 24));

        // یک ساعت ارفاق: بکاپِ ۰۱:۳۰ و سنجشِ ۰۱:۲۹ نباید قرمز شود
        $age = (int) ($backup->created_at?->diffInHours(now()) ?? PHP_INT_MAX);

        if ($age <= $rpo + 1) {
            return false;
        }

        $this->problems[] = sprintf(
            'تازه‌ترین بکاپِ «%s» %d ساعت پیش ساخته شده، ولی RPOی اعلام‌شده %d ساعت است.',
            $backup->type,
            $age,
            $rpo,
        );

        return true;
    }
}
