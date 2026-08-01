<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupBuilder;
use Throwable;

/**
 * ساختِ فایلِ نسخه‌ی پشتیبان، بیرون از چرخه‌ی درخواست.
 *
 * ─── چرا این یکی واقعاً باید در صف باشد ────────────────────────────────────
 * بکاپِ کاملِ سیستم همه‌ی ردیف‌های ~۱۸ جدول را به حافظه می‌خواند و در یک فایل
 * JSON می‌نویسد. روی داده‌ی واقعی این ثانیه‌ها تا دقیقه‌ها طول می‌کشد؛ در
 * چرخه‌ی درخواست یعنی یا `max_execution_time` یا سقفِ حافظه‌ی PHP-FPM — و
 * کاربر فقط یک ۵۰۰ می‌بیند بی‌آنکه بداند بکاپ ساخته شد یا نه.
 *
 * رکوردِ `Backup` **پیش از** صف‌شدن با وضعیتِ `pending` ساخته می‌شود، پس
 * کاربر بلافاصله ردیفش را می‌بیند و وضعیتش زنده به‌روز می‌شود.
 */
class BuildBackupJob extends BaseJob
{
    /**
     * بکاپ سنگین است ولی یک‌بارمصرف نیست؛ اگر دیسک موقتاً پر باشد، تلاشِ
     * دوباره ارزش دارد.
     */
    public int $timeout = 600;

    public function __construct(public readonly int $backupId) {}

    public function handle(BackupBuilder $builder): void
    {
        $backup = Backup::find($this->backupId);

        // رکورد بین صف‌شدن و اجرا حذف شده — کارِ بی‌صاحب انجام نمی‌دهیم
        if (! $backup || $backup->status === 'completed') {
            return;
        }

        $builder->fill($backup);
    }

    public function failed(?Throwable $exception): void
    {
        parent::failed($exception);

        /*
         * رکورد پاک نمی‌شود، `failed` می‌شود.
         *
         * حذفش یعنی کاربر دکمه را زده، ردیفی دیده، و بعد ردیف بی‌هیچ توضیحی
         * ناپدید شده. با وضعیتِ `failed` دست‌کم می‌داند تلاش شده و شکست خورده.
         */
        Backup::whereKey($this->backupId)->update(['status' => 'failed']);
    }
}
