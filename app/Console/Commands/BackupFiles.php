<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\FileArchive;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Throwable;

/**
 * آرشیوِ فایل‌های آپلودی (R58).
 *
 * ⚠️ چرا دستورِ جدا و نه بخشی از `backups:run`: فایلِ دیتابیس و آرشیوِ
 * فایل‌ها اندازه و آهنگِ تغییرِ کاملاً متفاوتی دارند. جداکردنشان یعنی
 * می‌شود دیتابیس را روزانه و آرشیو را با فاصله‌ی دیگری گرفت، و شکستِ یکی
 * دیگری را با خود پایین نمی‌کشد.
 */
class BackupFiles extends Command
{
    protected $signature = 'backups:files {--note= : یادداشتی که روی رکورد می‌نشیند}';

    protected $description = 'ساختِ آرشیوِ رمزدار از فایل‌های آپلودیِ کاربران';

    public function handle(FileArchive $archive): int
    {
        $backup = Backup::create([
            'complex_id' => null,
            'type' => 'files',
            'status' => 'pending',
            'disk' => PrivateFiles::name(),
            'note' => $this->option('note') ?: 'آرشیوِ خودکارِ فایل‌ها',
            'created_by' => null,
        ]);

        try {
            $archive->fill($backup);
        } catch (Throwable $e) {
            /*
             * ⚠️ رکورد `failed` می‌ماند و پاک نمی‌شود — همان قاعده‌ی
             * `BackupBuilder`: بکاپی که شکست خورده باید **دیده شود**.
             */
            $backup->update(['status' => 'failed', 'note' => 'شکست: '.$e->getMessage()]);

            $this->error('ساختِ آرشیو شکست خورد: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'آرشیوِ فایل‌ها ساخته شد: %s (%s مگابایت)',
            $backup->path,
            number_format(((int) $backup->size) / 1048576, 2),
        ));

        return self::SUCCESS;
    }
}
