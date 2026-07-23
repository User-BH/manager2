<?php

namespace App\Http\Controllers\Api;

use App\Enums\OccupancyStatus;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Unit;
use App\Services\Subscription\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireComplex();

        $units = Unit::with('building')
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->where('unit_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%"));
            })
            ->when($request->string('occupancy')->value(), fn ($q, $s) => $q->where('occupancy_status', $s))
            ->orderBy('unit_number')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => collect($units->items())->map(fn (Unit $u) => $this->present($u))->values(),
            'meta' => [
                'currentPage' => $units->currentPage(),
                'lastPage' => $units->lastPage(),
                'perPage' => $units->perPage(),
                'total' => $units->total(),
            ],
            'filters' => [
                'buildings' => Building::orderBy('name')->get(['id', 'name']),
                'occupancyOptions' => collect(OccupancyStatus::cases())
                    ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
            ],
        ]);
    }

    public function store(Request $request, PlanGate $plans): JsonResponse
    {
        $complex = $this->requireComplex();

        // سقف تعداد واحد پلن رایگان — پیش از اعتبارسنجی تا کاربر بی‌دلیل
        // فرم را پر نکند و بعد رد شود.
        $plans->assertCanAddUnit($complex);

        $unit = Unit::create($this->validateData($request));

        return response()->json(['unit' => $this->present($unit->load('building'))], 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $unit->update($this->validateData($request));

        return response()->json(['unit' => $this->present($unit->fresh('building'))]);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $unit->delete();

        return response()->json(['message' => 'واحد حذف شد.']);
    }

    private function present(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'unitNumber' => $unit->unit_number,
            'buildingId' => $unit->building_id,
            'buildingName' => $unit->building?->name,
            'floor' => (int) $unit->floor,
            'area' => (float) $unit->area,
            'residentsCount' => (int) $unit->residents_count,
            'parkingCount' => (int) $unit->parking_count,
            'occupancyStatus' => $unit->occupancy_status->value,
            'occupancyLabel' => $unit->occupancy_status->label(),
            'coefficient' => (float) $unit->coefficient,
            'usesElevator' => (bool) $unit->uses_elevator,
            'balance' => (float) $unit->balance,
            'notes' => $unit->notes,
        ];
    }

    /** همان قواعد اعتبارسنجی کنترلر وب، تا رفتار دو مسیر یکی بماند. */
    private function validateData(Request $request): array
    {
        $request->merge(['uses_elevator' => $request->boolean('uses_elevator')]);

        return $request->validate([
            'unit_number' => ['required', 'string', 'max:20'],
            // exists خام به مجتمع محدود نیست؛ بدون این قید می‌شد واحد را به
            // ساختمانِ مجتمع دیگری چسباند.
            'building_id' => [
                'nullable',
                Rule::exists('buildings', 'id')->where('complex_id', $this->requireComplex()->id),
            ],
            'floor' => ['required', 'integer', 'min:-5', 'max:200'],
            'area' => ['required', 'numeric', 'min:0'],
            'residents_count' => ['required', 'integer', 'min:0'],
            'parking_count' => ['nullable', 'integer', 'min:0'],
            'occupancy_status' => ['required', 'in:'.implode(',', array_column(OccupancyStatus::cases(), 'value'))],
            'coefficient' => ['required', 'numeric', 'min:0'],
            'uses_elevator' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'unit_number' => 'شماره واحد',
            'floor' => 'طبقه',
            'area' => 'متراژ',
            'residents_count' => 'تعداد ساکنین',
            'occupancy_status' => 'وضعیت سکونت',
            'coefficient' => 'ضریب',
        ]);
    }
}
