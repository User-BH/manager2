<?php

namespace App\Http\Controllers;

use App\Models\Bill;
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

    /** Ensure the bill belongs to one of the signed-in user's units. */
    protected function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');
        abort_unless($unitIds->contains($bill->unit_id) || Auth::user()->isAdmin(), 403);
    }
}
