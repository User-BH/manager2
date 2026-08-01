<?php

namespace App\Http\Controllers\Api;

use App\Enums\OccupancyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Building;
use App\Models\Unit;
use App\Services\Subscription\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(StoreUnitRequest $request, PlanGate $plans): JsonResponse
    {
        $complex = $this->requireComplex();

        // سقف تعداد واحد پلن رایگان — پیش از اعتبارسنجی تا کاربر بی‌دلیل
        // فرم را پر نکند و بعد رد شود.
        $plans->assertCanAddUnit($complex);

        $unit = Unit::create($request->validated());

        return response()->json(['unit' => $this->present($unit->load('building'))], 201);
    }

    public function update(StoreUnitRequest $request, Unit $unit): JsonResponse
    {
        $unit->update($request->validated());

        return response()->json(['unit' => $this->present($unit->fresh('building'))]);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        // حذف واحد آبشاری است و قبوض و پرداخت‌هایش را هم می‌برد؛ ردش باید
        // بماند — که `AuditObserver` خودکار انجامش می‌دهد.
        $unit->delete();

        return response()->json(['message' => 'واحد حذف شد.']);
    }

    /**
     * شکلِ خروجی حالا در `UnitResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Unit $unit): array
    {
        return (new UnitResource($unit))->toArray(request());
    }
}
