<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Validation\Rule;

/**
 * گفت‌وگوی تازه (مرحلهٔ ۶ · فاز ۱۲) — فقط مدیریت: رشته‌ی خصوصی با یک واحد، یا
 * گروهی از واحدها. کانال و رشته‌ی خودِ ساکن خودبه‌خود ساخته می‌شوند.
 */
class StoreConversationRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->role->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unitInComplex = Rule::exists('units', 'id')->where('complex_id', $this->currentComplexId());

        /*
         * ۱۰/۹: گفت‌وگوی خصوصی با **یک عضو** (بندِ ۳۷)، نه رشته‌ی مشترکِ واحد — آن بایگانی است و تازه ساخته نمی‌شود.
         * عضو یعنی مالک یا مستأجرِ **فعالِ** همین مجتمع، از عضویت؛ عضوِ مجتمعِ دیگر یا رفته ۴۲۲ است.
         */
        $memberHere = Rule::exists('complex_memberships', 'user_id')
            ->where('complex_id', $this->currentComplexId())
            ->where('status', 'active')
            ->whereIn('role', ['owner', 'tenant']);

        return [
            'type' => ['required', Rule::in([Conversation::USER, Conversation::GROUP])],
            'user_id' => ['required_if:type,user', 'nullable', 'integer', $memberHere],
            'title' => ['required_if:type,group', 'nullable', 'string', 'max:120'],
            'unit_ids' => ['required_if:type,group', 'nullable', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['integer', $unitInComplex],
            'members_can_post' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['user_id' => 'عضو', 'title' => 'نامِ گروه', 'unit_ids' => 'واحدها'];
    }
}
