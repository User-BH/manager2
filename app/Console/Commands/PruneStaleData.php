<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * اعمالِ سیاستِ نگه‌داریِ داده (R45).
 *
 * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────────
 * پنج جدولِ پرترافیک **هیچ سیاستِ نگه‌داری‌ای نداشتند**: `otp_codes`،
 * `sessions`، `notifications`، `audit_logs` و `error_events`.
 *
 * ⚠️ بدترینشان `otp_codes` است: هر تلاشِ ورود یک ردیف می‌سازد و ردیفی که
 * چند دقیقه بعد منقضی شده، تا ابد می‌ماند. در مقیاسِ یک‌میلیون کاربر این
 * پرحجم‌ترین جدولِ سامانه می‌شود بی‌آنکه یک ردیفش به درد بخورد.
 *
 * ⚠️ و `sessions` از آن هم بدتر عمل می‌کند، چون در **هر درخواست** خوانده و
 * نوشته می‌شود. لاراول نشستِ دیتابیسی را خودش پاک نمی‌کند؛
 * `SESSION_LIFETIME` فقط اعتبار را تعیین می‌کند، نه پاک‌شدنِ ردیف را.
 *
 * ─── چرا حذفِ تکه‌تکه ──────────────────────────────────────────────────────
 * ⚠️ یک `DELETE` بدونِ سقف روی جدولی با ده‌ها میلیون ردیف، تراکنشی می‌سازد
 * که دقایق طول می‌کشد و در تمامِ آن مدت ردیف‌ها قفل‌اند. یعنی دستوری که
 * برای سلامتِ سامانه نوشته شده، خودش در ساعتِ اجرا سامانه را می‌خواباند —
 * و چون شبانه اجرا می‌شود، صبح کسی نمی‌فهمد چه شد.
 */
class PruneStaleData extends Command
{
    protected $signature = 'data:prune
                            {--dry-run : فقط بشمار، چیزی پاک نکن}
                            {--table= : فقط همین یک جدول}';

    protected $description = 'اعمالِ سیاستِ نگه‌داری روی جدول‌های پرترافیک';

    public function handle(): int
    {
        /*
         * ⚠️ کفِ ۱ است، نه ۱۰۰ — و این را یک خرابکاریِ عمدی نشان داد.
         *
         * نسخه‌ی اول `max(100, ...)` داشت. نتیجه این بود که تستِ
         * «حذف باید تکه‌تکه باشد» هرگز تکه‌بندی را نمی‌سنجید: تست تکه را
         * روی ۲ می‌گذاشت، کف آن را به ۱۰۰ می‌برد، و شش ردیف در یک حرکت
         * پاک می‌شدند. یعنی محافظِ اصلیِ این دستور پوچ بود.
         *
         * کف فقط برای جلوگیری از حلقه‌ی بی‌پایان است (تکه‌ی صفر یعنی هر
         * دور صفر ردیف پاک شود)، نه برای تعیینِ کارایی.
         */
        $chunk = max(1, (int) config('retention.chunk', 1000));
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('table');

        $rows = [];

        foreach ((array) config('retention.rules', []) as $rule) {
            if ($only !== null && $rule['table'] !== $only) {
                continue;
            }

            /*
             * ⚠️ جدولِ ناموجود رد می‌شود، نه اینکه دستور را بترکاند.
             *
             * این دستور شبانه اجرا می‌شود؛ اگر روزی جدولی حذف شود، شکستنِ
             * کلِ دستور یعنی چهار قاعده‌ی سالمِ دیگر هم اجرا نشوند و
             * جدول‌هایشان بی‌صدا شروع به رشد کنند.
             */
            if (! Schema::hasTable($rule['table'])) {
                $this->warn("جدولِ «{$rule['table']}» وجود ندارد؛ رد شد.");

                continue;
            }

            $deleted = $dry
                ? $this->query($rule)->count()
                : $this->deleteInChunks($rule, $chunk);

            $rows[] = [
                $rule['table'],
                $rule['label'] ?? '—',
                $rule['days'].' روز',
                number_format($deleted),
            ];
        }

        $this->table(['جدول', 'شرح', 'نگه‌داری', $dry ? 'قابلِ حذف' : 'حذف‌شده'], $rows);

        return self::SUCCESS;
    }

    /**
     * حذف در تکه‌های کوچک، تا قفلِ طولانی روی جدول نیفتد.
     *
     * @param  array<string, mixed>  $rule
     */
    private function deleteInChunks(array $rule, int $chunk): int
    {
        $total = 0;

        /*
         * ⚠️ حلقه‌ی بی‌پایان ممکن نیست: هر دور دقیقاً ردیف‌هایی را می‌برد
         * که شرط را دارند، پس تعدادشان اکیداً کم می‌شود و وقتی حذف صفر
         * برگرداند حلقه تمام است.
         */
        do {
            $removed = $this->query($rule)->limit($chunk)->delete();
            $total += $removed;
        } while ($removed > 0);

        return $total;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function query(array $rule): Builder
    {
        $days = max(1, (int) $rule['days']);
        $cutoff = now()->subDays($days);

        $query = DB::table($rule['table']);

        /*
         * ⚠️ ستونِ `last_activity` جدولِ `sessions` مُهرِ یونیکس است، نه
         * تاریخ. مقایسه‌اش با یک رشته‌ی تاریخ در MySQL خطا نمی‌دهد — بی‌صدا
         * به صفر تبدیل می‌شود و **هر ردیف** را شاملِ حذف می‌کند. یعنی
         * همه‌ی کاربرانِ آنلاین در همان لحظه بیرون انداخته می‌شوند.
         */
        $query = ($rule['unix'] ?? false)
            ? $query->where($rule['column'], '<', $cutoff->getTimestamp())
            : $query->where($rule['column'], '<', $cutoff);

        if (isset($rule['where'])) {
            [$column, $condition] = $rule['where'];

            $query = $condition === 'not null'
                ? $query->whereNotNull($column)
                : $query->whereNull($column);
        }

        return $query;
    }
}
