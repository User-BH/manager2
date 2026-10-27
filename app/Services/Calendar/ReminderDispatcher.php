<?php

namespace App\Services\Calendar;

use App\Models\ComplexMembership;
use App\Models\Reminder;
use App\Notifications\ReminderDueNotification;
use App\Services\Account\ComplexMembers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * فرستادنِ یادآورهایی که لحظه‌شان رسیده (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۸۶ و ۱۰۰؛ تصمیمِ ۲.۳-۸).
 *
 * هر دقیقه از زمان‌بندِ سرور (`reminders:dispatch`). برای هر یادآورِ سررسیده، **در یک تراکنش و زیرِ قفلِ ردیف**: اعلانِ
 * همان نمونه، و جلو بردنِ نشانگر به نمونه‌ی بعدی. پس اجرای هم‌زمان یا تکراری (دو کارگر، اجرای دوباره پس از خطا) یک
 * نمونه را دو بار نمی‌فرستد: کارگرِ دوم ردیفِ قفل‌شده را با نشانگرِ جلورفته می‌بیند و کاری نمی‌کند.
 *
 * نمونه‌ای که لحظه‌اش در خاموشیِ سرور گذشت، فقط یک بار در بازگشت فرستاده می‌شود و سری از «اکنون» جلو می‌رود — نه سیلی
 * از یادآورهای کهنه. یادآوری که زمینه‌اش دیگر معتبر نیست (عضویت پایان یافته، حساب غیرفعال) فرستاده نمی‌شود ولی سری جلو
 * می‌رود تا هر دقیقه دوباره سنجیده نشود.
 */
final class ReminderDispatcher
{
    /** در هر اجرا؛ بقیه در دقیقه‌ی بعد */
    private const BATCH = 500;

    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        $due = Reminder::whereNotNull('next_remind_at')->where('next_remind_at', '<=', $now)
            ->orderBy('next_remind_at')->limit(self::BATCH)->pluck('id');

        foreach ($due as $id) {
            $sent += $this->deliver((int) $id, $now);
        }

        return $sent;
    }

    /**
     * یک شناسه از فهرستِ «سررسیده» — زیرِ قفلِ ردیف دوباره سنجیده می‌شود، چون فهرست ممکن است کهنه باشد: کارگرِ هم‌زمان
     * همین حالا فرستاد و نشانگر را جلو برد، یا ویرایش عوضش کرد. عمومی است تا آزمون همان فهرستِ کهنه را بازی کند.
     */
    public function deliver(int $id, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($id, $now): int {
            $reminder = Reminder::with(['skips', 'user'])->lockForUpdate()->find($id);

            if ($reminder === null || $reminder->next_remind_at === null || $reminder->next_remind_at->gt($now)) {
                return 0;
            }

            $occurrence = (string) $reminder->next_occurrence?->toDateString();
            $deliver = $this->stillInContext($reminder);

            if ($deliver) {
                $reminder->user->notify(new ReminderDueNotification($reminder, $occurrence));
                $reminder->forceFill([
                    'notified_count' => $reminder->notified_count + 1,
                    'last_notified_at' => $now,
                ]);
            }

            $reminder->reschedule($now);
            $reminder->save();

            return $deliver ? 1 : 0;
        }, attempts: 3);
    }

    /** زمینه‌ی یادآور هنوز مالِ صاحبش است؟ سوپر ادمین — سکو؛ بقیه — عضویتِ **فعال** در همان مجتمع */
    private function stillInContext(Reminder $reminder): bool
    {
        $user = $reminder->user;

        if (! $user->is_active) {
            return false;
        }

        return $reminder->complex_id === null
            ? $user->isSuperAdmin()
            : ComplexMembers::has($user, $reminder->complex_id, ComplexMembership::ROLES);
    }
}
