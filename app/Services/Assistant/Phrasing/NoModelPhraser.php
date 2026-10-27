<?php

namespace App\Services\Assistant\Phrasing;

/** پیش‌فرض: بی مدلِ زبانی — پاسخِ قطعیِ ساخته‌شده از داده همان است که برمی‌گردد. */
final class NoModelPhraser implements Phraser
{
    public function rephrase(string $answer): ?string
    {
        return null;
    }

    public function name(): string
    {
        return 'none';
    }
}
