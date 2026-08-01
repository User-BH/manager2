<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateObservabilityRequest;
use App\Models\ErrorEvent;
use App\Support\Observability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تنظیمات و گزارش‌های پایش و تحلیل — فقط برای ادمینِ کل.
 *
 * هدفِ طراحی: صاحبِ پروژه اگر عوض شد، بتواند **بدونِ هیچ تغییری در کد** حسابِ
 * تحلیلیِ خودش را وصل کند. پس هر شناسه یا از `.env` می‌آید یا از همین صفحه، و
 * اولویت با همین صفحه است.
 */
class ObservabilityController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'fields' => Observability::forPanel(),
            'services' => Observability::status(),
            'summary' => $this->summary(),
        ]);
    }

    public function update(UpdateObservabilityRequest $request): JsonResponse
    {
        $data = $request->validated();

        // مقدارهای محرمانه اگر ماسک باشند دست‌نخورده می‌مانند (داخل save)
        Observability::save($data);

        return response()->json([
            'message' => 'تنظیمات پایش ذخیره شد.',
            'fields' => Observability::forPanel(),
            'services' => Observability::status(),
        ]);
    }

    /** فهرستِ خطاهای ثبت‌شده در دیتابیسِ خودمان. */
    public function errors(Request $request): JsonResponse
    {
        $query = ErrorEvent::query()
            ->with(['user:id,name,phone'])
            ->when($request->string('source')->toString(), fn ($q, $source) => $q->where('source', $source))
            ->when(
                ! $request->boolean('include_resolved'),
                fn ($q) => $q->where('is_resolved', false)
            )
            ->orderByDesc('last_seen_at');

        $events = $query->paginate(20);

        return response()->json([
            'data' => $events->getCollection()->map(fn (ErrorEvent $event) => [
                'id' => $event->id,
                'source' => $event->source,
                'sourceLabel' => $event->source === 'client' ? 'مرورگر' : 'سرور',
                'type' => class_basename($event->type),
                'fullType' => $event->type,
                'message' => $event->message,
                'file' => $event->file,
                'line' => $event->line,
                'stack' => $event->stack,
                'url' => $event->url,
                'method' => $event->method,
                'status' => $event->status,
                'occurrences' => $event->occurrences,
                'userName' => $event->user?->name,
                'firstSeen' => $event->first_seen_at?->diffForHumans(),
                'lastSeen' => $event->last_seen_at?->diffForHumans(),
                'isResolved' => $event->is_resolved,
            ])->all(),
            'meta' => [
                'currentPage' => $events->currentPage(),
                'lastPage' => $events->lastPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /** «بررسی شد» — خطا پاک نمی‌شود، فقط از فهرستِ فعال بیرون می‌رود. */
    public function resolve(ErrorEvent $errorEvent): JsonResponse
    {
        $errorEvent->update(['is_resolved' => true]);

        return response()->json(['message' => 'خطا به‌عنوان بررسی‌شده علامت خورد.']);
    }

    /**
     * آمارِ خلاصه برای کارت‌های بالای صفحه.
     *
     * همه از دیتابیسِ خودمان می‌آید، پس حتی وقتی هیچ سرویسِ بیرونی وصل نیست
     * این صفحه داده‌ی واقعی دارد.
     */
    /**
     * سلامتِ صف.
     *
     * ─── چرا این در پنل لازم است ───────────────────────────────────────────
     * از R11 بکاپ‌ها در صف ساخته می‌شوند. اگر کارگر (`queue:work`) روی سرور
     * بالا نباشد، Jobها بی‌صدا در جدول تلنبار می‌شوند: کاربر «در حال ساخت…»
     * می‌بیند و هیچ‌وقت تمام نمی‌شود، بی‌آنکه خطایی جایی ثبت شود.
     *
     * `oldestPendingMinutes` همان زنگِ خطر است: کارِ چنددقیقه‌ای که ساعت‌ها
     * منتظر مانده یعنی کارگر نمی‌چرخد.
     *
     * @return array<string, mixed>
     */
    private function queueHealth(): array
    {
        $oldest = DB::table('jobs')->min('available_at');

        return [
            'pending' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'oldestPendingMinutes' => $oldest
                ? (int) round((now()->timestamp - (int) $oldest) / 60)
                : 0,
        ];
    }

    private function summary(): array
    {
        $now = now();

        return [
            'queue' => $this->queueHealth(),
            'openErrors' => ErrorEvent::where('is_resolved', false)->count(),
            'last24h' => ErrorEvent::where('last_seen_at', '>=', $now->copy()->subDay())->sum('occurrences'),
            'last7days' => ErrorEvent::where('last_seen_at', '>=', $now->copy()->subDays(7))->sum('occurrences'),
            'serverVsClient' => [
                'server' => ErrorEvent::where('source', 'server')->where('is_resolved', false)->count(),
                'client' => ErrorEvent::where('source', 'client')->where('is_resolved', false)->count(),
            ],
            /** پرتکرارترین‌ها: جایی که رفعِ یک باگ بیشترین اثر را دارد. */
            'topErrors' => ErrorEvent::where('is_resolved', false)
                ->orderByDesc('occurrences')
                ->limit(5)
                ->get(['id', 'type', 'message', 'occurrences'])
                ->map(fn (ErrorEvent $e) => [
                    'id' => $e->id,
                    'type' => class_basename($e->type),
                    'message' => mb_substr($e->message, 0, 120),
                    'occurrences' => $e->occurrences,
                ])->all(),
            /*
             * نمودارِ روزانه‌ی ۱۴ روزِ اخیر.
             *
             * با کوئری‌بیلدرِ خام و نه Eloquent: ستون‌های تجمیعی (`day`, `total`)
             * روی مدل وجود ندارند و خواندنشان از یک نمونه‌ی `ErrorEvent` هم
             * گمراه‌کننده است و هم تحلیلِ ایستا را می‌شکند.
             */
            'daily' => DB::table('error_events')
                ->where('last_seen_at', '>=', $now->copy()->subDays(14))
                ->selectRaw('DATE(last_seen_at) as day, SUM(occurrences) as total')
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(fn (object $row) => [
                    'day' => (string) ($row->day ?? ''),
                    'total' => (int) ($row->total ?? 0),
                ])
                ->all(),
        ];
    }
}
