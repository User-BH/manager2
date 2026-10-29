<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CursorPage;
use App\Support\DemoData;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مشاهده‌ی لاگ فعالیت.
 *
 * جدول `audit_logs` از ابتدا پر می‌شد ولی هیچ راهی برای دیدنش نبود؛ یعنی
 * عملاً وجود نداشت. اینجا فقط خواندنی است: رویداد ثبت‌شده نباید از رابط
 * کاربری قابل حذف یا ویرایش باشد، وگرنه ارزش ردیابی‌اش را از دست می‌دهد.
 *
 * ─── فعالیتِ مدیران (۲۰۲۶-۱۰-۲۹، بندِ ۳) ─────────────────────────────────
 * پروژه دو مدیر دارد و هر کار باید به نامِ همان مدیر دیده شود: «کدام مدیر به فلان بازخورد پاسخ داد، کدام کاربر را حذف
 * کرد». `scope=admins` فقط رویدادهایی را می‌دهد که یک سوپر ادمین انجام داده، و `admin_id` یکی از آن‌ها را. مرز همان
 * `role:super_admin` ِ مسیر است: هیچ مسیرِ دیگری این جدول را نمی‌خواند، پس این فهرست جای دیگری دیده نمی‌شود.
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
        $admins = $this->admins();
        $adminIds = array_column($admins, 'id');

        $query = AuditLog::with('user:id,name,phone')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('complex_id'), fn ($q) => $q->where('complex_id', $request->integer('complex_id')))
            ->when($request->input('scope') === 'admins', fn ($q) => $q->whereIn('user_id', $adminIds))
            // شناسه‌ای جز مدیرانِ همین فهرست، فهرستِ خالی است — نه رویدادهای کاربرِ دیگری
            ->when($request->filled('admin_id'), fn ($q) => $q->where('user_id', in_array($request->integer('admin_id'), $adminIds, true) ? $request->integer('admin_id') : 0));

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
                'byAdmin' => in_array($log->user_id, $adminIds, true),
                'ip' => $log->ip_address,
                'properties' => $log->properties,
                'at' => Jalali::dateTime($log->created_at),
            ],
        );

        return response()->json($page + [
            'actions' => $this->availableActions(),
            'admins' => $admins,
        ]);
    }

    /** برچسب فارسی هر رویداد؛ ناشناخته‌ها خودِ کلید را نشان می‌دهند. */
    private function label(string $action): string
    {
        return match ($action) {
            'payment.settled' => 'تایید پرداخت',
            'payment.rejected' => 'رد رسید پرداخت',
            'resident.deleted' => 'حذف ساکن',
            'resident.removed' => 'پایانِ عضویتِ ساکن',
            'manager.removed' => 'پایانِ مدیریتِ مجتمع',
            'member.deactivated' => 'حذفِ کاربر (غیرفعال‌سازی)',
            'member.updated' => 'تغییرِ نقش یا وضعیتِ عضو سامانه',
            'complex.suspended' => 'تعلیقِ مجتمع',
            'complex.activated' => 'بازگرداندنِ دسترسیِ مجتمع',
            'complex.retyped' => 'تغییرِ نوعِ مجتمع',
            'invitation.sent' => 'ارسالِ دعوت',
            'invitation.revoked' => 'ابطالِ دعوت',
            'invitation.accepted' => 'پذیرشِ دعوت',
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
            'admin.signed_in' => 'ورودِ مدیر',
            'profile.password_changed' => 'تغییرِ رمزِ عبور',
            'feedback.replied' => 'پاسخ به بازخورد',
            'feedback.status_changed' => 'تغییرِ وضعیتِ بازخورد',
            'account_recovery.approved' => 'تأییدِ بازیابیِ حساب',
            'account_recovery.rejected' => 'ردِ بازیابیِ حساب',
            'account_recovery.requested' => 'درخواستِ بازیابیِ حساب',
            'plan.created' => 'ساختِ پکیج',
            'plan.updated' => 'ویرایشِ پکیج',
            'plan.toggled' => 'فعال/غیرفعال‌کردنِ پکیج',
            'plan.deleted' => 'حذفِ پکیج',
            'subscription.granted' => 'اهدای پلن',
            'blog.published' => 'انتشارِ مقاله',
            'blog.deleted' => 'حذفِ مقاله',
            'feature.toggled' => 'تغییرِ قابلیت',
            'two_factor.enabled' => 'فعال‌سازیِ Authenticator',
            'two_factor.rotated' => 'تعویضِ Authenticator',
            'two_factor.reset' => 'بازنشانیِ Authenticator',
            'two_factor.recovery_code_used' => 'ورود با کدِ بازیابی',
            'two_factor.recovery_codes_regenerated' => 'ساختِ کدهای بازیابیِ تازه',
            'checkout.refunded' => 'ثبتِ بازپرداخت',
            'user.deleted' => 'حذفِ کاربر',
            'advertisement.deleted' => 'حذفِ تبلیغ',
            default => $action,
        };
    }

    /**
     * مدیرانِ سامانه — هر سوپر ادمینِ واقعی، فعال یا غیرفعال (لاگِ مدیرِ غیرفعال‌شده هم باید با نامش بماند). حسابِ
     * آزمایشی فقط بیرون از Production (Preview)، جایی که خودش همان مدیر است.
     *
     * @return list<array{id: int, name: string}>
     */
    private function admins(): array
    {
        return User::withoutGlobalScopes()
            ->where('role', UserRole::SuperAdmin->value)
            ->when(! DemoData::allowedHere(), fn ($q) => $q->where('is_demo', false))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => (string) $u->name])
            ->all();
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
