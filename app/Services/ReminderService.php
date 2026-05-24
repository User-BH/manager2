<?php

namespace App\Services;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Complex;
use App\Services\Sms\SmsManager;
use App\Support\Jalali;

class ReminderService
{
    public function __construct(protected SmsManager $sms) {}

    /**
     * Send SMS reminders for overdue, still-unpaid bills of a complex.
     * Skips bills reminded within the cooldown window to avoid spamming.
     *
     * @return int number of reminders sent
     */
    public function sendForComplex(Complex $complex, ?string $period = null, int $cooldownDays = 3): int
    {
        $query = Bill::withoutGlobalScopes()
            ->where('complex_id', $complex->id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->where(function ($q) use ($cooldownDays) {
                $q->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<', now()->subDays($cooldownDays));
            })
            ->with('unit');

        if ($period) {
            $query->where('period', $period);
        }

        $sent = 0;
        foreach ($query->get() as $bill) {
            $phone = $this->recipientPhone($bill);
            if (! $phone) {
                continue;
            }

            $message = sprintf(
                'ساکن گرامی، بدهی شارژ واحد %s دوره %s مبلغ %s %s است. لطفا نسبت به پرداخت اقدام کنید.',
                $bill->unit->unit_number,
                Jalali::periodLabel($bill->period),
                number_format($bill->remaining()),
                $complex->currencyLabel(),
            );

            if ($this->sms->send($phone, $message)) {
                $bill->forceFill(['last_reminded_at' => now()])->saveQuietly();
                $sent++;
            }
        }

        return $sent;
    }

    protected function recipientPhone(Bill $bill): ?string
    {
        $unit = $bill->unit;
        $resident = $unit->tenants()->first() ?? $unit->owners()->first();

        return $resident?->phone;
    }
}
