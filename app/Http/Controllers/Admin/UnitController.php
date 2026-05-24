<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OccupancyStatus;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        $units = Unit::with('building')->orderBy('unit_number')->paginate(20);

        return view('admin.units.index', compact('units'));
    }

    public function create()
    {
        return view('admin.units.form', [
            'unit' => new Unit(['coefficient' => 1, 'residents_count' => 1, 'uses_elevator' => true]),
            'buildings' => Building::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Unit::create($this->validateData($request));

        return redirect()->route('admin.units.index')->with('success', 'واحد جدید ثبت شد.');
    }

    public function edit(Unit $unit)
    {
        return view('admin.units.form', [
            'unit' => $unit,
            'buildings' => Building::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Unit $unit)
    {
        $unit->update($this->validateData($request));

        return redirect()->route('admin.units.index')->with('success', 'واحد به‌روزرسانی شد.');
    }

    public function destroy(Unit $unit)
    {
        $unit->delete();

        return redirect()->route('admin.units.index')->with('success', 'واحد حذف شد.');
    }

    /** Settlement statement for a unit (useful on move-out / sale). */
    public function statement(Unit $unit)
    {
        return view('admin.units.statement', $this->statementData($unit));
    }

    public function statementPdf(Unit $unit)
    {
        $content = \App\Support\Pdf::fromView('pdf.statement', $this->statementData($unit));

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement-'.$unit->unit_number.'.pdf"',
        ]);
    }

    protected function statementData(Unit $unit): array
    {
        $unit->load(['bills' => fn ($q) => $q->orderBy('period'), 'owners', 'tenants']);
        $payments = $unit->payments()->where('status', \App\Enums\PaymentStatus::Success)->latest()->get();

        return [
            'unit' => $unit,
            'complex' => $unit->complex,
            'bills' => $unit->bills,
            'payments' => $payments,
            'totalDebt' => (float) $unit->bills->sum(fn ($b) => $b->remaining()),
        ];
    }

    protected function validateData(Request $request): array
    {
        $request->merge(['uses_elevator' => $request->boolean('uses_elevator')]);

        return $request->validate([
            'unit_number' => ['required', 'string', 'max:20'],
            'building_id' => ['nullable', 'exists:buildings,id'],
            'floor' => ['required', 'integer', 'min:-5', 'max:200'],
            'area' => ['required', 'numeric', 'min:0'],
            'residents_count' => ['required', 'integer', 'min:0'],
            'parking_count' => ['nullable', 'integer', 'min:0'],
            'occupancy_status' => ['required', 'in:'.implode(',', array_column(OccupancyStatus::cases(), 'value'))],
            'coefficient' => ['required', 'numeric', 'min:0'],
            'uses_elevator' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
