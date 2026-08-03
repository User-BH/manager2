<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequests\ServiceRequestService;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * درخواست‌های ساکنین و واگذاری به مسئول (R25).
 *
 * پیش از این تنها راهِ گفتنِ «آسانسور خراب است» یک پیام در پیام‌رسان بود که
 * نه وضعیت داشت، نه متولی، و نه زمانِ پاسخگویی.
 */
class ServiceRequestController extends Controller
{
    /** بیش از این، فهرست باید صفحه‌بندیِ واقعی بگیرد. */
    private const PER_PAGE = 20;

    public function __construct(private readonly ServiceRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('viewAny', ServiceRequest::class);

        $query = ServiceRequest::query()
            ->visibleTo($user)
            ->with(['unit:id,unit_number', 'requester:id,name', 'assignee:id,name']);

        if ($status = $request->string('status')->value()) {
            // «باز» یک وضعیت نیست، چند وضعیت است — و پیش‌فرضِ مفیدِ مدیر
            $status === 'open'
                ? $query->open()
                : $query->where('status', $status);
        }

        if ($category = $request->string('category')->value()) {
            $query->where('category', $category);
        }

        /*
         * «فقط مالِ من» برای مسئول: کسی که ده درخواست به او واگذار شده،
         * فهرستِ کاملِ ساختمان برایش نویز است.
         */
        if ($request->boolean('mine')) {
            $query->where('assigned_to', $user->id);
        }

        $paginator = $query
            // بحرانی بالای فهرست، بعد تازه‌ترین — نه فقط تاریخ
            ->orderByRaw($this->priorityOrder())
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'requests' => collect($paginator->items())
                ->map(fn (ServiceRequest $r) => new ServiceRequestResource($r, $user))
                ->all(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),

            // شمارنده‌ی وضعیت‌ها، مستقل از فیلترِ جاری تا فیلتر خودش را پنهان نکند
            'counts' => $this->counts($user),

            'isAdmin' => $user->role->isAdmin(),
            'categories' => ServiceRequestCategory::options(),
            'statuses' => ServiceRequestStatus::options(),
            'priorities' => ServiceRequestPriority::options(),

            // فهرستِ مسئول‌های ممکن، فقط برای مدیر
            'assignables' => $user->role->isAdmin() ? $this->assignables() : [],
        ]);
    }

    public function show(ServiceRequest $serviceRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('view', $serviceRequest);

        $serviceRequest->load(['unit:id,unit_number', 'requester:id,name', 'assignee:id,name', 'comments.author:id,name']);

        return response()->json(['request' => new ServiceRequestResource($serviceRequest, $user)]);
    }

    public function store(StoreServiceRequestRequest $request): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('create', ServiceRequest::class);

        $data = $request->validated();
        $attachment = $request->file('attachment');

        /*
         * واحدِ درخواست: ساکن هرچه بفرستد نادیده گرفته می‌شود و واحدِ خودش
         * می‌نشیند. وگرنه می‌شد درخواستی به نامِ واحدِ همسایه ثبت کرد و از
         * راهِ `visibleTo` گفت‌وگویش را خواند.
         */
        $unitId = $user->role->isAdmin()
            ? ($data['unit_id'] ?? null)
            : $user->units()->value('units.id');

        $write = fn (?string $path) => ServiceRequest::create([
            'unit_id' => $unitId,
            'user_id' => $user->id,
            'category' => $data['category'],
            'priority' => $data['priority'] ?? ServiceRequestPriority::Normal->value,
            'status' => ServiceRequestStatus::New,
            'title' => $data['title'],
            'description' => $data['description'],
            'attachment_path' => $path,
            'attachment_name' => $attachment ? Uploads::safeOriginalName($attachment) : null,
        ]);

        // `keepIf` تضمین می‌کند فایل بدونِ درخواستِ متناظر روی دیسک نماند (R19)
        $serviceRequest = $attachment
            ? Uploads::keepIf($attachment, 'requests/'.$this->requireComplex()->id, $write)
            : $write(null);

        return response()->json(
            ['request' => new ServiceRequestResource($serviceRequest, $user)],
            201,
        );
    }

    /** تغییرِ وضعیت — قاعده‌هایش در سرویس است، نه اینجا. */
    public function updateStatus(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('update', $serviceRequest);

        $status = ServiceRequestStatus::tryFrom((string) $request->input('status'));
        abort_if($status === null, 422);

        $updated = $this->requests->changeStatus(
            $serviceRequest,
            $user,
            $status,
            $request->string('note')->value(),
        );

        return response()->json(['request' => new ServiceRequestResource($updated, $user)]);
    }

    public function assign(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('assign', $serviceRequest);

        $assigneeId = $request->integer('assigned_to');

        $updated = $this->requests->assign(
            $serviceRequest,
            $user,
            $assigneeId > 0 ? User::findOrFail($assigneeId) : null,
        );

        return response()->json(['request' => new ServiceRequestResource($updated, $user)]);
    }

    /** بالا/پایین بردنِ فوریت — فقط مدیر، چون `Critical` دستِ ساکن نیست. */
    public function setPriority(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('assign', $serviceRequest);

        $priority = ServiceRequestPriority::tryFrom((string) $request->input('priority'));
        abort_if($priority === null, 422);

        $serviceRequest->update(['priority' => $priority]);

        return response()->json(['request' => new ServiceRequestResource($serviceRequest, $user)]);
    }

    public function comment(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = Auth::user();
        $this->authorize('update', $serviceRequest);

        $body = trim((string) $request->input('body'));
        abort_if($body === '' || mb_strlen($body) > 2000, 422);

        $this->requests->comment(
            $serviceRequest,
            $user,
            $body,
            internal: $request->boolean('is_internal'),
        );

        $serviceRequest->load('comments.author:id,name');

        return response()->json(['request' => new ServiceRequestResource($serviceRequest, $user)]);
    }

    /** پیوست از دیسکِ خصوصی؛ ۴۰۴ و نه ۴۰۳، چون وجودِ پیوست هم اطلاعات است. */
    public function attachment(ServiceRequest $serviceRequest): StreamedResponse
    {
        $this->authorize('view', $serviceRequest);
        abort_unless($serviceRequest->hasAttachment(), 404);

        return Uploads::serve($serviceRequest->attachment_path);
    }

    /**
     * شمارشِ وضعیت‌ها برای تب‌های بالای فهرست.
     *
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        $rows = ServiceRequest::query()
            ->visibleTo($user)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['open' => 0];

        foreach (ServiceRequestStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);

            if ($status->isOpen()) {
                $counts['open'] += $counts[$status->value];
            }
        }

        return $counts;
    }

    /**
     * کسانی که می‌شود درخواست را به آن‌ها واگذار کرد.
     *
     * عمداً همه‌ی کاربرانِ فعالِ مجتمع‌اند و نه فقط مدیران: در ساختمانِ
     * واقعی، مسئولِ پیگیری اغلب سرایدار یا عضوِ هیئت‌مدیره است که نقشش در
     * سامانه «ساکن» است. نقشِ تازه ساختن برای این کار، کلِ مجوزدهی را
     * درگیر می‌کرد بی‌آنکه چیزی به دست بیاید.
     *
     * @return array<int, array{id: int, name: string, role: string}>
     */
    private function assignables(): array
    {
        return User::where('complex_id', $this->requireComplex()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role->label(),
            ])
            ->all();
    }

    /** مرتب‌سازیِ فوریت بدونِ ستونِ عددیِ اضافه در دیتابیس. */
    private function priorityOrder(): string
    {
        $cases = collect(ServiceRequestPriority::cases())
            ->map(fn (ServiceRequestPriority $p) => "when '{$p->value}' then {$p->weight()}")
            ->implode(' ');

        return "case priority {$cases} else 0 end desc";
    }
}
