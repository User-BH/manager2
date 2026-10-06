<?php

namespace App\Http\Requests;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * شروعِ پرداختِ اشتراک از درگاه.
 *
 * از `SubscriptionCheckoutController.php::start()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class StartCheckoutRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],

            /*
             * خریدارِ «حالتِ اولیه» با همین خرید مجتمعش را می‌سازد (مرحلهٔ ۹ · فاز ۴)؛
             * برای مدیرِ فعلی (تمدید) بی‌معناست و نادیده گرفته می‌شود — مثلِ
             * `UploadSubscriptionReceiptRequest`. نوع اجباری است، نه پیش‌فرضِ «مسکونی».
             */
            'complex_name' => [$this->needsComplex() ? 'required' : 'nullable', 'string', 'max:150'],
            'complex_type' => [$this->needsComplex() ? 'required' : 'nullable', Rule::in(ComplexType::values())],
            'complex_address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plan' => 'پلن',
            'complex_name' => 'نام مجتمع',
            'complex_type' => 'نوع مجتمع',
            'complex_address' => 'نشانی مجتمع',
        ];
    }

    /** آیا این خرید باید مجتمع را هم بسازد؟ از واقعیت مشتق می‌شود، مثلِ `AccountState`. */
    private function needsComplex(): bool
    {
        $user = $this->user();

        return $user instanceof User && AccountState::of($user) === AccountState::Initial;
    }
}
