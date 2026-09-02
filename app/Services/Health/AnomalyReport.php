<?php

namespace App\Services\Health;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\SmsOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * هشدارهای نشانه‌ای: چیزی که یک لحظه نشان نمی‌دهد (R64).
 *
 * ─── چرا کلاسِ جدا و نه چند سنجه‌ی تازه در `HealthReport` ────────────────────
 * ⚠️ دو دلیلِ مستقل، و هر کدام به‌تنهایی کافی است:
 *
 * ① **پرسش‌ها یکی نیستند.** `HealthReport` می‌پرسد «این گره می‌تواند سرویس
 *    بدهد؟» و پاسخش می‌تواند گره را با ۵۰۳ از مدار خارج کند. اینجا می‌پرسیم
 *    «چیزی به‌طورِ سیستمی خراب شده؟» — پاسخش هرگز نباید سرویس را قطع کند.
 *    درگاهِ بانک که کار نمی‌کند، دلیلی برای پایین‌آوردنِ سایت نیست.
 *
 * ② 🔴 **`/up` باید سبک بماند.** آن مسیر را متعادل‌کننده‌ی بار هر چند ثانیه
 *    می‌زند. چهار کوئریِ تجمیعی روی هر ضربه هزینه‌ای است که تا روزِ شلوغی
 *    دیده نمی‌شود، و آن‌وقت خودِ بررسیِ سلامت می‌شود منبعِ کندی.
 *
 * پس این گزارش فقط از `alerts:scan` (هر پانزده دقیقه) اجرا می‌شود و از
 * همان `HealthAlerter` برای رساندنِ هشدار استفاده می‌کند — یک مسیرِ تحویل،
 * یک خفه‌کن، یک وب‌هوک.
 *
 * ─── چرا `DB::table` و نه Eloquent ─────────────────────────────────────────
 * ⚠️ این سنجه‌ها ذاتاً فرامجتمعی‌اند. با Eloquent، `ComplexScope` در زمینه‌ی
 * کنسول بی‌اثر است ولی اگر روزی کسی همین کد را از دلِ یک درخواست صدا بزند،
 * اعداد **بی‌سروصدا** به مجتمعِ همان کاربر محدود می‌شوند و هشدار روی
 * داده‌ی ناقص تصمیم می‌گیرد. سازنده‌ی کوئری چنین حالتی ندارد.
 */
class AnomalyReport
{
    use MeasuresProbes;

    /** آخرین شمارشِ تجمعیِ خطاها، برای محاسبه‌ی اختلاف. */
    public const ERROR_SNAPSHOT_KEY = 'alerts:errors:snapshot';

