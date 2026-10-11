<?php

namespace App\Console\Commands;

use App\Services\Account\MembershipLedger;
use Illuminate\Console\Command;

/**
 * هم‌خوانیِ عضویت‌ها با زمینه‌ی فعالِ حساب‌ها (مرحلهٔ ۱۰ · فاز ۲).
 *
 * پس از مهاجرتِ `complex_memberships` روی هر محیط یک بار اجرا شود: هر حسابِ عضو
 * باید عضویتِ فعالِ هم‌خوان داشته باشد و هیچ عضویتِ فعالی بی‌صاحب نماند. چیزی
 * را درست نمی‌کند — فقط می‌گوید؛ اصلاحِ داده تصمیمِ انسان است.
 */
class CheckMemberships extends Command
{
    protected $signature = 'memberships:check';

    protected $description = 'هم‌خوانیِ عضویت‌های مجتمع با مجتمع و نقشِ فعالِ هر حساب را می‌سنجد';

    public function handle(MembershipLedger $ledger): int
    {
        $problems = $ledger->violations();

        if ($problems === []) {
            $this->info('عضویت‌ها و حساب‌ها هم‌خوان‌اند.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
