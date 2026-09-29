<?php

namespace App\Http\Requests;

use App\Models\MessageReaction;
use Illuminate\Validation\Rule;

/** واکنش — فقط از فهرستِ ثابت (مرحلهٔ ۶ · فاز ۱۴). */
class ReactMessageRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['emoji' => ['required', 'string', Rule::in(MessageReaction::ALLOWED)]];
    }
}
