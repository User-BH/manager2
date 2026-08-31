<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * وصله‌ی کانالِ لاگ، تا `SecretScrubber` روی همه‌ی خطوطش اجرا شود (R55).
 *
 * ⚠️ پردازنده روی **هر handler** جداگانه سوار می‌شود، نه روی خودِ logger.
 *
 * دلیلش رفتارِ Monolog است: پردازنده‌ی سطحِ logger پیش از رسیدن به
 * handlerها اجرا می‌شود و برای کانالِ `stack` — که چند handler دارد —
 * درست کار می‌کند؛ ولی وقتی لاراول کانال را از روی درایور می‌سازد،
 * مطمئن‌ترین جا خودِ handler است.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        /*
         * ⚠️ `getLogger()` در قرارداد `LoggerInterface` برمی‌گرداند، نه
         * `Monolog\Logger`. برای کانال‌های غیرِ Monolog (مثلِ کانالِ null
         * در تست) اصلاً handler وجود ندارد، پس بررسی لازم است — نه فقط
         * برای تحلیلِ ایستا، که برای درستیِ خودِ کد.
         */
        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor(new SecretScrubber);
            }
        }
    }
}
