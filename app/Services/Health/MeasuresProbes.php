<?php

namespace App\Services\Health;

use Illuminate\Support\Str;
use Throwable;

/**
 * قواعدِ مشترکِ هر گزارشی که چند سنجه را کنارِ هم می‌گذارد (R64).
 *
 * ─── چرا صفت و نه کپیِ دوم ─────────────────────────────────────────────────
 * ⚠️ `HealthReport` (R43) و `AnomalyReport` (R64) دو پرسشِ متفاوت می‌پرسند و
 * عمداً دو کلاسِ جدا هستند، ولی **دو قاعده‌ی ایمنیِ یکسان** دارند. کپی‌کردنِ
 * آن دو قاعده یعنی روزی یکی اصلاح شود و دیگری نه — و آن روز، همان کپیِ
 * اصلاح‌نشده جایی است که گزارش می‌ترکد.
 */
trait MeasuresProbes
{
    /**
     * بدترین وضعیتِ میانِ همه‌ی سنجه‌ها — یک `down` کلِ گزارش را `down` می‌کند.
     *
     * @param  array<string, array<string, mixed>>  $checks
     */
    public function worst(array $checks): string
    {
        $states = array_column($checks, 'status');

        if (in_array(HealthReport::DOWN, $states, true)) {
            return HealthReport::DOWN;
        }

        return in_array(HealthReport::DEGRADED, $states, true)
            ? HealthReport::DEGRADED
            : HealthReport::OK;
    }

    /**
     * اجرای یک سنجه با زمان‌گیری و مهارِ استثنا.
     *
     * ⚠️ هیچ سنجه‌ای حق ندارد خودِ گزارش را بترکاند. اگر یکی خطای غیرمنتظره
     * بدهد و اینجا گرفته نشود، بقیه‌ی سنجه‌ها هم دیده نمی‌شوند — یعنی درست
     * وقتی که بیشترین نیاز را به گزارش داریم، گزارشی نداریم.
     *
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    protected function timed(callable $probe, string $onFailure): array
    {
        $started = microtime(true);

        try {
            $result = $probe();
        } catch (Throwable $e) {
            $result = [
                'status' => $onFailure,
                // ⚠️ پیام کوتاه و یک‌خطی می‌شود: استثناهای PDO رشته‌ی اتصال،
                // نامِ کاربر و گاهی مسیرِ سرور را در خود دارند
                'detail' => Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 120),
            ];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }
}
