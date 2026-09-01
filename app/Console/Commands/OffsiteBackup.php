<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\OffsiteTarget;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * بردنِ بکاپ به مقصدِ بیرون از سرور (R58).
 *
 * ⚠️ کپی‌کردنِ کور کافی نیست. نوشتنی که ناقص مانده باشد، فایلی می‌سازد که
 * **وجود دارد** — و روزِ حادثه معلوم می‌شود باز نمی‌شود. پس هر انتقال پس
 * از نوشتن دوباره خوانده و سنجیده می‌شود.
 */
class OffsiteBackup extends Command
{
    protected $signature = 'backups:offsite
                            {--no-hash : فقط اندازه سنجیده شود، نه اثرِ انگشتِ محتوا}
                            {--dry-run : فقط بگو چه چیزی منتقل می‌شد}';

    protected $description = 'انتقالِ تازه‌ترین بکاپ‌ها به ذخیره‌سازِ خارج از سرور';

    public function handle(): int
    {
        if (! OffsiteTarget::configured()) {
            /*
             * ⚠️ عمداً `SUCCESS` برمی‌گرداند و نه `FAILURE`.
             *
             * این یک خرابی نیست، یک **تنظیمِ انجام‌نشده** است. اگر هر شب
             * خطا بدهد، ظرفِ یک هفته کسی خروجیِ این cron را نمی‌خواند —
             * و آن‌وقت خطاهای واقعی هم دیده نمی‌شوند.
             *
             * جایش `backups:verify` هفتگی این را برجسته گزارش می‌کند.
             */
            $this->warn('مقصدِ خارج از سرور تنظیم نشده است (BACKUP_OFFSITE_DISK).');
            $this->line('⚠️  تا آن زمان، مرگِ دیسکِ سرور یعنی از دست رفتنِ داده و همه‌ی بکاپ‌ها با هم.');
            $this->line('   رویه: docs/DISASTER_RECOVERY.md');

            return self::SUCCESS;
        }

        try {
            $target = OffsiteTarget::disk();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $source = PrivateFiles::disk();
        $moved = 0;

        foreach (['full', 'files'] as $type) {
            $backup = Backup::whereNull('complex_id')
                ->where('type', $type)
                ->where('status', 'completed')
                ->whereNotNull('path')
                ->latest('id')
                ->first();

            if ($backup === null) {
                $this->warn("هیچ بکاپِ کاملِ «{$type}» برای انتقال پیدا نشد.");

                continue;
            }

            if (! $source->exists((string) $backup->path)) {
                $this->error("فایلِ «{$backup->path}» روی دیسکِ سرور نیست.");

                return self::FAILURE;
            }

            $destination = OffsiteTarget::path(basename((string) $backup->path));

            if ($this->option('dry-run')) {
                $this->line("منتقل می‌شد: {$backup->path} → {$destination}");

                continue;
            }

            if ($target->exists($destination)) {
                $this->line("از قبل منتقل شده: {$destination}");

                continue;
            }

            $handle = $source->readStream((string) $backup->path);
            $target->writeStream($destination, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }

            $error = $this->confirmArrival($source, $target, (string) $backup->path, $destination);

            if ($error !== null) {
                // فایلِ نیمه‌نوشته بدتر از نبودِ فایل است: به‌جای «بکاپ دارم»
                // می‌شود «بکاپِ خرابی دارم که خیال می‌کنم سالم است»
                $target->delete($destination);

                $this->error($error);

                return self::FAILURE;
            }

            $this->info("منتقل و تأیید شد: {$destination}");
            $moved++;
        }

        $this->prune($target);

        if (! $this->option('dry-run')) {
            $this->info("{$moved} فایل به مقصدِ خارجی رفت.");
        }

        return self::SUCCESS;
    }

    /**
     * خواندنِ دوباره از مقصد و مقایسه با مبدأ.
     *
     * @return string|null پیامِ خطا، یا null اگر سالم باشد
     */
    private function confirmArrival(
        Filesystem $source,
        Filesystem $target,
        string $from,
        string $to,
    ): ?string {
        if (! $target->exists($to)) {
            return "فایل پس از انتقال در مقصد پیدا نشد: {$to}";
        }

        $expected = (int) $source->size($from);
        $actual = (int) $target->size($to);

        if ($expected !== $actual) {
            return "اندازه‌ی «{$to}» با مبدأ فرق دارد ({$actual} در برابرِ {$expected} بایت).";
        }

        if ($this->option('no-hash')) {
            return null;
        }

        /*
         * ⚠️ اثرِ انگشت **جریانی** حساب می‌شود، نه با خواندنِ کلِ فایل در
         * حافظه: آرشیوِ فایل‌ها می‌تواند صدها مگابایت باشد.
         *
         * هزینه‌اش پهنای باندِ دانلودِ دوباره است. `--no-hash` برای مقصدهایی
         * که ترافیکِ خروجی گران دارند.
         */
        if ($this->streamHash($source, $from) !== $this->streamHash($target, $to)) {
            return "محتوای «{$to}» با مبدأ یکی نیست؛ انتقال ناقص بوده است.";
        }

        return null;
    }

    private function streamHash(Filesystem $disk, string $path): string
    {
        $context = hash_init('sha256');
        $handle = $disk->readStream($path);

        if (! is_resource($handle)) {
            return '';
        }

        while (! feof($handle)) {
            $chunk = fread($handle, 1048576);

            if ($chunk === false) {
                break;
            }

            hash_update($context, $chunk);
        }

        fclose($handle);

        return hash_final($context);
    }

    /**
     * نگه‌داشتنِ چند نسخه‌ی آخر در مقصد.
     *
     * ⚠️ هرس **به تفکیکِ نوع** انجام می‌شود. اگر هر دو نوع در یک فهرست
     * مرتب شوند، `backup-system-*` الفبایی پیش از `files-*` می‌افتد و
     * «نگه‌داشتنِ ۳۰ تای آخر» یعنی نگه‌داشتنِ ۳۰ آرشیوِ فایل و پاک‌کردنِ
     * **همه‌ی** بکاپ‌های دیتابیس. همان تله‌ای که `backups:prune` روی سرور
     * با گروه‌بندی حلش کرده بود.
     */
    private function prune(Filesystem $target): void
    {
        $keep = max(1, (int) config('backup.offsite.keep', 30));
        $prefix = trim((string) config('backup.offsite.path', ''), '/');

        $all = collect($target->files($prefix === '' ? '/' : $prefix));

        foreach (['backup-system-', 'files-'] as $kind) {
            $files = $all
                ->filter(fn (string $file) => str_starts_with(basename($file), $kind))
                ->sort()
                ->values();

            if ($files->count() <= $keep) {
                continue;
            }

            foreach ($files->slice(0, $files->count() - $keep) as $stale) {
                if (! $this->option('dry-run')) {
                    $target->delete($stale);
                }

                $this->line("هرس شد: {$stale}");
            }
        }
    }
}
