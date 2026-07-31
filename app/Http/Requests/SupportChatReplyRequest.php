<?php

namespace App\Http\Requests;

/**
 * پیامِ کاربر در چتِ پشتیبانیِ صفحه‌ی فرود.
 *
 * از `Api/SupportChatController.php::reply()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class SupportChatReplyRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'پیامتان را بنویسید.',
            'message.max' => 'پیام بیش از حد طولانی است؛ کوتاه‌تر بپرسید تا بهتر بتوانم کمک کنم.',
        ];
    }
}
