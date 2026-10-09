<?php

namespace App\Http\Controllers\Api;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Requests\UpdateResidentRequest;
use App\Http\Resources\InvitationPresenter;
use App\Http\Resources\ResidentResource;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Units\TenureService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResidentController extends Controller
{
    public function __construct(
        private readonly TenureService $tenures,
        private readonly Membership $membership,
    ) {}

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

    /**
     * «ساکن جدید» = دعوتِ امن به شماره (مرحلهٔ ۹ · فاز ۶، F5 و F6).
     *
     * هیچ حسابی اینجا ساخته نمی‌شود و هیچ کس به مجتمع وصل نمی‌شود. پاسخ پیوندِ
     * یک‌بارمصرف را **فقط همین یک بار** دارد؛ مدیر خودش آن را برای صاحبِ شماره
     * می‌فرستد (پیامکِ سامانه فقط برای OTP و یادآورِ بدهی است). صاحبِ شماره
     * با OTP ثبت‌نام یا وارد می‌شود و با کدِ تازه می‌پذیرد.
     *
     * ─── چرا وصلِ مستقیمِ حسابِ موجود هنوز انتخاب نمی‌شود (R21) ────────────
     * یعنی هر مدیری با دانستنِ یک شماره می‌توانست آن حساب را به مجتمعِ خودش
     * بکشد و نقشش را عوض کند، بی اطلاعِ صاحبش.
     */
    public function store(StoreResidentRequest $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $data = $request->validated();

        [$invitation, $token] = $this->membership->invite(
            $complex,
            $data['phone'],
            UserRole::from($data['role']),
            Unit::findOrFail($data['unit_id']),
            $request->user(),
        );

        Audit::log('invitation.sent', 'ارسال دعوت به شماره', $invitation, [
            'phone' => $invitation->phone,
            'role' => $invitation->role->value,
        ]);

        return response()->json([
            'invited' => true,
            'invitation' => InvitationPresenter::forSender($invitation->load('unit')),
            'link' => InvitationPresenter::link($token),
            'message' => 'دعوت ساخته شد. پیوند را برای صاحبِ این شماره بفرستید؛ '
                .'با همان شماره وارد یا ثبت‌نام می‌کند و با کدِ پیامکی می‌پذیرد.',
        ], 201);
    }

    public function update(UpdateResidentRequest $request, User $resident): JsonResponse
    {
        $this->guard($resident);
        $data = $request->validated();

        /*
         * نامِ حسابی که خودش نام و نام خانوادگی‌اش را ثبت کرده مالِ خودِ کاربر
         * است (مرحلهٔ ۹ · فاز ۳) — در معماریِ چندمجتمعی هم بینِ مجتمع‌ها یکی است.
         * مدل `name` را از آن دو می‌سازد؛ بی این خطا، ویرایشِ مدیر بی‌صدا نادیده
         * گرفته می‌شد. حسابی که مدیر ساخته (بی دو بخش) مثلِ قبل ویرایش می‌شود.
         */
        if (isset($data['name']) && ! $resident->needsNameCompletion() && $data['name'] !== $resident->name) {
            throw ValidationException::withMessages([
                'name' => 'نامِ این حساب را خودِ کاربر در پروفایلش تعیین می‌کند.',
            ]);
        }

        // شماره و رمز اینجا نیستند: `UpdateResidentRequest` هر دو را رد می‌کند
        $resident->update(array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'role' => $data['role'],
        ], fn ($value) => $value !== null));

        $this->syncUnit($resident, $data);

        return response()->json(['resident' => $this->present($resident->fresh('currentUnits'))]);
    }

    /**
     * «حذفِ ساکن» = **پایانِ عضویت**، نه حذفِ حساب (مرحلهٔ ۹ · فاز ۶، F4).
     *
     * پیش از این ردیفِ `users` حذف می‌شد: دوره‌های واحد (`cascadeOnDelete`)،
     * پرداخت‌کننده‌ی پرداخت‌ها (`NULL`) و حسابِ ساکنا همه می‌رفتند. حالا
     * دوره‌ها بسته و حساب به حالتِ اولیه برمی‌گردد؛ هیچ ردیفی حذف نمی‌شود.
     */
    public function destroy(User $resident): JsonResponse
    {
        $this->guard($resident);

        Audit::log('resident.removed', 'پایانِ عضویتِ ساکن', $resident, [
            'name' => $resident->name,
            'phone' => $resident->phone,
            'complex_id' => $resident->complex_id,
        ]);

        $this->membership->end($resident);

        return response()->json([
            'message' => 'عضویتِ ساکن در مجتمع پایان یافت. حساب و سابقه‌اش حفظ شد.',
        ]);
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

    /**
     * تخصیصِ واحد به ساکن — از راهِ `TenureService` (R26).
     *
     * ─── چه چیزی عوض شد و چرا ───────────────────────────────────────────────
     * پیش از این `syncWithoutDetaching` استفاده می‌شد. آن متد ردیفِ موجودِ
     * همان (واحد، کاربر) را **بازنویسی** می‌کند، پس:
     *
     *   • مستاجری که واحد ۵ را ترک کرده و دو سال بعد برگشته، دوره‌ی اولش
     *     برای همیشه پاک می‌شد،
     *   • و `end_date` هرگز پر نمی‌شد، پس حتی دوره‌های بسته‌شده تاریخِ
     *     پایان نداشتند.
     *
     * حالا هر تخصیص یک ردیفِ تازه است و ردیف‌های قبلی بسته می‌شوند.
     */
    private function syncUnit(User $resident, array $data): void
    {
        if (empty($data['unit_id'])) {
            return;
        }

        $unit = Unit::findOrFail($data['unit_id']);
        $relation = $resident->role === UserRole::Owner ? ResidentRelation::Owner : ResidentRelation::Tenant;

        /*
         * همان واحد و همان نسبت: دوره‌ی جاری ادامه دارد. واحد حالا در ویرایش
         * اجباری است (F6)؛ بی این شرط، هر ویرایشِ ایمیل یک دوره‌ی تازه می‌ساخت
         * و سابقه‌ی واحد را با دوره‌های یک‌روزه پر می‌کرد.
         */
        if ($resident->currentUnits()->whereKey($unit->id)->wherePivot('relation', $relation->value)->exists()) {
            return;
        }

        $this->tenures->open(
            $unit,
            $resident,
            $relation,
            // سهمِ مالکیت اختیاری است؛ نبودش یعنی تک‌مالکی
            (float) ($data['share_percent'] ?? 100),
        );
    }

    /** جلوگیری از دست‌زدن به کاربران خارج از مجتمع جاری. */
    private function guard(User $resident): void
    {
        $this->authorize('manageResident', $resident);
    }
}
