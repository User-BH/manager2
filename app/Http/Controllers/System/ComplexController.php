<?php

namespace App\Http\Controllers\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ComplexController extends Controller
{
    public function index()
    {
        $complexes = Complex::withCount(['units', 'users'])->latest()->get();

        return view('system.complexes.index', [
            'complexes' => $complexes,
            'activeId' => session('active_complex_id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:6'],
        ], [], [
            'name' => 'نام مجتمع', 'admin_name' => 'نام مدیر',
            'admin_email' => 'ایمیل مدیر', 'admin_password' => 'رمز عبور مدیر',
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
            'email' => $data['admin_email'],
            'password' => Hash::make($data['admin_password']),
            'role' => UserRole::ComplexAdmin,
        ]);

        return back()->with('success', 'مجتمع جدید به همراه حساب مدیر ایجاد شد.');
    }

    public function select(Complex $complex)
    {
        session(['active_complex_id' => $complex->id]);

        return redirect()->route('dashboard')->with('success', 'مجتمع «'.$complex->name.'» انتخاب شد.');
    }

    public function clear()
    {
        session()->forget('active_complex_id');

        return redirect()->route('dashboard')->with('success', 'حالت مدیریت مجتمع خارج شد.');
    }
}
