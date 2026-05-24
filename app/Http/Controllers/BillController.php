<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Support\Pdf;
use Illuminate\Support\Facades\Auth;

class BillController extends Controller
{
    public function index()
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');

        $bills = Bill::whereIn('unit_id', $unitIds)
            ->with('unit')
            ->orderByDesc('period')
            ->paginate(15);

        return view('bills.index', compact('bills'));
    }

    public function show(Bill $bill)
    {
        $this->authorizeBill($bill);

        $bill->load('unit', 'payments');

        return view('bills.show', compact('bill'));
    }

    public function pdf(Bill $bill)
    {
        $this->authorizeBill($bill);
        $bill->load('unit', 'complex');

        $content = Pdf::fromView('pdf.invoice', ['bill' => $bill]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$bill->unit->unit_number.'-'.$bill->period.'.pdf"',
        ]);
    }

    /** Ensure the bill belongs to one of the signed-in user's units. */
    protected function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');
        abort_unless($unitIds->contains($bill->unit_id) || Auth::user()->isAdmin(), 403);
    }
}
