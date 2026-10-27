<?php

namespace App\Console\Commands;

use App\Services\Calendar\ReminderDispatcher;
use Illuminate\Console\Command;

/**
 * یادآورهای سررسیده‌ی تقویم را به زنگوله می‌فرستد (مرحلهٔ ۱۳ · فاز ۷). زمان‌بند هر دقیقه اجرایش می‌کند
 * (`routes/console.php`)؛ اجرای دستی یا هم‌زمان هم چیزی را دو بار نمی‌فرستد (`ReminderDispatcher`).
 */
class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'یادآورهای سررسیده‌ی تقویم را به اعلان‌ها می‌فرستد';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $sent = $dispatcher->dispatchDue();

        if ($sent > 0) {
            $this->info("{$sent} یادآور فرستاده شد.");
        }

        return self::SUCCESS;
    }
}
