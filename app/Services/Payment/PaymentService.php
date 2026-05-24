<?php

namespace App\Services\Payment;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Mark a payment successful and allocate its amount to the unit's bills,
     * starting with the linked bill (if any) then oldest unpaid bills.
     */
    public function settle(Payment $payment, ?User $actor = null, ?string $note = null): void
    {
        DB::transaction(function () use ($payment, $actor, $note) {
            $payment->status = PaymentStatus::Success;
            $payment->paid_at ??= now();
            if ($actor) {
                $payment->reviewed_by = $actor->id;
                $payment->reviewed_at = now();
            }
            if ($note !== null) {
                $payment->review_note = $note;
            }
            $payment->save();

            $this->allocate($payment);

            $this->log($payment, 'payment.settled', $actor, 'تایید/تسویه پرداخت');
        });
    }

    public function reject(Payment $payment, ?User $actor = null, ?string $note = null): void
    {
        $payment->status = PaymentStatus::Rejected;
        $payment->reviewed_by = $actor?->id;
        $payment->reviewed_at = now();
        $payment->review_note = $note;
        $payment->save();

        $this->log($payment, 'payment.rejected', $actor, 'رد رسید پرداخت');
    }

    /** Spread a successful payment across the unit's outstanding bills. */
    protected function allocate(Payment $payment): void
    {
        $remaining = (float) $payment->amount;

        $bills = collect();
        if ($payment->bill_id) {
            $linked = Bill::withoutGlobalScopes()->find($payment->bill_id);
            if ($linked) {
                $bills->push($linked);
            }
        }

        $others = Bill::withoutGlobalScopes()
            ->where('unit_id', $payment->unit_id)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->when($payment->bill_id, fn ($q) => $q->where('id', '!=', $payment->bill_id))
            ->orderBy('due_date')
            ->orderBy('period')
            ->get();

        foreach ($bills->concat($others) as $bill) {
            if ($remaining <= 0) {
                break;
            }
            $due = $bill->remaining();
            if ($due <= 0) {
                continue;
            }
            $apply = min($due, $remaining);
            $bill->paid_amount = (float) $bill->paid_amount + $apply;
            $bill->syncStatus();
            $remaining -= $apply;
        }

        // Any overpayment reduces the unit's cached balance as a credit.
        Unit::withoutGlobalScopes()->find($payment->unit_id)?->recalculateBalance();
    }

    protected function log(Payment $payment, string $action, ?User $actor, string $description): void
    {
        AuditLog::create([
            'complex_id' => $payment->complex_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'description' => $description,
            'ip_address' => request()->ip(),
            'properties' => ['amount' => $payment->amount, 'method' => $payment->method->value],
            'created_at' => now(),
        ]);
    }
}
