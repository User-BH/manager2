<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * «پروفایل من».
 *
 * همه چیز اینجا درباره‌ی خودِ کاربر واردشده است — هیچ پارامتر شناسه‌ای
 * نمی‌گیرد تا کسی نتواند با عوض کردن id پروفایل دیگری را ببیند.
 */
class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'profile' => $this->present($user),
            'units' => $this->units($user),
            'people' => $this->people($user),
            'complexes' => $this->complexes($user),
            'stats' => $this->stats($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = Auth::user();

        // ارقام فارسیِ کد ملی و شماره‌ی اضطراری قبل از regex به لاتین تبدیل
        // می‌شوند تا درخواستِ مستقیمِ API هم مثل کلاینت پاک باشد.
        $request->merge([
            'national_id' => $this->latinDigits($request->input('national_id')),
            'emergency_phone' => $this->latinDigits($request->input('emergency_phone')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[\p{L}\s\x{200c}\'\-]+$/u'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            // کد ملی: دقیقاً ۱۰ رقم (رقم کنترلی سمت کلاینت بررسی می‌شود)
            'national_id' => ['nullable', 'regex:/^\d{10}$/'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            // موبایل یا ثابت: ۱۱ رقم با شروع ۰
            'emergency_phone' => ['nullable', 'regex:/^0\d{10}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:500'],
        ], [
            'name.regex' => 'نام فقط می‌تواند شامل حروف باشد.',
            'national_id.regex' => 'کد ملی باید ۱۰ رقم باشد.',
            'emergency_phone.regex' => 'شماره تماس معتبر نیست.',
            'birth_date.before_or_equal' => 'تاریخ تولد نمی‌تواند در آینده باشد.',
        ], [
            'name' => 'نام',
            'email' => 'ایمیل',
            'national_id' => 'کد ملی',
            'birth_date' => 'تاریخ تولد',
            'emergency_phone' => 'شماره اضطراری',
            'address' => 'نشانی',
            'bio' => 'درباره من',
        ]);

        // شماره‌ی تلفن عمداً اینجا قابل تغییر نیست: کلید ورود به سامانه است و
        // عوض کردنش باید با تایید پیامکی انجام شود، نه با یک فرم ساده.
        if ($data['emergency_phone'] ?? null) {
            $data['emergency_phone'] = Phone::normalize($data['emergency_phone']);
        }

        $user->update($data);

        return response()->json([
            'profile' => $this->present($user->fresh()),
            'message' => 'اطلاعات پروفایل ذخیره شد.',
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            // رمز قوی: حداقل ۸ نویسه، دست‌کم یک حرف و یک رقم — همان قاعده‌ی کلاینت
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'password.different' => 'رمز جدید باید با رمز فعلی متفاوت باشد.',
        ], [
            'current_password' => 'رمز عبور فعلی',
            'password' => 'رمز عبور جدید',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'رمز عبور فعلی نادرست است.',
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'رمز عبور تغییر کرد.']);
    }

    /** ارقام فارسی/عربی را به لاتین برمی‌گرداند (یا null اگر ورودی خالی باشد). */
    private function latinDigits(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $from = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $to = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($from, $to, $value);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'nationalId' => $user->national_id,
            'birthDate' => $user->birth_date?->toDateString(),
            'birthDateLabel' => $user->birth_date ? Jalali::date($user->birth_date) : null,
            'emergencyPhone' => $user->emergency_phone,
            'address' => $user->address,
            'bio' => $user->bio,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isAdmin' => $user->isAdmin(),
            'isActive' => (bool) $user->is_active,
            'canMessage' => (bool) $user->can_message,
            'joinedAt' => Jalali::date($user->created_at),
            'complex' => $user->complex ? ['id' => $user->complex->id, 'name' => $user->complex->name] : null,
        ];
    }

    /** واحدهایی که کاربر مالک یا مستاجرشان است. */
    private function units(User $user): array
    {
        return $user->units()->with('building')->get()->map(fn (Unit $unit) => [
            'id' => $unit->id,
            'label' => 'واحد '.$unit->unit_number,
            'buildingName' => $unit->building?->name,
            'floor' => (int) $unit->floor,
            'area' => (float) $unit->area,
            'relation' => $unit->pivot->relation,
            'relationLabel' => $unit->pivot->relation === 'owner' ? 'مالک' : 'مستاجر',
            'sharePercent' => (float) $unit->pivot->share_percent,
            'isCurrent' => (bool) $unit->pivot->is_current,
            'balance' => (float) $unit->balance,
            'startDate' => $unit->pivot->start_date ? Jalali::date($unit->pivot->start_date) : null,
            'endDate' => $unit->pivot->end_date ? Jalali::date($unit->pivot->end_date) : null,
        ])->all();
    }

    /**
     * افراد وابسته.
     *
     * برای ساکن: هم‌واحدی‌ها. برای مدیر: مدیران دیگر همان مجتمع. در هر دو
     * حالت فقط کسانی که کاربر از قبل حق دیدنشان را دارد.
     */
    private function people(User $user): array
    {
        if ($user->isAdmin()) {
            $complex = $this->currentComplex();
            if (! $complex) {
                return [];
            }

            return User::where('complex_id', $complex->id)
                ->where('id', '!=', $user->id)
                ->whereIn('role', [UserRole::SuperAdmin->value, UserRole::ComplexAdmin->value])
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'phone' => $u->phone,
                    'roleLabel' => $u->role->label(),
                    'relationLabel' => 'هم‌تیمی مدیریت',
                    'isActive' => (bool) $u->is_active,
                ])->all();
        }

        $unitIds = $user->currentUnits()->pluck('units.id');
        if ($unitIds->isEmpty()) {
            return [];
        }

        return User::whereHas('units', fn ($q) => $q->whereIn('units.id', $unitIds)->wherePivot('is_current', true))
            ->where('id', '!=', $user->id)
            ->with('currentUnits')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'roleLabel' => $u->role->label(),
                'relationLabel' => 'هم‌واحدی',
                'isActive' => (bool) $u->is_active,
            ])->all();
    }

    /** مجتمع‌های وابسته: ادمین کل همه را می‌بیند، بقیه فقط مجتمع خودشان را. */
    private function complexes(User $user): array
    {
        $activeId = $user->isSuperAdmin() ? session('active_complex_id') : $user->complex_id;

        $query = $user->isSuperAdmin()
            ? Complex::orderBy('name')
            : Complex::whereKey($user->complex_id);

        return $query->withCount(['units', 'users'])->get()->map(fn (Complex $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'address' => $c->address,
            'phone' => $c->phone,
            'unitsCount' => $c->units_count,
            'usersCount' => $c->users_count,
            'isActive' => (bool) $c->is_active,
            'isCurrent' => (int) $activeId === $c->id,
        ])->all();
    }

    private function stats(User $user): array
    {
        $unitIds = $user->currentUnits()->pluck('units.id');

        if ($unitIds->isEmpty()) {
            return ['unitsCount' => 0, 'billsCount' => 0, 'paidCount' => 0, 'totalDebt' => 0.0];
        }

        $bills = Bill::whereIn('unit_id', $unitIds);

        return [
            'unitsCount' => $unitIds->count(),
            'billsCount' => (clone $bills)->count(),
            'paidCount' => Payment::where('user_id', $user->id)->where('status', 'success')->count(),
            'totalDebt' => (float) ((clone $bills)->sum('total_amount') - (clone $bills)->sum('paid_amount')),
        ];
    }
}
