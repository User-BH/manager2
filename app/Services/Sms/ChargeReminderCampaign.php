<?php

namespace App\Services\Sms;

use App\Enums\BillStatus;
use App\Enums\NotificationChannelKey;
use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\SmsCampaign;
use App\Models\User;
use App\Support\Jalali;
use App\Support\NotificationPreferences;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * یادآوریِ پیامکیِ شارژ — تنها پیامکی جز کدِ ورود (R27).
 *
 * ─── قاعده‌ی محصول، کلمه‌به‌کلمه ────────────────────────────────────────────
 * «مدیرِ مجتمع در ماه فقط یک بار سهمیه دارد که ارسالِ پیامک به ساکنین را
 * بزند تا شارژ و بدهی‌شان را بدهند، و زمانی می‌تواند بزند که تمامِ هزینه‌ها
 * را در سامانه وارد کرده باشد.»
 *
 * سه قید در همین جمله است و هر سه اینجا اعمال می‌شوند:
 *
 *   ۱. **ماهی یک بار.** یکتاییِ (مجتمع، دوره) در دیتابیس؛ نه شمارنده و نه
 *      کش، چون هر دو می‌توانند سهمیه‌ی اضافه بدهند.
 *   ۲. **به ساکنین، برای بدهی.** فقط واحدهایی که واقعاً بدهی دارند. ساکنِ
 *      تسویه‌کرده پیامک نمی‌گیرد — وگرنه همان کسی که سرِ وقت پرداخت کرده،
 *      مزاحمت می‌بیند.
 *   ۳. **پس از ثبتِ هزینه‌ها.** «تمامِ هزینه‌ها» را نمی‌شود مستقیم سنجید
 *      (سامانه نمی‌داند مدیر چند فاکتور در جیبش دارد)، ولی نتیجه‌اش
 *      سنجیدنی است: قبضِ دوره باید **صادر شده** باشد و هزینه‌ای برای آن
 *      دوره ثبت شده باشد. پیش از این دو، مبلغی وجود ندارد که ساکن بپردازد
 *      و پیامک فقط هزینه و بی‌اعتمادی می‌سازد.
 */
class ChargeReminderCampaign
{
    /** سقفِ گیرنده در یک کارزار — بیش از این باید صف و گزارشِ جدا داشته باشد. */
    private const MAX_RECIPIENTS = 500;

