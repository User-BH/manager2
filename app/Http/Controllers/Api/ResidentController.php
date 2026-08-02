<?php

namespace App\Http\Controllers\Api;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Resources\ResidentResource;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ResidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $complexId = $this->requireComplex()->id;

        $residents = User::where('complex_id', $complexId)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Tenant->value])
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->when($request->string('role')->value(), fn ($q, $role) => $q->where('role', $role))
            ->with('currentUnits')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => collect($residents->items())->map(fn (User $u) => $this->present($u))->values(),
            'meta' => [
                'currentPage' => $residents->currentPage(),
                'lastPage' => $residents->lastPage(),
                'perPage' => $residents->perPage(),
                'total' => $residents->total(),
            ],
            'filters' => [
                'units' => Unit::orderBy('unit_number')->get(['id', 'unit_number']),
                'roleOptions' => [
                    ['value' => 'owner', 'label' => UserRole::Owner->label()],
                    ['value' => 'tenant', 'label' => UserRole::Tenant->label()],
                ],
            ],
        ]);
    }

    public function store(StoreResidentRequest $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $data = $request->validated();

        /*
         * شماره‌ای که از قبل ثبت شده، دعوت می‌گیرد نه خطا (R21).
         *
         * پیش از این، کاربری که خودش ثبت‌نام کرده بود در بن‌بستِ کامل می‌ماند:
         * خودش نمی‌توانست وارد شود (حساب غیرفعال ساخته می‌شد) و مدیر هم
         * نمی‌توانست اضافه‌اش کند چون شماره یکتاست. سنجیده شد و اثبات شد.
         *
         * وصل‌کردنِ مستقیمِ حسابِ موجود عمداً انتخاب **نشد**: یعنی هر مدیری با
         * دانستنِ یک شماره می‌توانست آن حساب را به مجتمعِ خودش بکشد و نقشش را
         * عوض کند، بدونِ اطلاعِ صاحبش.
         */
        if ($existing = $this->existingUnattachedUser($data['phone'])) {
            return $this->invite($existing, $complex, $data);
        }

        $resident = User::create([
            'complex_id' => $complex->id,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        $this->syncUnit($resident, $data);

        return response()->json(['resident' => $this->present($resident->load('currentUnits'))], 201);
    }

    /**
     * کاربرِ موجودی که هنوز به هیچ مجتمعی وصل نیست — یعنی «حالتِ اولیه».
     *
     * کاربری که از قبل عضوِ مجتمعِ دیگری است اینجا برنمی‌گردد و مسیرِ عادیِ
     * اعتبارسنجی ۴۲۲ می‌دهد. عمدی است: بیرون‌کشیدنِ ساکنِ مجتمعِ دیگر کارِ
     * این فرم نیست.
     */
    private function existingUnattachedUser(string $phone): ?User
    {
        return User::withoutGlobalScopes()
            ->where('phone', Phone::normalize($phone))
            ->whereNull('complex_id')
            ->first();
    }

    /** @param  array<string, mixed>  $data */
    private function invite(User $user, Complex $complex, array $data): JsonResponse
    {
        $invitation = ComplexInvitation::updateOrCreate(
            [
                'complex_id' => $complex->id,
                'user_id' => $user->id,
                'status' => ComplexInvitation::PENDING,
            ],
            [
                'unit_id' => $data['unit_id'] ?? null,
                'role' => $data['role'],
                'invited_by' => Auth::id(),
            ],
        );

        Audit::log('invitation.sent', 'ارسال دعوت به کاربر موجود', $invitation, [
            'phone' => $user->phone,
        ]);

        return response()->json([
            'invited' => true,
            'message' => 'این شماره از قبل در سامانه حساب دارد. دعوت برایش فرستاده شد و '
                .'پس از پذیرشِ خودش به مجتمع اضافه می‌شود.',
        ], 202);
    }

    public function update(StoreResidentRequest $request, User $resident): JsonResponse
    {
        $this->guard($resident);
        $data = $request->validated();

        $resident->update(array_filter([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'role' => $data['role'],
            // رمز فقط وقتی عوض می‌شود که مقدار جدیدی داده شده باشد
            'password' => filled($data['password'] ?? null) ? Hash::make($data['password']) : null,
        ], fn ($value) => $value !== null));

        $this->syncUnit($resident, $data);

        return response()->json(['resident' => $this->present($resident->fresh('currentUnits'))]);
    }

    public function destroy(User $resident): JsonResponse
    {
        $this->guard($resident);

        Audit::log('resident.deleted', 'حذف ساکن', $resident, [
            'name' => $resident->name,
            'phone' => $resident->phone,
        ]);

        $resident->delete();

        return response()->json(['message' => 'ساکن حذف شد.']);
    }

    public function toggleActive(User $resident): JsonResponse
    {
        $this->guard($resident);
        $resident->update(['is_active' => ! $resident->is_active]);

        Audit::log(
            $resident->is_active ? 'resident.activated' : 'resident.deactivated',
            $resident->is_active ? 'فعال‌سازی حساب ساکن' : 'غیرفعال‌سازی حساب ساکن',
            $resident,
            ['name' => $resident->name],
        );

        return response()->json(['resident' => $this->present($resident->fresh('currentUnits'))]);
    }

    /**
     * محدودکردن یک ساکن در پیام‌رسان.
     *
     * ستون `can_message` از قبل در MessengerController اعمال می‌شد ولی هیچ
     * راهی برای تغییرش وجود نداشت؛ یعنی قابلیتِ «محدودیت کاربر» عملاً در
     * دسترس مدیر نبود.
     */
    public function toggleMessaging(User $resident): JsonResponse
    {
        $this->guard($resident);
        $resident->update(['can_message' => ! $resident->can_message]);

        Audit::log(
            $resident->can_message ? 'resident.messaging_allowed' : 'resident.messaging_blocked',
            $resident->can_message ? 'بازکردن پیام‌رسان برای ساکن' : 'بستن پیام‌رسان برای ساکن',
            $resident,
            ['name' => $resident->name],
        );

        return response()->json([
            'resident' => $this->present($resident->fresh('currentUnits')),
            'message' => $resident->can_message
                ? 'ارسال پیام برای این ساکن آزاد شد.'
                : 'ارسال پیام برای این ساکن بسته شد.',
        ]);
    }

    /**
     * شکلِ خروجی حالا در `ResidentResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(User $user): array
    {
        return (new ResidentResource($user))->toArray(request());
    }

    private function syncUnit(User $resident, array $data): void
    {
        if (empty($data['unit_id'])) {
            return;
        }

        $relation = $resident->role === UserRole::Owner
            ? ResidentRelation::Owner
            : ResidentRelation::Tenant;

        // سابقه‌ی سکونت قبلی حفظ می‌شود؛ فقط ردیف جاری جابه‌جا می‌شود.
        $resident->units()->newPivotStatement()
            ->where('user_id', $resident->id)
            ->update(['is_current' => false]);

        /*
         * complex_id در جدول pivot ستون NOT NULL است و برخلاف مدل‌ها،
         * BelongsToComplex آن را برای رابطه‌ی چند-به-چند پر نمی‌کند. بدون
         * این خط، اتصال ساکن به واحد همیشه با خطای پایگاه‌داده می‌ترکید.
         */
        $resident->units()->syncWithoutDetaching([
            $data['unit_id'] => [
                'complex_id' => $this->requireComplex()->id,
                'relation' => $relation->value,
                'is_current' => true,
                'start_date' => now(),
            ],
        ]);
    }

    /** جلوگیری از دست‌زدن به کاربران خارج از مجتمع جاری. */
    private function guard(User $resident): void
    {
        $this->authorize('manageResident', $resident);
    }
}
