<?php

namespace App\Console\Commands;

use App\Services\Messenger\ConversationBackfill;
use Illuminate\Console\Command;

/**
 * پیامِ بی‌گفت‌وگو → گفت‌وگو (مرحلهٔ ۶ · فاز ۱۲).
 *
 * مهاجرت همین کار را یک بار می‌کند. ⚠️ ولی در فاصله‌ی استقرار — پس از
 * مهاجرت و پیش از رسیدنِ کدِ تازه — کدِ قدیمی هنوز پیام می‌نویسد، بی‌گفت‌وگو.
 * این دستور پس از استقرار همان‌ها را هم می‌گیرد. تکرارش بی‌خطر است.
 */
class BackfillConversations extends Command
{
    protected $signature = 'messenger:backfill-conversations';

    protected $description = 'پیام‌های بی‌گفت‌وگو را در گفت‌وگوی متناظرشان می‌گذارد (تکرارپذیر).';

    public function handle(ConversationBackfill $backfill): int
    {
        $count = $backfill->run();

        $this->info("{$count} پیام گفت‌وگو گرفت.");

        return self::SUCCESS;
    }
}
