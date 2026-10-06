<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Support\PersianName;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * بازخوردِ صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ هیچ چیزی به اعتمادِ فرمِ مرورگر معتبر فرض نمی‌شود: همه‌ی سقف‌های طول،
 * نوع، شماره و شمارِ پیوند اینجا دوباره سنجیده می‌شوند.
 *
 * ─── پاک‌سازی ──────────────────────────────────────────────────────────────
 * متن **همیشه متن** می‌ماند و جایی به‌صورتِ HTML رندر نمی‌شود (React آن را
 * escape می‌کند). اینجا فقط نویسه‌های نادیدنیِ مخرب و فاصله‌ی دو سر برداشته
 * می‌شوند (جزئیات در `prepareForValidation`).
 */
class StoreFeedbackRequest extends BaseFormRequest
{
    /** بیش از این پیوند در یک پیام، نشانه‌ی تبلیغ است نه بازخورد. */
    public const MAX_LINKS = 2;

    public const NAME_MAX = 60;

    /**
     * نام فقط حرفِ فارسی، فاصله و نیم‌فاصله (مرحلهٔ ۸ · فاز ۵ · بندِ ۹) — همان
     * `PERSIAN_NAME_PATTERN` ِ `inputFilters.ts`. «ي/ك/ى» پیش از سنجش «ی/ک» می‌شوند.
     */
    public const PERSIAN_NAME = PersianName::PATTERN;

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'name' => ['nullable', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::PERSIAN_NAME],
            // اجباری (مرحلهٔ ۸ · فاز ۵ · بندِ ۱۰)؛ شکلش در after() با Phone::isValidMobile
            'phone' => ['required', 'string'],
            // تله‌ی ربات (honeypot): برای انسان دیده نمی‌شود؛ بررسی‌اش در کنترلر است
            'website' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $phone = $this->input('phone');
                if (is_string($phone) && ! Phone::isValidMobile($phone)) {
                    $validator->errors()->add('phone', 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.');
                }

                $message = (string) $this->input('message', '');
                if (preg_match_all('~https?://|www\.~i', $message) > self::MAX_LINKS) {
                    $validator->errors()->add('message', 'حداکثر '.self::MAX_LINKS.' پیوند در پیام مجاز است.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * ⚠️ نه همه‌ی `\p{C}`: نیم‌فاصله (U+200C) و اتصال‌دهنده (U+200D) هم در
         * همان رده‌اند و بی‌آن‌ها متنِ فارسی می‌شکند. فقط نویسه‌های کنترلی
         * (جز سطرِ تازه و تب) و نویسه‌های بازنویسیِ جهت (U+202A–U+202E،
         * U+2066–U+2069) — که می‌توانند متنی را در صفِ ادمین وارونه نشان
         * دهند — برداشته می‌شوند.
         */
        $clean = fn ($value) => is_string($value)
            ? trim((string) preg_replace('/(?![\n\t])\p{Cc}|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value))
            : $value;

        $this->merge([
            'message' => $clean($this->input('message')),
            'name' => is_string($name = $clean($this->input('name')))
                ? (trim((string) preg_replace('/ {2,}/', ' ', strtr($name, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']))) ?: null)
                : $name,
            'phone' => is_string($this->input('phone')) && trim($this->input('phone')) !== ''
                ? Phone::normalize($this->input('phone'))
                : null,
        ]);
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'نام فقط با حروف فارسی نوشته شود.',
            'phone.required' => 'شماره موبایل را وارد کنید.',
        ];
    }

    public function attributes(): array
    {
        return [
            'category' => 'نوعِ پیام',
            'message' => 'متنِ پیام',
            'name' => 'نام',
            'phone' => 'شماره موبایل',
        ];
    }
}
