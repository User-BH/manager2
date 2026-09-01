<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Schema;

/**
 * سنجشِ سلامتِ محتوای فایلِ بکاپ، پیش از آنکه چیزی پاک شود.
 *
 * ─── چرا از کنترلر بیرون آمد (R58) ─────────────────────────────────────────
 * ⚠️ این منطق در `System\BackupController` خصوصی بود. `backups:verify` هم
 * دقیقاً همین سنجش را لازم دارد — و اگر نسخه‌ی دومی برایش نوشته می‌شد،
 * تأییدِ هفتگی چیزی را می‌سنجید که **بازیابیِ واقعی نمی‌سنجد**.
 *
 * آن‌وقت بدترین حالتِ ممکن رخ می‌داد: هر هفته گزارشِ سبز، و روزِ حادثه
 * بازیابی رد کند. حالا هر دو مسیر از همین یک تابع می‌گذرند.
 */
class SnapshotValidator
{
    /**
     * @return string|null پیامِ خطا، یا null اگر فایل سالم باشد
     */
    public static function validate(mixed $payload): ?string
    {
        if (! is_array($payload) || ! isset($payload['tables']) || ! is_array($payload['tables'])) {
            return 'فایل بکاپ معتبر نیست یا ساختار درستی ندارد.';
        }

        if (($payload['meta']['type'] ?? null) !== 'full') {
            return 'این فایل بکاپ کامل سیستم نیست. بکاپ یک مجتمع را نمی‌توان اینجا بازیابی کرد.';
        }

        foreach ($payload['tables'] as $table => $rows) {
            // جدولی که نمی‌شناسیم نباید بی‌سروصدا نادیده گرفته شود؛ یعنی فایل
            // از نسخه‌ی دیگری از برنامه آمده و بازیابی‌اش داده را ناقص می‌کند.
            if (! in_array($table, BackupManifest::TABLES, true)) {
                return "فایل بکاپ شامل جدول ناشناخته‌ی «{$table}» است.";
            }

            if (! is_array($rows)) {
                return "داده‌ی جدول «{$table}» در فایل بکاپ معتبر نیست.";
            }

            if (! Schema::hasTable($table) || $rows === []) {
                continue;
            }

            // ستون‌های ناشناخته هنگام insert خطای SQL می‌دهند؛ بهتر است همین
            // حالا با پیام روشن رد شوند.
            $columns = Schema::getColumnListing($table);
            $unknown = array_diff(array_keys((array) $rows[0]), $columns);

            if ($unknown !== []) {
                return "جدول «{$table}» ستون ناشناخته دارد: ".implode('، ', $unknown);
            }
        }

        return null;
    }
}
