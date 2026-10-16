<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\RefundCheckoutRequest;
use App\Http\Resources\SubscriptionReviewResource;
use App\Models\CheckoutIntent;
use App\Models\Subscription;
use App\Services\Subscription\ComplexPurchase;
use App\Support\Jalali;
use App\Support\PrivateFiles;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * اشتراک‌های مجتمع‌ها از دیدِ ادمینِ کل — **فقط دیدن** (مرحلهٔ ۱۲ · فاز ۲؛ تصمیمِ قطعیِ کارفرما).
 *
 * تا ۱۲/۲ این‌جا رسیدِ واریز تأیید یا رد می‌شد و دکمه‌ی «تأیید» هر اشتراکِ `pending` را — حتی تمدیدِ **آنلاینِ**
 * پرداخت‌نشده — با کدِ رهگیریِ ساختگی فعال می‌کرد (۱۲/۰ بازتولیدش کرد). حالا خرید و تمدید فقط آنلاین‌اند و فقط
 * تأییدِ مستقلِ درگاه در سرور فعالشان می‌کند (`SubscriptionRenewal`، `ComplexPurchase`). ردیفِ `pending` یعنی
 * «در انتظارِ درگاه» و تکلیفش را `payments:reconcile` با پرسیدن از خودِ درگاه روشن می‌کند، نه ادمین. مسیرهای
 * قدیمیِ تأیید و رد یک چرخه‌ی استقرار ۴۱۰ می‌دهند (`RetiredEndpointController`). رسیدهای پیشین برای سابقه دیده
 * می‌شوند.
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
            /*
             * خریدِ مجتمعِ تازه که به آدم نیاز دارد (۱۲/۲): پرداخت‌شده‌ای که مجتمعش ساخته نشد (ثبتِ بازپرداخت) و
             * قصدی که بازبینیِ خودکار هنوز نتوانسته تکلیفش را روشن کند.
             */
            'checkouts' => CheckoutIntent::with('user:id,name,phone')
                ->where(fn ($q) => $q->where('status', CheckoutIntent::PAID)
                    ->orWhere(fn ($p) => $p->where('status', CheckoutIntent::PENDING)->whereNotNull('authority')
                        ->where('created_at', '<', now()->subMinutes(30))))
                ->orderBy('id')
                ->get()
                ->map(fn (CheckoutIntent $i) => [
                    'id' => $i->public_id,
                    'status' => $i->status,
                    'statusLabel' => $i->status === CheckoutIntent::PAID ? 'پرداخت‌شده، ساخته‌نشده' : 'در انتظارِ درگاه',
                    'buyerName' => $i->user?->name,
                    'buyerPhone' => $i->user?->phone,
                    'complexName' => $i->complex_name,
                    'planName' => $i->plan_name,
                    'amountLabel' => Jalali::money($i->amount),
                    'trackingCode' => $i->tracking_code,
                    'reason' => $i->failure_reason,
                    'createdAt' => Jalali::dateTime($i->created_at),
                ])->values(),
        ]);
    }

    /**
     * ثبتِ بازپرداختِ «پرداخت شد ولی مجتمع ساخته نشد» — پس از برگرداندنِ پول در بانک (۱۲/۲). فقط ثبت است: هیچ چیزی
     * فعال یا ساخته نمی‌شود و پس از آن همان پرداخت هرگز مجتمع نمی‌شود. با تأییدِ تازه‌ی رمز.
     */
    public function refund(RefundCheckoutRequest $request, CheckoutIntent $intent, ComplexPurchase $purchase): JsonResponse
    {
        $done = $purchase->refund($intent, $request->user(), $request->validated('reference'));

        return response()->json(['message' => 'بازپرداخت ثبت شد.', 'status' => $done->status]);
    }

    /** رسیدِ اشتراک‌های پیش از ۱۲/۲ (سابقه) روی دیسکِ خصوصی است و فقط از این مسیر سرو می‌شود. */
    public function receipt(Subscription $subscription): StreamedResponse
    {
        abort_if(
            ! $subscription->receipt_path
                || ! PrivateFiles::disk()->exists($subscription->receipt_path),
            404,
        );

        // نوعِ محتوا صریح فرستاده می‌شود، نه حدسِ لحظه‌ی سرو (R19)
        return Uploads::serve($subscription->receipt_path);
    }

    /**
     * شکلِ خروجی حالا در `SubscriptionReviewResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Subscription $s): array
    {
        return (new SubscriptionReviewResource($s))->toArray(request());
    }
}
