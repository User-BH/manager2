<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Support\PrivateFiles;
use RuntimeException;
use ZipArchive;

/**
 * آرشیوِ فایل‌های آپلودیِ کاربران — نیمه‌ی گم‌شده‌ی بکاپ (R58).
 *
 * ─── 🔴 چه چیزی نبود ───────────────────────────────────────────────────────
 * ⚠️ بکاپ فقط **دیتابیس** بود. `payments.receipt_path` در فایلِ بکاپ می‌رفت
 * ولی خودِ رسید — تصویری که ساکن آپلود کرده و **تنها سندِ پرداختش** است —
 * هیچ نسخه‌ی دومی نداشت.
 *
 * یعنی پس از بازیابی، سامانه ردیف‌هایی داشت که می‌گفتند «رسید موجود است» و
 * هر کلیک روی آن‌ها ۴۰۴ می‌داد. بدتر از نبودنِ داده: **داده‌ی دروغین**.
 *
 * ─── چرا زیپِ رمزدار و نه پوشه‌ی ساده ──────────────────────────────────────
 * همان استدلالِ `BackupCipher` (R20): این فایل‌ها رسیدِ بانکیِ مردم‌اند. اگر
 * قرار است از سرور بیرون بروند — و کلِ هدفِ R58 همین است — نباید بدونِ رمز
 * روی ذخیره‌سازِ کسِ دیگری بنشینند.
 *
 * رمز از `APP_KEY` مشتق می‌شود، پس همان قاعده‌ی `BackupCipher` برقرار است:
 * ⚠️ **با چرخشِ `APP_KEY` آرشیوهای قدیمی باز نمی‌شوند.** رویه در
 * `docs/SECRETS.md`.
 *
 * ─── چرا AES و نه رمزِ قدیمیِ zip ──────────────────────────────────────────
 * رمزِ کلاسیکِ ZipCrypto در چند ثانیه شکسته می‌شود. `EM_AES_256` واقعی است،
 * ولی به libzip نسخه‌ی مناسب نیاز دارد.
 *
 * ⚠️ اگر پشتیبانی نباشد، این کلاس **خطا می‌دهد و آرشیو نمی‌سازد**. تنزلِ
 * بی‌صدا به آرشیوِ بی‌رمز، بدترین حالتِ ممکن بود: اپراتور فکر می‌کرد
 * رسیدهای مردم رمز دارند و نداشتند.
 */
class FileArchive
{
    /** برچسبی که رمزِ آرشیو از آن و `APP_KEY` مشتق می‌شود. */
    private const DERIVATION_LABEL = 'sakena-backup-archive-v1';

    /**
     * رمزِ آرشیو.
     *
     * جدا از `APP_KEY` نگه داشته می‌شود تا اگر روزی رمزِ آرشیو جایی لو رفت،
     * خودِ کلیدِ برنامه از آن استخراج نشود.
     */
    public static function password(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException(
                'APP_KEY تنظیم نشده است؛ آرشیوِ فایل‌ها بدونِ آن رمز نمی‌شود.',
            );
        }

