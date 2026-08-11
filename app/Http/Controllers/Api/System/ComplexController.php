<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreComplexRequest;
use App\Http\Requests\SuspendComplexRequest;
use App\Models\Complex;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ComplexController extends Controller
{
    public function index(): JsonResponse
    {
        /*
         * تنها فهرستی که با رشدِ کسب‌وکار بی‌کران می‌شود.
         *
         * بقیه‌ی فهرست‌های بدونِ صفحه‌بندی ذاتاً محدودند (یک دوره، یک مجتمع)،
         * ولی تعدادِ مجتمع‌های پلتفرم فقط زیاد می‌شود. بدونِ صفحه‌بندی، این
         * صفحه روزی همه‌ی رکوردها را در یک پاسخ می‌ریخت.
         */
        $complexes = Complex::withCount(['units', 'users'])->latest()->paginate(20);

        return response()->json([
            'meta' => [
                'currentPage' => $complexes->currentPage(),
                'lastPage' => $complexes->lastPage(),
                'total' => $complexes->total(),
            ],
            'data' => $complexes->getCollection()->map(fn (Complex $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'address' => $c->address,
                'units' => (int) $c->units_count,
                'users' => (int) $c->users_count,
                'isActive' => session('active_complex_id') == $c->id,
                // وضعیتِ تعلیق (R29) — جدا از «مجتمعِ انتخاب‌شده»
                'isSuspended' => ! $c->is_active,
                'suspendedAt' => $c->suspended_at ? Jalali::dateTime($c->suspended_at) : null,
                'suspensionReason' => $c->suspension_reason,
            ])->values(),
            'activeId' => session('active_complex_id'),
        ]);
    }

    public function store(StoreComplexRequest $request): JsonResponse
    {
        $request->merge(['admin_phone' => Phone::normalize($request->input('admin_phone'))]);

        $data = $request->validated();

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
    /**
     * تعلیق یا بازفعال‌سازیِ یک مجتمع (R29).
     *
     * ─── چرا این تا امروز نبود ─────────────────────────────────────────────
     * ستونِ `is_active` از اولین مهاجرت وجود داشت ولی نه راهی برای
     * تغییرش بود و نه هیچ‌جا خوانده می‌شد. یعنی «مدیریتِ کنترل‌شده‌ی
     * Tenantها» عملاً فقط ساختنِ مجتمع بود، بدونِ هیچ اهرمی.
     *
     * دلیل **اجباری** است: ساکنی که فردا با پشتیبانی تماس می‌گیرد باید
     * جوابی بگیرد، و آن جواب نباید حافظه‌ی کسی باشد که دکمه را زده.
     */
    public function suspend(SuspendComplexRequest $request, Complex $complex): JsonResponse
    {
        $complex->update([
            'is_active' => false,
            'suspended_at' => now(),
            'suspension_reason' => $request->validated()['reason'],
        ]);

        return response()->json([
            'message' => 'دسترسی این مجتمع تعلیق شد.',
            'complex' => $this->presentOne($complex->refresh()),
        ]);
    }

    public function activate(Complex $complex): JsonResponse
    {
        $complex->update([
            'is_active' => true,
            // دلیل هم پاک می‌شود، وگرنه در تعلیقِ بعدی متنِ کهنه نشان داده می‌شد
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        return response()->json([
            'message' => 'دسترسی این مجتمع بازگردانده شد.',
            'complex' => $this->presentOne($complex->refresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentOne(Complex $complex): array
    {
        return [
            'id' => $complex->id,
            'name' => $complex->name,
            'isSuspended' => ! $complex->is_active,
            'suspendedAt' => $complex->suspended_at ? Jalali::dateTime($complex->suspended_at) : null,
            'suspensionReason' => $complex->suspension_reason,
        ];
    }

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
