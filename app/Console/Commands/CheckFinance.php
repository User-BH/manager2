<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancialInvariants;
use Illuminate\Console\Command;

/**
 * ناوردایی‌های مالی (مرحلهٔ ۱۰ · فاز ۱۳؛ بندهای ۲ و ۱۰۵): کشِ مانده‌ی واحد با قبض‌ها، پرداخت‌شده‌ی قبض با دفتر، و
 * هیچ قبضِ بیش‌پرداخت.
 *
 * `--repair` فقط کشِ مانده‌ی واحد را از قبض‌ها دوباره می‌شمارد. ناهمخوانیِ قبض با دفتر هرگز خودکار درست نمی‌شود —
 * یعنی ردِ پولی گم شده و تصمیمِ انسان می‌خواهد؛ فرمان با خطا تمام می‌شود.
 */
class CheckFinance extends Command
{
    protected $signature = 'finance:check
        {--complex= : فقط همین مجتمع}
        {--repair : کشِ مانده‌ی واحدهای ناهمخوان را از قبض‌ها دوباره بشمار}';

    protected $description = 'هم‌خوانیِ مانده‌ی واحد با قبض‌ها و پرداخت‌شده‌ی قبض با دفترِ پرداخت و کیف را می‌سنجد';

    private const LABELS = [
        'unit_balance' => 'مانده‌ی واحد #%d: کش %s، از قبض‌ها %s',
        'bill_ledger' => 'قبض #%d: پرداخت‌شده %s، از دفتر %s',
        'bill_overpaid' => 'قبض #%d: پرداخت‌شده %s بیش از مبلغِ %s یا منفی',
    ];

    public function handle(FinancialInvariants $invariants): int
    {
        $complexId = $this->option('complex') !== null ? (int) $this->option('complex') : null;

        if ($this->option('repair')) {
            $repaired = $invariants->repairUnitBalances($complexId);
            $this->line("کشِ مانده‌ی {$repaired} واحد از قبض‌ها دوباره شمرده شد.");
        }

        $problems = $invariants->violations($complexId);

        if ($problems === []) {
            $this->info('مانده‌ی واحدها، قبض‌ها و دفتر هم‌خوان‌اند.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error(sprintf(
                self::LABELS[$problem['kind']],
                $problem['id'],
                $problem['actual'],
                $problem['expected'],
            ));
        }

        return self::FAILURE;
    }
}
