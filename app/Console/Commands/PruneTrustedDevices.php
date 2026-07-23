<?php

namespace App\Console\Commands;

use App\Models\TrustedDevice;
use Illuminate\Console\Command;

/**
 * پاک‌کردن دستگاه‌های مورداعتمادِ منقضی‌شده.
 *
 * کوکیِ منقضی دیگر کاربر را وارد نمی‌کند (سرویس انقضا را بررسی می‌کند)، ولی
 * ردیفش تا ابد در جدول می‌ماند. این دستور جدول را تمیز نگه می‌دارد.
 */
class PruneTrustedDevices extends Command
{
    protected $signature = 'trusted-devices:prune';

    protected $description = 'حذف دستگاه‌های مورداعتمادِ منقضی‌شده';

    public function handle(): int
    {
        $deleted = TrustedDevice::where('expires_at', '<=', now())->delete();

        $this->info("{$deleted} دستگاه مورداعتمادِ منقضی حذف شد.");

        return self::SUCCESS;
    }
}
