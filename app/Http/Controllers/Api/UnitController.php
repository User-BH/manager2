<?php

namespace App\Http\Controllers\Api;

use App\Enums\OccupancyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\TransferOwnershipRequest;
use App\Http\Resources\UnitResource;
use App\Models\Building;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Subscription\PlanGate;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireComplex();

        $units = Unit::with('building')
            ->when($request->string('search')->trim()->value(), function ($query, string $search) {
                $query->where(fn ($q) => $q
                    ->where('unit_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%"));
            })
            ->when($request->string('occupancy')->value(), fn ($q, $s) => $q->where('occupancy_status', $s))
            ->orderBy('unit_number')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => collect($units->items())->map(fn (Unit $u) => $this->present($u))->values(),
            'meta' => [
                'currentPage' => $units->currentPage(),
                'lastPage' => $units->lastPage(),
                'perPage' => $units->perPage(),
                'total' => $units->total(),
            ],
            'filters' => [
                'buildings' => Building::orderBy('name')->get(['id', 'name']),
                'occupancyOptions' => collect(OccupancyStatus::cases())
                    ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
            ],
        ]);
    }

    /**
     * پرونده‌ی کاملِ یک واحد: مشخصات، ساکنانِ جاری و **تاریخچه** (R26).
     *
     * ─── چرا تاریخچه اینجا و نه در فهرست ────────────────────────────────────
     * فهرستِ واحدها با ۲۰ ردیف در هر صفحه خوانده می‌شود؛ آوردنِ دوره‌های هر
     * واحد آنجا یعنی یک کوئریِ اضافه به ازای هر ردیف. تاریخچه فقط وقتی
     * لازم است که مدیر پرونده‌ی یک واحد را باز کند.
     *
     * ─── چرا شمارشِ مالی هم برمی‌گردد ───────────────────────────────────────
     * خواسته‌ی اصلیِ این مرحله «تاریخچه‌ای که با تغییرِ مالک/مستاجر پاک
     * نشود» بود. نشان‌دادنِ اینکه این واحد چند قبض و چند پرداخت دارد،
     * همان تضمین را جلوی چشمِ مدیر می‌گذارد: پرونده به **واحد** بسته است،
     * نه به کسی که امروز آنجا زندگی می‌کند.
     */
    public function show(Unit $unit, TenureService $tenures): JsonResponse
    {
        $this->requireComplex();
        $this->authorize('view', $unit);

        return response()->json([
            'unit' => $this->present($unit),
            'tenures' => $tenures->history($unit)->map(fn (UnitTenure $tenure) => [
                'id' => $tenure->id,
                'userId' => $tenure->user_id,
                // کاربرِ حذف‌شده: رابطه هست ولی رکورد ممکن است نباشد
                'name' => $tenure->user === null ? 'کاربر حذف‌شده' : $tenure->user->name,
                'phone' => $tenure->user?->phone,
                'relation' => $tenure->relation->value,
                'relationLabel' => $tenure->relation->label(),
                'sharePercent' => (float) $tenure->share_percent,
                'startDate' => $tenure->start_date ? Jalali::date($tenure->start_date) : null,
                'endDate' => $tenure->end_date ? Jalali::date($tenure->end_date) : null,
                'isCurrent' => (bool) $tenure->is_current,
                'isOpen' => $tenure->isOpen(),
            ])->values(),

            /*
             * جمعِ سهمِ مالکانِ جاری. اگر ۱۰۰ نباشد، پرونده ناقص است و مدیر
             * باید ببیندش — پنهان‌کردنش یعنی رأیِ وزنیِ نظرسنجی (R24) و
             * سهمِ هزینه بی‌سروصدا غلط بماند.
             */
            'ownershipShare' => (float) $unit->tenures()->current()->owners()->sum('share_percent'),

            'history' => [
                'bills' => $unit->bills()->count(),
                'payments' => $unit->payments()->count(),
            ],
        ]);
    }

    public function store(StoreUnitRequest $request, PlanGate $plans): JsonResponse
    {
        $complex = $this->requireComplex();

        // سقف تعداد واحد پلن رایگان — پیش از اعتبارسنجی تا کاربر بی‌دلیل
        // فرم را پر نکند و بعد رد شود.
        $plans->assertCanAddUnit($complex);

        $unit = Unit::create($request->validated());

        return response()->json(['unit' => $this->present($unit->load('building'))], 201);
    }

    public function update(StoreUnitRequest $request, Unit $unit): JsonResponse
    {
        $unit->update($request->validated());

        return response()->json(['unit' => $this->present($unit->fresh('building'))]);
    }

    /**
     * انتقالِ مالکیتِ واحد به مجموعه‌ی تازه‌ای از مالکان (R26).
     *
     * عملیاتِ مستقل است و نه «افزودنِ مالک»: مالکانِ قبلی باید با هم بسته
     * شوند و تازه‌ها با هم باز، وگرنه در فاصله‌ی میانی واحد یا بی‌مالک است
     * یا دو مالکِ ۱۰۰ درصدی دارد.
     */
    public function transferOwnership(
        TransferOwnershipRequest $request,
        Unit $unit,
        TenureService $tenures,
    ): JsonResponse {
        $this->requireComplex();
        $this->authorize('update', $unit);

        $owners = collect($request->validated()['owners'])
            ->map(fn (array $row) => [
                'user' => User::findOrFail($row['user_id']),
                'share' => (float) $row['share_percent'],
            ])
            ->all();

        $tenures->transferOwnership($unit, $owners);

        return response()->json(['message' => 'مالکیت واحد منتقل شد.']);
    }

    /** بستنِ دستیِ یک دوره — مثلاً وقتی مستاجر رفته ولی هنوز جایگزینی نیامده. */
    public function endTenure(Unit $unit, UnitTenure $tenure, TenureService $tenures): JsonResponse
    {
        $this->requireComplex();
        $this->authorize('update', $unit);

        // ۴۰۴ و نه ۴۰۳: دوره‌ی واحدِ دیگری نباید حتی وجودش تایید شود
        abort_unless($tenure->unit_id === $unit->id, 404);

        $tenures->close($tenure);

        return response()->json(['message' => 'این دوره بسته شد.']);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        // حذف واحد آبشاری است و قبوض و پرداخت‌هایش را هم می‌برد؛ ردش باید
        // بماند — که `AuditObserver` خودکار انجامش می‌دهد.
        $unit->delete();

        return response()->json(['message' => 'واحد حذف شد.']);
    }

    /**
     * شکلِ خروجی حالا در `UnitResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Unit $unit): array
    {
        return (new UnitResource($unit))->toArray(request());
    }
}
