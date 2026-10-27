<?php

namespace App\Console\Commands;

use App\Models\AssistantMessage;
use Illuminate\Console\Command;

/** سیاستِ نگه‌داریِ تاریخچه‌ی دستیار (ایرادات ۱۱، بندِ ۴.۱۴): کهنه‌تر از `assistant.history.keep_days` پاک می‌شود. */
class PruneAssistantHistory extends Command
{
    protected $signature = 'assistant:prune';

    protected $description = 'پاک‌کردنِ تاریخچه‌ی کهنه‌ی دستیار';

    public function handle(): int
    {
        $deleted = AssistantMessage::where('created_at', '<', now()->subDays((int) config('assistant.history.keep_days', 30)))->delete();
        $this->info("{$deleted} پیامِ کهنه‌ی دستیار پاک شد.");

        return self::SUCCESS;
    }
}
