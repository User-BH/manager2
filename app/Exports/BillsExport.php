<?php

namespace App\Exports;

use App\Models\Bill;
use App\Support\Jalali;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class BillsExport implements FromCollection, WithHeadings, WithMapping, WithTitle, WithEvents
{
    public function __construct(protected Collection $bills, protected string $period) {}

    public function collection(): Collection
    {
        return $this->bills;
    }

    public function title(): string
    {
        return Jalali::periodLabel($this->period);
    }

    public function headings(): array
    {
        return [
            'واحد', 'طبقه', 'دوره', 'مالکانه', 'مستاجرانه',
            'جریمه', 'تخفیف', 'مبلغ کل', 'پرداخت‌شده', 'مانده', 'وضعیت',
        ];
    }

    /** @param Bill $bill */
    public function map($bill): array
    {
        return [
            $bill->unit->unit_number,
            $bill->unit->floor,
            Jalali::periodLabel($bill->period),
            (float) $bill->owner_amount,
            (float) $bill->tenant_amount,
            (float) $bill->penalty_amount,
            (float) $bill->discount_amount,
            (float) $bill->total_amount,
            (float) $bill->paid_amount,
            $bill->remaining(),
            $bill->status->label(),
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getDelegate()->setRightToLeft(true);
            },
        ];
    }
}
