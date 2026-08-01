<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Complex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ساختِ فایلِ نسخه‌ی پشتیبان.
 *
 * ─── چرا از کنترلر بیرون آمد ───────────────────────────────────────────────
 * این منطق در دو کنترلر (مجتمع و سیستم) بود و هر دو همان کار را با کدِ
 * جداگانه انجام می‌دادند. حالا که همین کار باید از داخلِ Job هم صدا زده شود،
 * ماندنش در کنترلر یعنی سه نسخه.
 *
 * ─── نکته‌ی حافظه ──────────────────────────────────────────────────────────
 * بکاپِ سیستم همه‌ی ردیف‌های ~۱۸ جدول را می‌خواند. روی داده‌ی واقعی این
 * می‌تواند صدها مگابایت حافظه بگیرد، و دقیقاً به همین دلیل باید در صف اجرا
 * شود نه در چرخه‌ی درخواست: آنجا `max_execution_time` و حافظه‌ی PHP-FPM
 * محدودند و شکستش به‌صورتِ ۵۰۰ به کاربر می‌رسد.
 */
class BackupBuilder
{
    /** جدول‌هایی که در بکاپِ کاملِ سیستم ذخیره می‌شوند. */
    public const SYSTEM_TABLES = [
        'complexes', 'users', 'buildings', 'units', 'unit_user',
        'charge_rules', 'expenses', 'incomes', 'bills', 'payments',
        'discounts', 'announcements', 'announcement_reads', 'messages',
        'settings', 'subscriptions', 'advertisements', 'audit_logs',
    ];

    /**
     * پرکردنِ یک رکوردِ `pending` با فایلِ ساخته‌شده.
     *
     * رکورد از قبل ساخته شده تا کاربر بلافاصله ببیندش («در حال ساخت»)؛ اینجا
     * فقط کامل می‌شود. اگر کاری خطا بدهد، وضعیت `failed` می‌شود و رکورد
     * می‌ماند — پاک‌کردنش یعنی کاربر هرگز نمی‌فهمد چه شد.
     */
    public function fill(Backup $backup): Backup
    {
        $snapshot = $backup->type === 'full'
            ? $this->systemSnapshot()
            : $this->complexSnapshot($backup->complex);

        $name = $backup->type === 'full'
            ? 'backup-system-'.now()->format('Ymd-His').'-'.Str::random(6)
            : 'backup-complex-'.$backup->complex_id.'-'.now()->format('Ymd-His');

        $path = "backups/{$name}.json";

        Storage::disk('local')->put(
            $path,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $backup->update([
            'status' => 'completed',
            'disk' => 'local',
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
        ]);

        return $backup->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function systemSnapshot(): array
    {
        $data = [];

        foreach (self::SYSTEM_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $data[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            }
        }

        return [
            'meta' => ['generated_at' => now()->toIso8601String(), 'type' => 'full'],
            'tables' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function complexSnapshot(Complex $complex): array
    {
        return [
            'meta' => ['generated_at' => now()->toIso8601String(), 'complex_id' => $complex->id],
            'complex' => $complex->toArray(),
            'buildings' => $complex->buildings()->get()->toArray(),
            'units' => $complex->units()->with('residents')->get()->toArray(),
            // رمزها هرگز داخل فایلِ بکاپ نمی‌روند
            'users' => $complex->users()->get()->makeHidden('password')->toArray(),
            'charge_rules' => $complex->chargeRules()->get()->toArray(),
            'expenses' => $complex->expenses()->get()->toArray(),
            'incomes' => $complex->incomes()->get()->toArray(),
            'bills' => $complex->bills()->get()->toArray(),
            'payments' => $complex->payments()->get()->toArray(),
            'announcements' => $complex->announcements()->get()->toArray(),
        ];
    }
}
