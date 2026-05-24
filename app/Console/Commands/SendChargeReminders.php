<?php

namespace App\Console\Commands;

use App\Models\Complex;
use App\Services\ReminderService;
use Illuminate\Console\Command;

class SendChargeReminders extends Command
{
    protected $signature = 'reminders:charges {--cooldown=3 : روزهای فاصله بین یادآوری‌ها}';

    protected $description = 'ارسال پیامک یادآوری برای قبوض معوق همه مجتمع‌ها';

    public function handle(ReminderService $reminders): int
    {
        $total = 0;
        foreach (Complex::where('is_active', true)->get() as $complex) {
            $count = $reminders->sendForComplex($complex, null, (int) $this->option('cooldown'));
            $total += $count;
            $this->line("«{$complex->name}»: {$count} یادآوری");
        }

        $this->info("مجموع یادآوری‌های ارسال‌شده: {$total}");

        return self::SUCCESS;
    }
}
