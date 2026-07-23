<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ManagerController extends Controller
{
    public function index(): JsonResponse
    {
        $complex = $this->requireComplex();

        $managers = User::where('complex_id', $complex->id)
            ->where('role', UserRole::ComplexAdmin->value)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $managers->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'isActive' => (bool) $m->is_active,
                'isSelf' => $m->id === Auth::id(),
                'createdAt' => Jalali::date($m->created_at),
            ])->values(),
            'complexName' => $complex->name,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $request->merge(['phone' => Phone::normalize($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')],
            // مدیر مجتمع دسترسی مالی کامل دارد؛ رمزش نباید ضعیف‌تر از رمز پروفایل باشد.
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ], [
            'phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'phone.unique' => 'این شماره قبلا ثبت شده است.',
        ], ['name' => 'نام', 'phone' => 'شماره تلفن', 'password' => 'رمز عبور']);

        User::create([
            'complex_id' => $complex->id,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        return response()->json(['message' => 'مدیر جدید برای مجتمع اضافه شد.'], 201);
    }

    public function destroy(User $manager): JsonResponse
    {
        $this->guard($manager);

        // مجتمع نباید بدون مدیر بماند، وگرنه دیگر کسی به تنظیماتش دسترسی ندارد.
        $count = User::where('complex_id', $manager->complex_id)
            ->where('role', UserRole::ComplexAdmin->value)
            ->count();

        if ($count <= 1) {
            return response()->json([
                'message' => 'حداقل یک مدیر باید برای مجتمع باقی بماند.',
            ], 422);
        }

        if ($manager->id === Auth::id()) {
            return response()->json([
                'message' => 'نمی‌توانید حساب خودتان را حذف کنید.',
            ], 422);
        }

        Audit::log('manager.deleted', 'حذف مدیر مجتمع', $manager, [
            'name' => $manager->name,
            'phone' => $manager->phone,
        ]);

        $manager->delete();

        return response()->json(['message' => 'مدیر حذف شد.']);
    }

    private function guard(User $manager): void
    {
        abort_unless($manager->role === UserRole::ComplexAdmin, 403);

        $user = Auth::user();
        if (! $user->isSuperAdmin()) {
            abort_unless($manager->complex_id === $user->complex_id, 403);
        }
    }
}
