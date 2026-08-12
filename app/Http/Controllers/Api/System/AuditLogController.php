<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\CursorPage;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مشاهده‌ی لاگ فعالیت.
 *
 * جدول `audit_logs` از ابتدا پر می‌شد ولی هیچ راهی برای دیدنش نبود؛ یعنی
 * عملاً وجود نداشت. اینجا فقط خواندنی است: رویداد ثبت‌شده نباید از رابط
 * کاربری قابل حذف یا ویرایش باشد، وگرنه ارزش ردیابی‌اش را از دست می‌دهد.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        /*
         * صفحه‌بندیِ نشانگری (R30) و نه `offset`.
         *
         * لاگ جدولی **افزایشی** است: تا وقتی کاربر صفحه‌ی ۲ را بزند،
         * رویدادهای تازه بالای فهرست نشسته‌اند و `OFFSET 30` ردیف‌هایی را
         * برمی‌گرداند که همین الان در صفحه‌ی ۱ دیده — و همان تعداد ردیفِ
         * قدیمی‌تر برای همیشه از دیدش پنهان می‌ماند. روی لاگِ امنیتی این
         * یعنی رویدادی که باید بررسی می‌شد هرگز دیده نشود.
         */
        $query = AuditLog::with('user:id,name,phone')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('complex_id'), fn ($q) => $q->where('complex_id', $request->integer('complex_id')));

        $page = CursorPage::descending(
            $query,
            $request->integer('cursor') ?: null,
            self::PER_PAGE,
            fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actionLabel' => $this->label($log->action),
                'description' => $log->description,
                // کاربر ممکن است بعداً حذف شده باشد؛ لاگ نباید با او برود
                'userName' => $log->user?->name ?? '—',
                'userPhone' => $log->user?->phone,
                'ip' => $log->ip_address,
                'properties' => $log->properties,
                'at' => Jalali::dateTime($log->created_at),
            ],
        );

        return response()->json($page + [
            'actions' => $this->availableActions(),
        ]);
    }

    /** برچسب فارسی هر رویداد؛ ناشناخته‌ها خودِ کلید را نشان می‌دهند. */
    private function label(string $action): string
    {
        return match ($action) {
            'payment.settled' => 'تایید پرداخت',
            'payment.rejected' => 'رد رسید پرداخت',
            'resident.deleted' => 'حذف ساکن',
            'resident.activated' => 'فعال‌سازی ساکن',
            'resident.deactivated' => 'غیرفعال‌سازی ساکن',
            'resident.messaging_allowed' => 'بازکردن پیام‌رسان',
            'resident.messaging_blocked' => 'بستن پیام‌رسان',
            'unit.deleted' => 'حذف واحد',
            'manager.deleted' => 'حذف مدیر',
            'settings.gateway_changed' => 'تغییر درگاه پرداخت',
            'subscription.approved' => 'تایید اشتراک',
            'subscription.rejected' => 'رد اشتراک',
            'backup.created' => 'ساخت نسخه پشتیبان',
            'system.restored' => 'بازیابی کل سیستم',
            default => $action,
        };
    }

    /**
     * فقط رویدادهایی که واقعاً در دیتابیس هستند، برای فیلتر.
     *
     * @return array<int,array<string,string>>
     */
    private function availableActions(): array
    {
        return AuditLog::query()
            ->select('action')->distinct()->orderBy('action')->pluck('action')
            ->map(fn (string $a) => ['value' => $a, 'label' => $this->label($a)])
            ->all();
    }
}