        return hash_hmac('sha256', self::DERIVATION_LABEL, $key);
    }

    /**
     * ساختِ آرشیو و پرکردنِ رکوردِ `pending`.
     */
    public function fill(Backup $backup): Backup
    {
        $disk = PrivateFiles::disk();
        $maxBytes = max(1, (int) config('backup.files.max_file_mb', 64)) * 1024 * 1024;

        $temporary = tempnam(sys_get_temp_dir(), 'sakena-files-');

        if ($temporary === false) {
            throw new RuntimeException('ساختِ فایلِ موقتِ آرشیو ممکن نشد.');
        }

        $zip = new ZipArchive;

        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temporary);
            throw new RuntimeException('بازکردنِ فایلِ زیپ برای نوشتن ممکن نشد.');
        }

        $zip->setPassword(self::password());

        $counts = [];
        $skipped = [];
        $bytes = 0;
        $scratch = [];

        try {
            foreach (array_keys(BackupManifest::FILE_ROOTS) as $root) {
                $counts[$root] = 0;

                foreach ($disk->allFiles($root) as $file) {
                    $size = (int) $disk->size($file);

                    if ($size > $maxBytes) {
                        // ⚠️ رد می‌شود ولی **گزارش** می‌شود؛ جاماندنِ خاموش
                        // دقیقاً همان چیزی است که این مرحله برای رفعش هست.
                        $skipped[] = $file;

                        continue;
                    }

                    $source = $this->localPath($file, $scratch);

                    if (! $zip->addFile($source, $file)) {
                        throw new RuntimeException("افزودنِ «{$file}» به آرشیو ناموفق بود.");
                    }

                    $this->encrypt($zip, $file);

                    $counts[$root]++;
                    $bytes += $size;
                }
            }

            $manifest = (string) json_encode([
                'format' => 'sakena-files',
                'version' => 1,
                'generated_at' => now()->toIso8601String(),
                'roots' => $counts,
                'skipped' => $skipped,
                'original_bytes' => $bytes,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            /*
             * ⚠️ فهرست عمداً **بی‌رمز** می‌ماند.
             *
             * تا اپراتوری که آرشیو را روی ذخیره‌سازِ خارجی می‌بیند بتواند
             * بدونِ کلید بفهمد داخلش چه هست و از کِی — بی‌آنکه یک بایت از
             * رسیدهای مردم خوانده شود.
             */
            if (! $zip->addFromString('manifest.json', $manifest)) {
                throw new RuntimeException('نوشتنِ فهرستِ آرشیو ناموفق بود.');
            }

            if (! $zip->close()) {
                throw new RuntimeException('بستنِ فایلِ آرشیو ناموفق بود.');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($temporary);
            $this->cleanScratch($scratch);

            throw $e;
        }

        $this->cleanScratch($scratch);

        $path = 'backups/files-'.now()->format('Ymd-His').'.zip';

        $handle = fopen($temporary, 'rb');
        $disk->writeStream($path, $handle);
        fclose($handle);
        @unlink($temporary);

        $backup->update([
            'status' => 'completed',
            'disk' => PrivateFiles::name(),
            'path' => $path,
            'size' => $disk->size($path),
            'note' => trim(($backup->note ?: 'آرشیوِ فایل‌ها').' — '.array_sum($counts).' فایل'
                .($skipped === [] ? '' : '، '.count($skipped).' جامانده')),
        ]);

        return $backup->refresh();
    }

    /**
     * رمزگذاریِ یک ورودی، با شکستِ **بلند** اگر پشتیبانی نباشد.
     */
    private function encrypt(ZipArchive $zip, string $entry): void
    {
        if (! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new RuntimeException(
                'کتابخانه‌ی libzip این سرور از رمزگذاریِ AES-256 پشتیبانی نمی‌کند. '
                .'آرشیوِ بی‌رمز ساخته نمی‌شود؛ رسیدهای بانکیِ ساکنین نباید '
                .'بدونِ رمز از سرور بیرون بروند.',
            );
        }
    }

    /**
     * مسیرِ محلیِ فایل برای `addFile`.
     *
     * ⚠️ `addFile` مسیرِ فایل‌سیستم می‌خواهد، نه استریم. روی دیسکِ محلی
     * مستقیم است؛ روی ذخیره‌سازِ ابری (وقتی `PRIVATE_DISK=s3` باشد) فایل
     * **یکی‌یکی** در پوشه‌ی موقت می‌نشیند و بعد پاک می‌شود — پس حافظه به
     * اندازه‌ی بزرگ‌ترین فایل است، نه کلِ آرشیو.
     *
     * @param  list<string>  $scratch
     */
    private function localPath(string $file, array &$scratch): string
    {
        $name = PrivateFiles::name();

        if (config("filesystems.disks.{$name}.driver") === 'local') {
            return PrivateFiles::disk()->path($file);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'sakena-entry-');

        if ($temporary === false) {
            throw new RuntimeException('ساختِ فایلِ موقت برای ورودیِ آرشیو ممکن نشد.');
        }

        file_put_contents($temporary, PrivateFiles::disk()->get($file));
        $scratch[] = $temporary;

        return $temporary;
    }

    /** @param  list<string>  $scratch */
    private function cleanScratch(array $scratch): void
    {
        foreach ($scratch as $file) {
            @unlink($file);
        }
    }
}
