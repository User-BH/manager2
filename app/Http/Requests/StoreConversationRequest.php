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

        return [
            'type' => ['required', Rule::in([Conversation::UNIT, Conversation::GROUP])],
            'unit_id' => ['required_if:type,unit', 'nullable', 'integer', $unitInComplex],
            'title' => ['required_if:type,group', 'nullable', 'string', 'max:120'],
            'unit_ids' => ['required_if:type,group', 'nullable', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['integer', $unitInComplex],
            'members_can_post' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['unit_id' => 'واحد', 'title' => 'نامِ گروه', 'unit_ids' => 'واحدها'];
    }
}
