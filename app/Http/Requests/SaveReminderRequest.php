<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ساخت و ویرایشِ یادآور (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۷۷ تا ۸۵).
 *
 * تاریخ‌ها میلادیِ «Y-m-d» می‌آیند (رابط شمسی نشان می‌دهد و تبدیل می‌کند؛ `shared/lib/jalali.ts`). «زمانِ یادآوری» یا
 * «چند روز پیش از رویداد» است (`lead_days`) یا تاریخِ دلخواه (`remind_on`) که همین‌جا به همان تبدیل می‌شود — یک منبع
 * برای زمان‌بند. یادآورِ یک‌باره‌ای که لحظه‌اش گذشته پذیرفته نمی‌شود: پیامِ روشن، نه یادآوری که هرگز نمی‌رسد.
 */
class SaveReminderRequest extends BaseFormRequest
{
    public const SCOPES = ['all', 'this', 'following'];

    /**
     * ویرایش: مالکیت **پیش از** اعتبارسنجی — یادآورِ حسابِ دیگر همان ۴۰۴ ِ «نیست» را می‌گیرد، نه ۴۲۲ (وگرنه ۴۲۲/۴۰۴
     * وجودِ شناسه را لو می‌دهد؛ جاروی BOLA ِ ۱۳/۷). زمینه را کنترلر جدا می‌سنجد (`ReminderController::guard`).
     */
    public function authorize(): bool
    {
        $reminder = $this->route('reminder');

        return $reminder === null || ($reminder instanceof Reminder && $reminder->user_id === $this->user()?->id);
    }

    protected function failedAuthorization(): never
    {
        abort(404);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = CarbonImmutable::now(Jalali::zone())->toDateString();

        return [
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            // ساختن فقط برای امروز به بعد (بندِ ۷۷)؛ ویرایشِ نمونه‌ی گذشته‌ی یک سری هم تاریخِ آینده می‌خواهد
            'target_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:'.CarbonImmutable::parse($today)->addYears(10)->toDateString()],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:365', 'required_without:remind_on'],
            'remind_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:target_date'],
            'remind_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'repeat_every_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'repeat_end' => ['nullable', Rule::in(['none', 'until', 'count'])],
            'repeat_until' => ['nullable', 'required_if:repeat_end,until', 'date_format:Y-m-d', 'after_or_equal:target_date'],
            'repeat_count' => ['nullable', 'required_if:repeat_end,count', 'integer', 'min:2', 'max:1000'],
            // ویرایش و حذفِ نمونه‌ای از سری: «فقط همین» یا «همین و همه‌ی بعدی‌ها» یا «کلِ سری» (بندِ ۸۵)
            'scope' => ['nullable', Rule::in(self::SCOPES)],
            'occurrence' => ['nullable', 'required_if:scope,this', 'required_if:scope,following', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => 'موضوع', 'description' => 'توضیحات', 'target_date' => 'تاریخِ رویداد',
            'lead_days' => 'زمانِ یادآوری', 'remind_on' => 'تاریخِ یادآوری', 'remind_time' => 'ساعتِ یادآوری',
            'repeat_every_days' => 'فاصله‌ی تکرار', 'repeat_until' => 'پایانِ تکرار', 'repeat_count' => 'تعدادِ تکرار',
            'occurrence' => 'نمونه',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target_date.after_or_equal' => 'تاریخِ رویداد نمی‌تواند پیش از امروز باشد.',
            'remind_on.before_or_equal' => 'یادآوری باید پیش از رویداد یا همان روز باشد.',
            'remind_time.regex' => 'ساعتِ یادآوری را به شکلِ ۰۹:۳۰ وارد کنید.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $attributes = $this->attributesForModel();
            if ($attributes['lead_days'] > 365) {
                $validator->errors()->add('remind_on', 'یادآوری حداکثر ۳۶۵ روز پیش از رویداد است.');

                return;
            }

            // یک‌باره: لحظه‌ی یادآوری باید هنوز نرسیده باشد (سری از نخستین نمونه‌ی آینده شروع می‌کند)
            if ($attributes['repeat_every_days'] === null) {
                $at = CarbonImmutable::parse($attributes['target_date'].' '.$attributes['remind_time'], Jalali::zone())
                    ->subDays($attributes['lead_days']);
                if ($at->lte(CarbonImmutable::now())) {
                    $validator->errors()->add('remind_time', 'زمانِ یادآوری گذشته است؛ ساعت یا روزِ یادآوری را دیرتر بگذارید.');
                }
            }
        }];
    }

    /**
     * ستون‌های یادآور از ورودیِ معتبر: تاریخِ دلخواهِ یادآوری به «چند روز پیش» تبدیل می‌شود.
     *
     * @return array{title: string, description: string|null, target_date: string, lead_days: int, remind_time: string, repeat_every_days: int|null, repeat_until: string|null, repeat_count: int|null}
     */
    public function attributesForModel(): array
    {
        $target = (string) $this->input('target_date');
        $lead = $this->filled('remind_on')
            ? (int) CarbonImmutable::parse((string) $this->input('remind_on'), 'UTC')->diffInDays(CarbonImmutable::parse($target, 'UTC'))
            : (int) $this->input('lead_days', 0);
        $every = $this->filled('repeat_every_days') ? (int) $this->input('repeat_every_days') : null;
        $end = $every === null ? 'none' : (string) $this->input('repeat_end', 'none');

        return [
            'title' => trim((string) $this->input('title')),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'target_date' => $target,
            'lead_days' => $lead,
            'remind_time' => (string) $this->input('remind_time'),
            'repeat_every_days' => $every,
            'repeat_until' => $end === 'until' ? (string) $this->input('repeat_until') : null,
            'repeat_count' => $end === 'count' ? (int) $this->input('repeat_count') : null,
        ];
    }

    public function scope(): string
    {
        return (string) ($this->input('scope') ?: 'all');
    }
}
