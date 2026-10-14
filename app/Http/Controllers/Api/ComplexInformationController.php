<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateComplexInformationRequest;
use App\Models\Building;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * «اطلاعاتِ مجتمع» برای مدیر (مرحلهٔ ۱۰ · فاز ۷؛ بندهای ۷ و ۱۸ ِ فایلِ معماری).
 *
 * دو نیمه دارد و این تفکیک عمدی است (بندِ ۲ — یک منبعِ حقیقت):
 *   • آنچه مدیر می‌نویسد — هویت، ساختار و بخش‌های مشترک، امکانات، مدیریت — روی خودِ مجتمع؛
 *   • آنچه از داده بیرون می‌آید — ساختمان، بلوک، طبقه، واحد، فضای تجاری، مالک، مستأجر، خالی، فعال، مدیران — هر
 *     بار حساب می‌شود و هرگز ذخیره نمی‌شود، تا هیچ‌وقت با واقعیت ناهمخوان نشود.
 */
class ComplexInformationController extends Controller
{
    /** امکاناتِ ۷.۴ — کلیدِ ثابت ← برچسب */
    public const FACILITIES = [
        'elevator' => 'آسانسور',
        'parking' => 'پارکینگ',
        'storage' => 'انباری',
        'lobby' => 'لابی',
        'guard' => 'نگهبانی',
        'hall' => 'سالن',
        'pool' => 'استخر',
        'gym' => 'باشگاه',
        'green_space' => 'فضای سبز',
        'access_control' => 'سیستمِ کنترلِ ورود',
    ];

    /** نوع‌هایی از واحد که «فضای تجاری» شمرده می‌شوند (۷.۲) */
    private const COMMERCIAL_UNIT_TYPES = ['commercial', 'shop', 'office'];

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->complex()));
    }

    public function update(UpdateComplexInformationRequest $request): JsonResponse
    {
        $complex = $this->complex();
        $data = $request->validated();
        $first = $complex->information_completed_at === null;

        $complex->forceFill([
            ...$data,
            'facilities' => array_values($data['facilities'] ?? []),
            'information_completed_at' => $complex->information_completed_at ?? now(),
        ])->save();

        Audit::log('complex.information_updated', 'به‌روزرسانیِ اطلاعاتِ مجتمع', $complex, ['first' => $first]);

        return response()->json([
            ...$this->payload($complex->fresh() ?? $complex),
            'message' => 'اطلاعاتِ مجتمع ذخیره شد.',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Complex $complex): array
    {
        return [
            'information' => [
                'name' => $complex->name,
                'type' => $complex->type->value,
                'typeLabel' => $complex->type->label(),
                'brandName' => $complex->brand_name,
                'address' => $complex->address,
                'province' => $complex->province,
                'city' => $complex->city,
                'postalCode' => $complex->postal_code,
                'latitude' => $complex->latitude !== null ? (float) $complex->latitude : null,
                'longitude' => $complex->longitude !== null ? (float) $complex->longitude : null,
                'description' => $complex->description,
                'structure' => $complex->structure,
                'commonAreas' => $complex->common_areas,
                'facilities' => $complex->facilities ?? [],
                'facilitiesOther' => $complex->facilities_other,
                'phone' => $complex->phone,
                'board' => $complex->board,
                'responseHours' => $complex->response_hours,
                'emergencyInfo' => $complex->emergency_info,
                'completedAt' => $complex->information_completed_at?->toIso8601String(),
            ],
            'derived' => $this->derived($complex),
            'facilityOptions' => collect(self::FACILITIES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
        ];
    }

    /**
     * شمارش‌های ۷.۲، ۷.۳ و ۷.۵ — از خودِ داده.
     *
     * @return array<string, mixed>
     */
    private function derived(Complex $complex): array
    {
        // دامنه‌ی مستأجر همین مجتمع را می‌دهد؛ شرطِ صریح فقط برای خواننده‌ی کد
        $units = Unit::query()->where('complex_id', $complex->id);
        $current = DB::table('unit_user')->where('complex_id', $complex->id)->where('is_current', true);

        $unitsCount = (clone $units)->count();
        $occupied = (clone $current)->distinct()->count('unit_id');

        return [
            'buildings' => Building::where('complex_id', $complex->id)->count(),
            'blocks' => (clone $units)->whereNotNull('block')->distinct()->count('block'),
            'floors' => (int) (Building::where('complex_id', $complex->id)->max('floors_count') ?? (clone $units)->max('floor') ?? 0),
            'units' => $unitsCount,
            'commercialSpaces' => (clone $units)->whereIn('unit_type', self::COMMERCIAL_UNIT_TYPES)->count(),
            'owners' => (clone $current)->where('relation', 'owner')->distinct()->count('user_id'),
            'tenants' => (clone $current)->where('relation', 'tenant')->distinct()->count('user_id'),
            'occupiedUnits' => $occupied,
            'vacantUnits' => max(0, $unitsCount - $occupied),
            'activeUnits' => (clone $units)->where('is_active', true)->count(),
            'managers' => ComplexMembers::query($complex->id, [UserRole::ComplexAdmin])->orderBy('users.name')
                ->get()->map(fn (User $u) => $u->name)->values()->all(),
        ];
    }

    /**
     * ردیفِ تازه‌ی مجتمعِ فعال — همان دلیلِ `JoinLinkController::complex`: نمونه‌ی نگه‌داشته روی کنترلر در فرایندِ
     * ماندگار کهنه می‌شود.
     */
    private function complex(): Complex
    {
        return Complex::query()->findOrFail($this->requireComplex()->id);
    }
}
