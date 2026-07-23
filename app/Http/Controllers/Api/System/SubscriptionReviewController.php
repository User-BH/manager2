<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * بررسی رسیدهای اشتراک توسط ادمین کل.
 *
 * چرا ادمین کل و نه مدیر مجتمع: پول اشتراک به حساب سرویس‌دهنده واریز
 * می‌شود، و پرداخت‌کننده خودِ مدیر مجتمع است. اگر مدیر مجتمع رسید خودش را
 * تایید می‌کرد، عملاً می‌توانست بدون پرداخت، اشتراک را فعال کند.
 *
 * (این با «بررسی پرداخت‌ها»ی شارژ فرق دارد: آنجا ساکن پرداخت می‌کند و مدیر
 * مجتمع بررسی می‌کند، که درست است.)
 *
 * مسیرها زیر گروه role:super_admin ثبت شده‌اند.
 */
class SubscriptionReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $pending = Subscription::where('status', 'pending')
            ->with(['complex', 'user'])
            ->latest()
            ->get();

        $recent = Subscription::whereIn('status', ['active', 'failed', 'canceled'])
            ->with(['complex', 'user', 'reviewer'])
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'pending' => $pending->map(fn (Subscription $s) => $this->present($s))->values(),
            'recent' => $recent->map(fn (Subscription $s) => $this->present($s))->values(),
            'pendingTotal' => (float) $pending->sum('amount'),
            'activeCount' => Subscription::where('status', 'active')
                ->where('ends_at', '>', now())->count(),
        ]);
    }

    /** فایل رسید روی دیسک خصوصی است و فقط از این مسیر سرو می‌شود. */
    public function receipt(Subscription $subscription): StreamedResponse
    {
        abort_if(
            ! $subscription->receipt_path
                || ! Storage::disk('local')->exists($subscription->receipt_path),
            404,
        );

        return Storage::disk('local')->response($subscription->receipt_path);
    }

    /**
     * تایید رسید: اشتراک از همین لحظه فعال و به اندازه‌ی ماه‌های پلن تمدید
     * می‌شود.
     */
    public function approve(Subscription $subscription): JsonResponse
    {
        abort_unless($subscription->status === 'pending', 422, 'این درخواست قبلاً بررسی شده است.');

        $subscription->update([
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonths($subscription->months),
            'paid_at' => now(),
            'tracking_code' => $subscription->tracking_code ?: 'MAN-'.Str::upper(Str::random(10)),
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'اشتراک تایید و فعال شد.',
            'subscription' => $this->present($subscription->fresh(['complex', 'user'])),
        ]);
    }

    public function reject(Request $request, Subscription $subscription): JsonResponse
    {
        abort_unless($subscription->status === 'pending', 422, 'این درخواست قبلاً بررسی شده است.');

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
        ], [], ['note' => 'دلیل رد']);

        $subscription->update([
            'status' => 'failed',
            'review_note' => $data['note'] ?? 'رسید تایید نشد.',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json(['message' => 'درخواست اشتراک رد شد.']);
    }

    private function present(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'complexName' => $s->complex?->name ?? '—',
            'buyerName' => $s->user?->name ?? '—',
            'buyerPhone' => $s->user?->phone,
            'plan' => $s->plan->value,
            'planLabel' => $s->plan->label(),
            'months' => (int) $s->months,
            'amount' => (float) $s->amount,
            'amountLabel' => Jalali::money($s->amount),
            'status' => $s->status,
            'statusLabel' => $s->statusLabel(),
            'method' => $s->method,
            'methodLabel' => $s->methodLabel(),
            'paidOn' => $s->receipt_paid_on ? Jalali::date($s->receipt_paid_on) : null,
            'note' => $s->review_note,
            'hasReceipt' => filled($s->receipt_path),
            'receiptUrl' => filled($s->receipt_path)
                ? route('api.system.subscriptions.receipt', $s)
                : null,
            'reviewedBy' => $s->reviewer?->name,
            'reviewedAt' => $s->reviewed_at ? Jalali::dateTime($s->reviewed_at) : null,
            'endsAt' => $s->ends_at ? Jalali::date($s->ends_at) : null,
            'createdAt' => Jalali::dateTime($s->created_at),
        ];
    }
}
