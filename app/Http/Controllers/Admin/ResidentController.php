<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MessageRestriction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ResidentController extends Controller
{
    public function index()
    {
        $residents = User::where('complex_id', $this->requireComplex()->id)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Tenant->value])
            ->with('currentUnits')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.residents.index', compact('residents'));
    }

    public function create()
    {
        return view('admin.residents.form', [
            'resident' => new User(['role' => UserRole::Tenant]),
            'units' => Unit::orderBy('unit_number')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $resident = User::create([
            'complex_id' => $this->requireComplex()->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
        ]);

        $this->syncUnit($resident, $data);

        return redirect()->route('admin.residents.index')->with('success', 'ساکن جدید ثبت شد.');
    }

    public function edit(User $resident)
    {
        $this->guard($resident);

        return view('admin.residents.form', [
            'resident' => $resident->load('currentUnits'),
            'units' => Unit::orderBy('unit_number')->get(),
        ]);
    }

    public function update(Request $request, User $resident)
    {
        $this->guard($resident);
        $data = $this->validateData($request, $resident->id);

        $resident->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'role' => $data['role'],
        ]);

        if (! empty($data['password'])) {
            $resident->update(['password' => Hash::make($data['password'])]);
        }

        $this->syncUnit($resident, $data);

        return redirect()->route('admin.residents.index')->with('success', 'اطلاعات ساکن به‌روزرسانی شد.');
    }

    public function destroy(User $resident)
    {
        $this->guard($resident);
        $resident->delete();

        return redirect()->route('admin.residents.index')->with('success', 'ساکن حذف شد.');
    }

    public function toggleActive(User $resident)
    {
        $this->guard($resident);
        $resident->update(['is_active' => ! $resident->is_active]);

        return back()->with('success', 'وضعیت دسترسی ساکن تغییر کرد.');
    }

    public function toggleMessage(User $resident)
    {
        $this->guard($resident);
        $resident->update(['can_message' => ! $resident->can_message]);

        if ($resident->can_message) {
            MessageRestriction::where('user_id', $resident->id)->delete();
        } else {
            MessageRestriction::updateOrCreate(
                ['complex_id' => $resident->complex_id, 'user_id' => $resident->id],
                ['created_by' => Auth::id(), 'reason' => 'محدودیت توسط مدیر']
            );
        }

        return back()->with('success', 'وضعیت پیام‌رسانی ساکن تغییر کرد.');
    }

    protected function syncUnit(User $resident, array $data): void
    {
        if (empty($data['unit_id'])) {
            return;
        }

        // End any previous current relation for this user, preserving history.
        foreach ($resident->currentUnits()->pluck('units.id') as $previousUnitId) {
            $resident->units()->updateExistingPivot($previousUnitId, ['is_current' => false, 'end_date' => now()]);
        }

        $relation = $data['role'] === UserRole::Owner->value
            ? ResidentRelation::Owner->value
            : ResidentRelation::Tenant->value;

        $resident->units()->syncWithoutDetaching([
            $data['unit_id'] => [
                'complex_id' => $resident->complex_id,
                'relation' => $relation,
                'is_current' => true,
                'start_date' => now(),
                'end_date' => null,
            ],
        ]);
    }

    protected function validateData(Request $request, ?int $ignoreId = null): array
    {
        // Phone is the login identifier: normalise then validate uniqueness/format.
        $request->merge(['phone' => \App\Support\Phone::normalize($request->input('phone'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')->ignore($ignoreId)],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($ignoreId)],
            'national_id' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:owner,tenant'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'password' => [$ignoreId ? 'nullable' : 'required', 'nullable', 'string', 'min:6'],
        ], [
            'phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'phone.unique' => 'این شماره تلفن قبلا ثبت شده است.',
        ], [
            'name' => 'نام', 'email' => 'ایمیل', 'phone' => 'شماره تلفن', 'role' => 'نقش', 'password' => 'رمز عبور',
        ]);
    }

    /** Block acting on users outside the current complex. */
    protected function guard(User $resident): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin()) {
            abort_unless($resident->complex_id === $user->complex_id, 403);
        }
    }
}
