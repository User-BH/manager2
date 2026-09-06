<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreComplexRequest;
use App\Http\Requests\SuspendComplexRequest;
use App\Http\Requests\UpdateComplexTypeRequest;
use App\Models\Complex;
use App\Models\User;
use App\Services\Subscription\PlanGate;
use App\Support\Audit;
use App\Support\Jalali;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ComplexController extends Controller
{
    public function __construct(private readonly PlanGate $plans) {}

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
                'type' => $c->type->value,
                'typeLabel' => $c->type->label(),
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
            // نوع (R67) — تعرفه‌ی پکیج‌های اشتراک از روی همین تعیین می‌شود
            'type' => ComplexType::from($data['type']),
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

    /**
     * تغییرِ نوعِ مجتمع — فقط ادمینِ کل (R67).
     *
     * ─── چرا مدیرِ مجتمع نمی‌تواند ──────────────────────────────────────────
     * 🔴 تعرفه‌ی پکیج‌های اشتراک به نوع گره خورده است. اگر مدیر می‌توانست
     * نوعش را عوض کند، با یک سوییچ تعرفه‌ی ارزان‌تر را می‌گرفت و بعد
     * برمی‌گرداند. این یک تنظیمِ ظاهری نیست؛ ورودیِ صورت‌حساب است.
     *
     * ⚠️ و با اشتراکِ فعال اصلاً عوض نمی‌شود: پکیجی که خریده شده مالِ نوعِ
     * قبلی است و پس از تغییر، مجتمع اشتراکی دارد که در فهرستِ خودش وجود
     * ندارد — حالتی که هیچ صفحه‌ای بلد نیست نشانش بدهد.
     */
    public function retype(UpdateComplexTypeRequest $request, Complex $complex): JsonResponse
    {
        $type = ComplexType::from($request->validated()['type']);

        if ($type === $complex->type) {
            return response()->json(['message' => 'نوعِ مجتمع همین است.']);
        }

        if ($this->plans->activeSubscription($complex) !== null) {
            throw DomainException::invalid(
                'تا پایانِ اشتراکِ فعالِ این مجتمع، نوعش عوض نمی‌شود.',
                'complex.type_locked_by_subscription',
            );
        }

        $complex->update(['type' => $type]);

        Audit::log('complex.retyped', 'نوعِ مجتمع به «'.$type->label().'» تغییر کرد.', $complex);

        return response()->json([
            'message' => 'نوعِ مجتمع به «'.$type->label().'» تغییر کرد.',
            'type' => $type->value,
        ]);
    }

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
