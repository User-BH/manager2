<?php

namespace App\Http\Requests;

use App\Enums\MessageAudience;
use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
use Illuminate\Validation\Rule;

/**
 * پیامِ تازه در پیام‌رسانِ داخلی.
 *
 * از `Api/MessengerController.php::store()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StoreMessageRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * متن وقتی پیوست یا نظرسنجی هست اختیاری می‌شود.
             *
             * عمداً `required_without_all` و نه یک شرطِ PHP روی `$this`:
             * تولیدکننده‌ی OpenAPI این FormRequest را بدونِ درخواستِ واقعی
             * می‌سازد و هر تماسی با `hasFile()` آنجا کرش می‌کند. قاعده‌ی
             * اعلانی هم برای اسپک خوانا می‌ماند.
             */
            'body' => ['required_without_all:attachment,poll_question', 'nullable', 'string', 'max:1000'],

            /*
             * مخاطب (R23) — فقط مدیر می‌تواند تعیینش کند و خودِ کنترلر این را
             * اعمال می‌کند. اینجا صرفاً شکلِ ورودی سنجیده می‌شود.
             */
            'audience' => ['nullable', Rule::enum(MessageAudience::class)],

            /*
             * `exists` خام به مجتمع محدود نیست و `ComplexScope` روی کوئریِ
             * اعتبارسنجی اعمال نمی‌شود — همان درسِ `StoreResidentRequest`.
             * بدونِ این قید، شناسه‌ی واحدِ مجتمعِ دیگری هم پذیرفته می‌شد.
             */
            'unit_ids' => ['nullable', 'array', 'max:200'],
            /*
             * پیوست (R23b) — همان فهرستِ مجازِ رسیدها. SVG عمداً نیست:
             * کلاسیک‌ترین راهِ XSS از مسیر آپلود (R19).
             */
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],

            // نظرسنجی: سوال بدونِ گزینه بی‌معناست، پس با هم لازم می‌شوند
            'poll_question' => ['nullable', 'string', 'max:255'],
            'poll_options' => ['nullable', 'array', 'min:2', 'max:10', 'required_with:poll_question'],
            'poll_options.*' => ['required', 'string', 'max:120'],

            /*
             * تنظیماتِ حرفه‌ای (R24). همه اختیاری‌اند و نبودنشان همان
             * رفتارِ ساده‌ی R23b را می‌دهد، پس نظرسنجیِ سریع هنوز با یک
             * سوال و دو گزینه ساخته می‌شود.
             */
            'poll_voter_scope' => ['nullable', Rule::enum(PollVoterScope::class)],
            'poll_weight_mode' => ['nullable', Rule::enum(PollWeightMode::class)],
            'poll_quorum_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'poll_allow_change' => ['nullable', 'boolean'],
            'poll_closes_at' => ['nullable', 'date', 'after:now'],

            'unit_ids.*' => [
                Rule::exists('units', 'id')->where('complex_id', $this->currentComplexId()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'متن پیام را وارد کنید.',
            'body.required_without_all' => 'متن پیام را وارد کنید.',
            'body.max' => 'پیام بیش از حد طولانی است.',
        ];
    }
}
