<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ManagerController extends Controller
{
    public function index()
    {
        $complex = $this->requireComplex();

        return view('admin.managers.index', [
            'managers' => User::where('complex_id', $complex->id)
                ->where('role', UserRole::ComplexAdmin->value)
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $complex = $this->requireComplex();
        $request->merge(['phone' => Phone::normalize($request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:6'],
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
        ]);

        return back()->with('success', 'مدیر جدید برای مجتمع اضافه شد.');
    }

    public function destroy(User $manager)
    {
        $this->guard($manager);

        // Keep at least one manager in the complex.
        $count = User::where('complex_id', $manager->complex_id)->where('role', UserRole::ComplexAdmin->value)->count();
        if ($count <= 1) {
            return back()->with('error', 'حداقل یک مدیر باید برای مجتمع باقی بماند.');
        }

        $manager->delete();

        return back()->with('success', 'مدیر حذف شد.');
    }

    protected function guard(User $manager): void
    {
        abort_unless($manager->role === UserRole::ComplexAdmin, 403);
        $user = Auth::user();
        if (! $user->isSuperAdmin()) {
            abort_unless($manager->complex_id === $user->complex_id, 403);
        }
    }
}
