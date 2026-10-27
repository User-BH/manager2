<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use Illuminate\Validation\Rule;

/**
 * حذفِ یادآور (۱۳/۷؛ ایرادات ۱۰، بندهای ۸۲ و ۹۱) — همان سه دامنه‌ی ویرایش؛ بی دامنه «کلِ سری».
 */
class DeleteReminderRequest extends BaseFormRequest
{
    /** مالکیت پیش از اعتبارسنجی — همان دلیلِ `SaveReminderRequest::authorize` */
    public function authorize(): bool
    {
        $reminder = $this->route('reminder');

        return $reminder instanceof Reminder && $reminder->user_id === $this->user()?->id;
    }

    protected function failedAuthorization(): never
    {
        abort(404);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(SaveReminderRequest::SCOPES)],
            'occurrence' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function scope(): string
    {
        return (string) ($this->validated('scope') ?? 'all');
    }

    public function occurrence(): ?string
    {
        $value = $this->validated('occurrence');

        return $value === null ? null : (string) $value;
    }
}