    /**
     * کمترین فاصله‌ای که تعمیمِ نرخ رویش معنی می‌دهد (دقیقه).
     *
     * ⚠️ زمان‌بند هر پانزده دقیقه اجرا می‌شود، ولی اجرای دستی هم ممکن است.
     * تقسیمِ اختلاف بر چند ثانیه، هر عددی را به نرخِ نجومی تبدیل می‌کند.
     */
    private const MIN_RATE_MINUTES = 5;

    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>, checked_at: string}
     */
    public function run(): array
    {
        $checks = [
            'errors' => $this->errorSpike(),
            'payments' => $this->paymentFailures(),
            'sms' => $this->smsDelivery(),
            'backups' => $this->backupPipeline(),
        ];

        return [
            'status' => $this->worst($checks),
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * جهشِ خطا.
     *
     * 🔴 **شمردنِ ردیف‌های `error_events` غلط است.** آن جدول با اثرِ انگشت
     * گروه می‌کند: خطایی که ده هزار بار در دقیقه تکرار شود **یک ردیف** است
     * که فقط `occurrences`اش جلو می‌رود. سنجه‌ای که ردیف بشمارد، دقیقاً
     * بدترین حالت — یک خطای حلقه‌زده — را نمی‌بیند.
     *
     * پس مبنا اختلافِ **مجموعِ رخدادها** بین دو اجراست.
     *
     * @return array<string, mixed>
     */
    private function errorSpike(): array
    {
        return $this->timed(function (): array {
            $total = (int) DB::table('error_events')->sum('occurrences');
            $previous = Cache::get(self::ERROR_SNAPSHOT_KEY);

            if (! is_array($previous) || ! isset($previous['total'], $previous['at'])) {
                /*
                 * ⚠️ اولین اجرا (یا پس از خالی‌شدنِ کش) پایه ندارد.
                 * هشدارِ ساختگی بدتر از یک اجرای بی‌نتیجه است.
                 */
                $this->rememberErrorTotal($total);

                return ['status' => HealthReport::OK, 'detail' => 'پایه‌ی شمارش ثبت شد'];
            }

            $minutes = (int) now()->diffInMinutes($previous['at'], absolute: true);

            if ($minutes < self::MIN_RATE_MINUTES) {
                /*
                 * 🔴 این را تستِ خودم پیدا کرد.
                 *
                 * زمان‌بند هر پانزده دقیقه اجرا می‌شود، ولی اپراتور می‌تواند
                 * دستی هم اجرا کند. دو اجرا با چند ثانیه فاصله یعنی تقسیم بر
                 * یک دقیقه: دو خطای بی‌ضرر به «۱۲۰ در ساعت» تعمیم داده
                 * می‌شد و هشدارِ کاملاً ساختگی می‌ساخت.
                 *
                 * ⚠️ و پایه عمداً **به‌روز نمی‌شود**: اگر می‌شد، همان اجرای
                 * دستی پنجره‌ی واقعی را صفر می‌کرد و جهشِ بعدی از دست
                 * می‌رفت. یعنی اجرای دستی، سنجه را کور می‌کرد.
                 */
                return [
                    'status' => HealthReport::OK,
                    'detail' => 'فاصله‌ی کافی از سنجشِ پیشین نگذشته؛ پایه دست‌نخورده ماند',
                ];
            }

            $delta = $total - (int) $previous['total'];

            $this->rememberErrorTotal($total);

            if ($delta < 0) {
                /*
                 * ⚠️ جدول هرس می‌شود (`observability.error_log.retention_days`)
                 * پس مجموع می‌تواند **کم** شود. تفسیرِ عددِ منفی به‌عنوان نرخ
                 * یعنی هشدار درست بعد از هر هرس.
                 */
                return ['status' => HealthReport::OK, 'detail' => 'جدول هرس شده؛ پایه از نو ثبت شد'];
            }

            $perHour = (int) round($delta / $minutes * 60);
            $limit = max(1, (int) config('alerts.errors.per_hour', 60));

            return [
                'status' => $perHour > $limit ? HealthReport::DEGRADED : HealthReport::OK,
                'detail' => sprintf('%d خطا در %d دقیقه (معادلِ %d در ساعت، سقف %d)', $delta, $minutes, $perHour, $limit),
                'per_hour' => $perHour,
                'delta' => $delta,
            ];
        }, HealthReport::DEGRADED);
    }

    /**
     * جهشِ شکستِ پرداخت.
     *
     * ⚠️ **هم کفِ تعداد، هم نسبت.** مجتمعی که ساعتی دو پرداخت دارد با یک
     * انصرافِ ساده به پنجاه درصد می‌رسد؛ هشدارِ هرروزه‌ی بی‌مورد همان چیزی
     * است که باعث می‌شود روزِ قطعیِ واقعیِ درگاه کسی هشدار را نخواند.
     *
     * 🔴 «بلاتکلیف» (R59) شکست حساب می‌شود: از دیدِ ساکن با ناموفق فرقی
     * ندارد، و از دیدِ اپراتور بدتر است چون ممکن است پول کسر شده باشد.
     *
     * @return array<string, mixed>
     */
    private function paymentFailures(): array
    {
        return $this->timed(function (): array {
            $since = now()->subMinutes($this->windowMinutes());

            $attempts = DB::table('payments')
                ->where('method', PaymentMethod::Online->value)
                ->where('updated_at', '>=', $since)
                ->count();

            $failed = DB::table('payments')
                ->where('method', PaymentMethod::Online->value)
                ->where('status', PaymentStatus::Failed->value)
                ->where('updated_at', '>=', $since)
                ->count();

            $indeterminate = DB::table('audit_logs')
                ->where('action', 'payment.indeterminate')
                ->where('created_at', '>=', $since)
                ->count();

            $bad = $failed + $indeterminate;
            $floor = max(1, (int) config('alerts.payments.min_attempts', 5));
            $limit = max(1, (int) config('alerts.payments.failure_percent', 50));

            if ($attempts < $floor) {
                return [
                    'status' => HealthReport::OK,
                    'detail' => sprintf('%d تراکنش در پنجره — کمتر از کفِ %d، قضاوت نمی‌شود', $attempts, $floor),
                ];
            }

            $percent = (int) round($bad / $attempts * 100);

            return [
                'status' => $percent >= $limit ? HealthReport::DEGRADED : HealthReport::OK,
                'detail' => sprintf(
                    '%d از %d تراکنش ناموفق یا بلاتکلیف (%d٪، سقف %d٪)',
                    $bad,
                    $attempts,
                    $percent,
                    $limit,
                ),
                'attempts' => $attempts,
                'failed' => $failed,
                'indeterminate' => $indeterminate,
                'percent' => $percent,
            ];
        }, HealthReport::DEGRADED);
    }

    /**
     * شکستِ پیامک.
     *
     * 🔴 **تنها سنجه‌ی این گزارش که به `down` می‌رسد.** پیامکِ این سامانه
     * فقط کدِ ورود است؛ شکستِ کاملش یعنی **هیچ‌کس نمی‌تواند وارد شود** در
     * حالی که سایت بالاست، دیتابیس سالم است و `/up` سبز می‌ماند.
     *
     * ⚠️ «کامل» باید واقعاً کامل باشد: یک شماره‌ی خاموش هم از بعضی درگاه‌ها
     * ناموفق برمی‌گردد، پس کفِ تعداد اینجا هم شرط است.
     *
     * @return array<string, mixed>
     */
    private function smsDelivery(): array
    {
        return $this->timed(function (): array {
            $window = SmsOutcome::window();
            $attempts = $window['attempts'];
            $failed = $window['failed'];

            $floor = max(1, (int) config('alerts.sms.min_attempts', 3));
            $limit = max(1, (int) config('alerts.sms.failure_percent', 50));

            if ($attempts < $floor) {
                return [
                    'status' => HealthReport::OK,
                    'detail' => sprintf('%d ارسال در پنجره — کمتر از کفِ %d، قضاوت نمی‌شود', $attempts, $floor),
                ];
            }

            $percent = (int) round($failed / $attempts * 100);

            $status = match (true) {
                $failed === $attempts => HealthReport::DOWN,
                $percent >= $limit => HealthReport::DEGRADED,
                default => HealthReport::OK,
            };

            $detail = sprintf('%d از %d ارسال ناموفق (%d٪)', $failed, $attempts, $percent);

            if ($status === HealthReport::DOWN) {
                $detail = 'هیچ پیامکی نمی‌رود؛ ورود با کد ممکن نیست — '.$detail;
            }

            if ($window['error'] !== null && $status !== HealthReport::OK) {
                $detail .= ' · آخرین خطا: '.$window['error'];
            }

            return [
                'status' => $status,
                'detail' => $detail,
                'attempts' => $attempts,
                'failed' => $failed,
                'percent' => $percent,
            ];
        }, HealthReport::DEGRADED);
    }

    /**
     * شکستِ بکاپ.
     *
     * 🔴 اندازه‌گیری‌شده پیش از R64: `BuildBackupJob::failed()` وضعیتِ ردیف
     * را `failed` می‌کرد و **هیچ‌جای کد آن ستون را نمی‌خواند**.
     *
     * سه نشانه سنجیده می‌شود، چون بکاپ سه‌جور می‌میرد:
     *
     * ① ردیفی که `failed` شده — کار اجرا شد و ترکید
     * ② ردیفی که برای همیشه `pending` مانده — ⚠️ ساکت‌ترین حالت: ردیف
     *    **پیش از** صف‌شدن ساخته می‌شود، پس کارگرِ مرده نه خطایی می‌سازد
     *    نه ردیفِ قرمزی
     * ③ تازه‌ترین بکاپِ سالم که از RPO عقب افتاده — یعنی زمان‌بند اجرا
     *    نمی‌شود
     *
     * ⚠️ عمداً فایلی باز یا رمزگشایی نمی‌شود؛ آن کارِ سنگین مالِ
     * `backups:verify` هفتگی است. اینجا فقط نشانه‌های ارزان سنجیده می‌شوند
     * تا شکاف بینِ دو اجرای هفتگی پوشیده شود.
     *
     * @return array<string, mixed>
     */
    private function backupPipeline(): array
    {
        return $this->timed(function (): array {
            $since = now()->subMinutes($this->windowMinutes());
            $stale = max(1, (int) config('alerts.backups.pending_minutes', 60));

            $failed = DB::table('backups')
                ->where('status', 'failed')
                ->where('updated_at', '>=', $since)
                ->count();

            $stuck = DB::table('backups')
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subMinutes($stale))
                ->count();

            $newest = DB::table('backups')
                ->whereNull('complex_id')
                ->where('type', 'full')
                ->where('status', 'completed')
                ->max('created_at');

            // یک ساعت ارفاق، مثلِ `backups:verify`: بکاپِ ۰۱:۳۰ و سنجشِ ۰۱:۲۹
            $rpo = max(1, (int) config('backup.rpo_hours', 24)) + 1;
            $ageHours = $newest === null
                ? null
                : (int) now()->diffInHours($newest, absolute: true);

            $problems = [];

            if ($failed > 0) {
                $problems[] = $failed.' بکاپِ شکست‌خورده';
            }

            if ($stuck > 0) {
                $problems[] = $stuck.' بکاپ بیش از '.$stale.' دقیقه در انتظار مانده (کارگرِ صف؟)';
            }

            if ($ageHours === null) {
                $problems[] = 'هیچ بکاپِ کاملِ تمام‌شده‌ای وجود ندارد';
            } elseif ($ageHours > $rpo) {
                $problems[] = 'تازه‌ترین بکاپ '.$ageHours.' ساعت پیش است، ولی RPO '.($rpo - 1).' ساعت اعلام شده';
            }

            return $problems === []
                ? [
                    'status' => HealthReport::OK,
                    'detail' => $ageHours === null
                        ? 'سالم است'
                        : 'تازه‌ترین بکاپ '.$ageHours.' ساعت پیش',
                ]
                : [
                    'status' => HealthReport::DEGRADED,
                    'detail' => implode('؛ ', $problems),
                ];
        }, HealthReport::DEGRADED);
    }

    /**
     * ⚠️ پایه فقط وقتی جابه‌جا می‌شود که واقعاً پنجره‌ای مصرف شده باشد.
     *
     * اجرای دستی که فاصله‌اش از سنجشِ پیشین کم است، عمداً پایه را دست
     * نمی‌زند — وگرنه همان اجرا پنجره‌ی واقعی را صفر می‌کرد و جهشِ بعدی
     * دیده نمی‌شد.
     */
    private function rememberErrorTotal(int $total): void
    {
        Cache::put(
            self::ERROR_SNAPSHOT_KEY,
            ['total' => $total, 'at' => now()->toIso8601String()],
            // دو برابرِ پنجره: پایه‌ی خیلی کهنه بی‌فایده است و بهتر است
            // منقضی شود تا اینکه نرخی از روی چند ساعت اختلاف ساخته شود
            $this->windowMinutes() * 120,
        );
    }

    private function windowMinutes(): int
    {
        return max(1, (int) config('alerts.window_minutes', 60));
    }
}
