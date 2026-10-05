<?php

namespace App\Services\Feedback;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * «فرستنده‌ی این بازخورد عضوِ سایت است؟» — فقط سمتِ سرور (مرحلهٔ ۸ · فاز ۶ · بندِ ۱۴).
 *
 * ترتیبِ تشخیص:
 *   ۱. اگر هنگامِ فرستادن وارد شده بود (`user_id`)، **همان حساب** — حتی اگر
 *      شماره‌ای که در فرم نوشته مالِ کسِ دیگری باشد. پاسخ به خودِ نویسنده
 *      می‌رسد، نه به صاحبِ شماره‌ای که شاید اشتباه یا عمداً تایپ شده.
 *   ۲. وگرنه، حسابی که همین شماره را دارد (`users.phone` یکتاست).
 *
 * ⚠️ مرزِ حالتِ ۲، صادقانه: مهمان می‌تواند شماره‌ی کسِ دیگری را بنویسد. پاسخ
 * آن‌وقت به صاحبِ شماره می‌رسد — متنی که ادمین نوشته، نه آن مهمان. ادمین در
 * صف می‌بیند تشخیص از کدام راه بوده («با حساب» یا «با شماره»).
 *
 * برای فهرست یک‌جا و با دو پرس‌وجو، نه یکی برای هر سطر.
 */
class FeedbackMembership
{
    public const VIA_ACCOUNT = 'account';

    public const VIA_PHONE = 'phone';

    /** @return array{user: User, via: string}|null */
    public function recipientFor(Feedback $feedback): ?array
    {
        return $this->resolve(collect([$feedback]))[$feedback->id] ?? null;
    }

    /**
     * @param  Collection<int, Feedback>  $items
     * @return array<int, array{user: User, via: string}>
     */
    public function resolve(Collection $items): array
    {
        $byId = User::query()
            ->whereKey($items->pluck('user_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');
        $byPhone = User::query()
            ->whereIn('phone', $items->whereNull('user_id')->pluck('phone')->filter()->unique()->all())
            ->get()
            ->keyBy('phone');

        $result = [];
        foreach ($items as $item) {
            if ($item->user_id !== null && $byId->has($item->user_id)) {
                $result[$item->id] = ['user' => $byId->get($item->user_id), 'via' => self::VIA_ACCOUNT];
            } elseif ($item->user_id === null && $item->phone !== null && $byPhone->has($item->phone)) {
                $result[$item->id] = ['user' => $byPhone->get($item->phone), 'via' => self::VIA_PHONE];
            }
        }

        return $result;
    }
}
