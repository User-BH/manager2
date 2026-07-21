<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ComplexController extends Controller
{
    public function index(): JsonResponse
    {
        $complexes = Complex::withCount(['units', 'users'])->latest()->get();

        return response()->json([
            'data' => $complexes->map(fn (Complex $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'address' => $c->address,
                'units' => (int) $c->units_count,
                'users' => (int) $c->users_count,
                'isActive' => session('active_complex_id') == $c->id,
            ])->values(),
            'activeId' => session('active_complex_id'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['admin_phone' => Phone::normalize($request->input('admin_phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:120'],
            // ورود سامانه با شماره تلفن است، پس مدیر مجتمع حتماً باید شماره
            // داشته باشد؛ نسخه‌ی قبلی فقط ایمیل می‌گرفت و حساب ساخته‌شده
            // عملاً قابل ورود نبود.
            'admin_phone' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'phone')],
            'admin_email' => ['nullable', 'email', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', 'min:6'],
        ], [
            'admin_phone.regex' => 'شماره تلفن همراه باید به شکل ۰۹xxxxxxxxx باشد.',
            'admin_phone.unique' => 'این شماره تلفن قبلا ثبت شده است.',
        ], [
            'name' => 'نام مجتمع',
            'admin_name' => 'نام مدیر',
            'admin_phone' => 'شماره مدیر',
            'admin_email' => 'ایمیل مدیر',
            'admin_password' => 'رمز عبور مدیر',
        ]);

        $complex = Complex::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::random(4),
            'address' => $data['address'] ?? null,
            'payment_gateway' => 'none',
        ]);

        User::create([
            'complex_id' => $complex->id,
            'name' => $data['admin_name'],
            'phone' => $data['admin_phone'],
            'email' => $data['admin_email'] ?? null,
            'password' => Hash::make($data['admin_password']),
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'مجتمع جدید به همراه حساب مدیر ایجاد شد.',
        ], 201);
    }

    /** ورود ادمین کل به اسکوپ یک مجتمع. */
    public function select(Complex $complex): JsonResponse
    {
        session(['active_complex_id' => $complex->id]);

        return response()->json([
            'message' => 'مجتمع «'.$complex->name.'» انتخاب شد.',
            'activeId' => $complex->id,
        ]);
    }

    public function clear(): JsonResponse
    {
        session()->forget('active_complex_id');

        return response()->json(['message' => 'از حالت مدیریت مجتمع خارج شدید.', 'activeId' => null]);
    }
}