    public function __construct(
        private readonly SmsManager $sms,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * وضعیتِ سهمیه و پیش‌نمایشِ گیرندگان.
     *
     * همان چیزی که کنترلر به رابط می‌دهد و همان چیزی که `send()` بررسی
     * می‌کند — یک منبع، تا دکمه‌ای که فعال دیده می‌شود واقعاً کار کند.
     *
     * @return array<string, mixed>
     */
    public function status(Complex $complex): array
    {
        $period = Jalali::currentPeriod();
        $used = $this->usedCampaign($complex, $period);
        $blocker = $this->blockReason($complex, $period);
        $recipients = $this->recipients($complex, $period);

        return [
            'period' => $period,
            'periodLabel' => Jalali::periodLabel($period),
            'quotaUsed' => $used !== null,
            'usedAt' => $used ? Jalali::dateTime($used->created_at) : null,
            'usedBy' => $used?->sender?->name,
            'lastRecipients' => $used?->recipients,
            'blockReason' => $blocker,
            'canSend' => $blocker === null,
            'recipientCount' => $recipients->count(),
            'totalDebt' => (float) $recipients->sum('debt'),
            'preview' => $recipients->isNotEmpty()
                ? $this->message($complex, $recipients->first())
                : null,
        ];
    }

    /**
     * ارسال. کلِ کار در یک تراکنش شروع می‌شود تا ردیفِ سهمیه **پیش از**
     * اولین پیامک ثبت شود.
     *
     * ─── چرا ترتیب مهم است ─────────────────────────────────────────────────
     * اگر اول پیامک می‌فرستادیم و بعد ردیف را می‌نوشتیم، یک خطای وسطِ کار
     * یعنی نصفِ ساکنین پیامک گرفته‌اند و سهمیه هم مصرف نشده — پس مدیر
     * دوباره می‌زند و همان‌ها دو بار پیامک می‌گیرند. حالا بدترین حالت این
     * است که سهمیه بسوزد و پیامکی نرود، که خیلی کم‌ضررتر است.
     *
     * @return array<string, mixed>
     */
    public function send(Complex $complex, User $manager): array
    {
        $period = Jalali::currentPeriod();

        if ($reason = $this->blockReason($complex, $period)) {
            throw DomainException::invalid($reason, 'sms.not_allowed');
        }

        $recipients = $this->recipients($complex, $period);

        if ($recipients->isEmpty()) {
            throw DomainException::invalid(
                'هیچ واحد بدهکاری برای ارسال پیامک وجود ندارد.',
                'sms.no_recipients',
            );
        }

        /*
         * ردیفِ سهمیه با `create` و نه `firstOrCreate`: اگر دو مدیر هم‌زمان
         * بزنند، قیدِ یکتای دیتابیس دومی را رد می‌کند و ما آن را به یک پیامِ
         * روشن تبدیل می‌کنیم، نه اینکه بی‌سروصدا دو کارزار بسازیم.
         */
        try {
            $campaign = DB::transaction(fn () => SmsCampaign::create([
                'complex_id' => $complex->id,
                'period' => $period,
                'sent_by' => $manager->id,
                'recipients' => $recipients->count(),
                'template' => $this->message($complex, $recipients->first()),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw DomainException::invalid(
                'سهمیه‌ی پیامک این ماه هم‌اکنون مصرف شد.',
                'sms.quota_used',
            );
        }

        $delivered = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            $ok = $this->sms->send($recipient['phone'], $this->message($complex, $recipient));
            $ok ? $delivered++ : $failed++;
        }

        $campaign->update(['delivered' => $delivered, 'failed' => $failed]);

        return [
            'recipients' => $recipients->count(),
            'delivered' => $delivered,
            'failed' => $failed,
        ];
    }

    /**
     * چرا الان نمی‌شود فرستاد — یا `null` اگر می‌شود.
     *
     * پیام برمی‌گرداند و نه `false`، چون مدیر باید بداند **چه کاری** باید
     * بکند تا دکمه فعال شود؛ دکمه‌ی خاموشِ بی‌دلیل فقط تماس با پشتیبانی
     * می‌سازد.
     */
    public function blockReason(Complex $complex, string $period): ?string
    {
        if ($this->usedCampaign($complex, $period) !== null) {
            return 'سهمیه‌ی پیامک '.Jalali::periodLabel($period).' مصرف شده است.';
        }

        $hasExpenses = Expense::where('complex_id', $complex->id)
            ->where('period', $period)
            ->exists();

        if (! $hasExpenses) {
            return 'ابتدا هزینه‌های '.Jalali::periodLabel($period).' را در سامانه ثبت کنید.';
        }

        $hasBills = Bill::where('complex_id', $complex->id)
            ->where('period', $period)
            ->exists();

        if (! $hasBills) {
            return 'ابتدا قبض‌های '.Jalali::periodLabel($period).' را صادر کنید.';
        }

        return null;
    }

    /**
     * واحدهای بدهکار و شماره‌ی ساکنِ جاری‌شان.
     *
     * ─── چه کسانی حذف می‌شوند ──────────────────────────────────────────────
     * • واحدی که قبضِ این دوره‌اش تسویه شده،
     * • کاربری که خودش این پیامک را خاموش کرده (R27، تنظیماتِ اعلان)،
     * • و کاربرِ غیرفعال یا بی‌شماره.
     *
     * @return Collection<int, array{phone: string, unit: string, debt: float, user: User}>
     */
    public function recipients(Complex $complex, string $period): Collection
    {
        $bills = Bill::where('complex_id', $complex->id)
            ->where('period', $period)
            ->where('status', '!=', BillStatus::Paid->value)
            ->with(['unit.residents' => fn ($q) => $q->wherePivot('is_current', true)])
            ->get();

        return $bills
            ->flatMap(function (Bill $bill) {
                $debt = (float) $bill->total_amount - (float) $bill->paid_amount;

                if ($debt <= 0 || ! $bill->unit) {
                    return [];
                }

                return $bill->unit->residents
                    ->filter(fn (User $user) => $user->is_active
                        && $user->phone
                        && $this->preferences->allows($user, NotificationChannelKey::SmsReminder))
                    ->map(fn (User $user) => [
                        'phone' => $user->phone,
                        'unit' => $bill->unit->unit_number,
                        'debt' => $debt,
                        'user' => $user,
                    ]);
            })
            // یک شماره دو بار پیامک نمی‌گیرد، حتی اگر دو واحد داشته باشد
            ->unique('phone')
            ->take(self::MAX_RECIPIENTS)
            ->values();
    }

    /**
     * متنِ پیامک.
     *
     * عمداً کوتاه است: متنِ فارسی یونیکد است و هر ۷۰ کاراکتر یک قبضِ جدا
     * حساب می‌شود، پس هر جمله‌ی اضافه هزینه‌ی واقعی دارد. رقم‌ها فارسی
     * می‌مانند چون گیرنده فارسی‌زبان است.
     *
     * @param  array{unit: string, debt: float}  $recipient
     */
    public function message(Complex $complex, array $recipient): string
    {
        return sprintf(
            '%s | واحد %s: بدهی %s تومان. لطفاً پرداخت کنید.',
            $complex->name,
            Jalali::digits($recipient['unit']),
            Jalali::money($recipient['debt']),
        );
    }

    private function usedCampaign(Complex $complex, string $period): ?SmsCampaign
    {
        return SmsCampaign::where('complex_id', $complex->id)
            ->where('period', $period)
            ->with('sender:id,name')
            ->first();
    }
}
