<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * واحدِ مسکونی.
 *
 * از `present()` در `Api/UnitController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Unit $resource
 */
class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->resource;

        return [
            'id' => $unit->id,
            'unitNumber' => $unit->unit_number,
            'buildingId' => $unit->building_id,
            'buildingName' => $unit->building?->name,
            'floor' => (int) $unit->floor,
            'area' => (float) $unit->area,
            'residentsCount' => (int) $unit->residents_count,
            'parkingCount' => (int) $unit->parking_count,
            'storageCount' => (int) $unit->storage_count,
            'occupancyStatus' => $unit->occupancy_status->value,
            'occupancyLabel' => $unit->occupancy_status->label(),
            'coefficient' => (float) $unit->coefficient,
            'usesElevator' => (bool) $unit->uses_elevator,
            'balance' => (float) $unit->balance,
            'notes' => $unit->notes,
        ];
    }
}
