<?php

namespace App\Http\Controllers\Api;

use App\Enums\OccupancyStatus;
use App\Enums\ResidentRelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUnitInformationRequest;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «اطلاعاتِ واحد» (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۹ ِ فایلِ معماری).
 *
 * چه کسی: مدیرِ همان مجتمع، یا مالک/مستأجرِ **فعلیِ** همان واحد. ساکنِ واحدِ دیگر یا ساکنِ قبلی نه — همان ۴۰۴ ِ
 * «نیست» (اتصالِ مدلِ مسیر هم واحد را به مجتمعِ فعال محدود می‌کند).
 *
 * چه چیزی: مشخصاتِ توصیفی نوشتنی‌اند (`UpdateUnitInformationRequest`)؛ مشخصاتِ شارژ و نفراتِ واحد فقط خوانده
 * می‌شوند — شارژی‌ها از فرمِ مدیر، مالک و مستأجر و اطلاعاتِ تماسشان از دوره‌های سکونت و حساب‌ها (بندِ ۲: هیچ‌کدام
 * کپی نمی‌شود).
 */
class UnitInformationController extends Controller
{
    public const UNIT_TYPES = [
        'residential' => 'مسکونی',
        'commercial' => 'تجاری',
        'office' => 'اداری',
        'shop' => 'مغازه',
        'other' => 'سایر',
    ];

    public const ORIENTATIONS = [
        'north' => 'شمالی', 'south' => 'جنوبی', 'east' => 'شرقی', 'west' => 'غربی',
        'northeast' => 'شمال‌شرقی', 'northwest' => 'شمال‌غربی', 'southeast' => 'جنوب‌شرقی', 'southwest' => 'جنوب‌غربی',
    ];

    public function show(Unit $unit): JsonResponse
    {
        $this->authorizeFor($unit);

        return response()->json($this->payload($unit));
    }

    public function update(UpdateUnitInformationRequest $request, Unit $unit): JsonResponse
    {
        $this->authorizeFor($unit);
        $data = $request->validated();
        $first = $unit->information_completed_at === null;

        $unit->forceFill([
            ...$data,
            'meters' => array_filter($data['meters'] ?? [], fn ($v) => $v !== null && $v !== '') ?: null,
            'information_completed_at' => $unit->information_completed_at ?? now(),
        ])->save();

        Audit::log('unit.information_updated', 'به‌روزرسانیِ اطلاعاتِ واحد', $unit, [
            'unit_number' => $unit->unit_number,
            'first' => $first,
        ]);

        return response()->json([...$this->payload($unit->fresh() ?? $unit), 'message' => 'اطلاعاتِ واحد ذخیره شد.']);
    }

    /** @return array<string, mixed> */
    private function payload(Unit $unit): array
    {
        $unit->loadMissing('building:id,name');

        return [
            'information' => [
                'unitType' => $unit->unit_type,
                'block' => $unit->block,
                'roomsCount' => $unit->rooms_count,
                'bedroomsCount' => $unit->bedrooms_count,
                'bathroomsCount' => $unit->bathrooms_count,
                'parkingNumbers' => $unit->parking_numbers,
                'storageNumbers' => $unit->storage_numbers,
                'hasTerrace' => $unit->has_terrace,
                'hasBalcony' => $unit->has_balcony,
                'orientation' => $unit->orientation,
                'meters' => (object) ($unit->meters ?? []),
                'description' => $unit->description,
                'completedAt' => $unit->information_completed_at?->toIso8601String(),
            ],
            // از فرمِ مدیر — این‌جا فقط خواندنی
            'managed' => [
                'unitNumber' => $unit->unit_number,
                'floor' => (int) $unit->floor,
                'building' => $unit->building?->name,
                'area' => (float) $unit->area,
                'parkingCount' => (int) $unit->parking_count,
                'storageCount' => (int) $unit->storage_count,
                'occupancyLabel' => $unit->occupancy_status instanceof OccupancyStatus ? $unit->occupancy_status->label() : null,
            ],
            // از دوره‌های سکونتِ فعلی و حساب‌ها — نه کپی
            'people' => DB::table('unit_user')
                ->join('users', 'users.id', '=', 'unit_user.user_id')
                ->where('unit_user.unit_id', $unit->id)
                ->where('unit_user.is_current', true)
                ->orderBy('unit_user.relation')
                ->get(['users.name', 'users.phone', 'unit_user.relation'])
                ->map(fn (object $p) => [
                    'name' => $p->name,
                    'phone' => $p->phone,
                    'relationLabel' => ResidentRelation::from($p->relation)->label(),
                ])->values(),
            'unitTypeOptions' => $this->options(self::UNIT_TYPES),
            'orientationOptions' => $this->options(self::ORIENTATIONS),
        ];
    }

    private function authorizeFor(Unit $unit): void
    {
        abort_unless(self::mayAccess(Auth::user(), $unit), 404);
    }

    /** مدیر (در زمینه‌ی همان مجتمع؛ اتصالِ مدل این را تضمین می‌کند)، یا مالک/مستأجرِ فعلیِ همین واحد */
    public static function mayAccess(?User $user, Unit $unit): bool
    {
        return $user !== null && ($user->isAdmin() || DB::table('unit_user')
            ->where('unit_id', $unit->id)
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->exists());
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    private function options(array $map): array
    {
        return array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($map), $map);
    }
}
