<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Feedback;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;

/**
 * دریافتِ بازخورد از صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴).
 *
 * عمومی است چون فرستنده ممکن است هنوز حسابی نداشته باشد — درست مثلِ چتِ
 * پشتیبانی. محافظت به‌جای احراز هویت:
 *
 *   ① CSRF (گروهِ web، مثلِ همه‌ی مسیرهای API)
 *   ② سقفِ نرخ (`throttle:feedback`)
 *   ③ تله‌ی ربات: فیلدِ پنهانِ `website`
 *   ④ ارسالِ دوباره‌ی همان متن از همان فرستنده در ۲۴ ساعت ردیفِ تازه نمی‌سازد
 *   ⑤ همه‌ی سقف‌ها در `StoreFeedbackRequest`
 */
class FeedbackController extends Controller
{
    public const RECEIVED = 'پیامِ شما رسید. ممنون که وقت گذاشتید.';

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $data = $request->validated();

        /*
         * ⚠️ ربات همان پاسخِ موفق را می‌گیرد، نه خطا: خطا به سازنده‌اش می‌گوید
         * کدام فیلد تله است و دورش بزند. چیزی هم ذخیره نمی‌شود.
         */
        if (filled($data['website'] ?? null)) {
            return response()->json(['message' => self::RECEIVED], 201);
        }

        $sender = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $digest = hash('sha256', PersianText::normalize($data['message']));

        /*
         * دوبار-کلیک، تلاشِ دوباره‌ی شبکه، یا فرستادنِ همان متن چند بار:
         * ردیفِ دوم ساخته نمی‌شود ولی کاربر همان پاسخِ موفق را می‌بیند.
         */
        $duplicate = Feedback::query()
            ->where('sender_hash', $sender)
            ->where('message_hash', $digest)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if (! $duplicate) {
            Feedback::create([
                'category' => $data['category'],
                'message' => $data['message'],
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'user_id' => $request->user()?->id,
                'status' => FeedbackStatus::New,
                'message_hash' => $digest,
                'sender_hash' => $sender,
            ]);
        }

        return response()->json(['message' => self::RECEIVED], 201);
    }
}
