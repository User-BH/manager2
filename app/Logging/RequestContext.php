<?php

namespace App\Logging;

use App\Support\RequestId;
use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * چسباندنِ شناسه‌ی درخواست به **هر** خطِ لاگ (R63).
 *
 * ─── چرا پردازنده و نه افزودنِ دستی به هر `Log::` ─────────────────────────
 * ⚠️ همان استدلالِ `SecretScrubber` در R55: رفعِ دستی امروز درست است و
 * فردا نه. هر `Log::info()`ی که کسی بنویسد و شناسه را فراموش کند، همان
 * خطی است که روزِ حادثه لازم می‌شود.
 *
 * با پردازنده، شناسه روی خطوطی هم می‌نشیند که خودِ فریم‌ورک می‌نویسد —
 * کوئریِ کند، استثنای مهارنشده، شکستِ کارِ صف.
 *
 * ─── ⚠️ و درسِ همان مرحله، دوباره ─────────────────────────────────────────
 * پردازنده **نباید استثنا بدهد**. لاگ‌نویسی مسیرِ خطاست؛ اگر اینجا چیزی
 * بترکد، پیامِ اصلی گم می‌شود و به‌جای یک خرابی، دو تا داریم.
 */
class RequestContext
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        /*
         * ⚠️ `getLogger()` قرارداد را برمی‌گرداند نه `Monolog\Logger`؛ برای
         * کانالِ `null`ِ تست اصلاً handler وجود ندارد.
         */
        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor(new RequestIdProcessor);
            }
        }
    }
}

/**
 * پردازنده‌ی واقعی.
 *
 * ⚠️ در `extra` می‌نشیند و نه در `context`: `context` جای داده‌ی خودِ پیام
 * است و اگر شناسه آنجا برود، هر `Log::info('...', ['request_id' => ...])`ی
 * که کسی بنویسد رویش را می‌گیرد.
 */
class RequestIdProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'request_id' => RequestId::current()]);
    }
}
