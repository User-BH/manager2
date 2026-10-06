<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use Illuminate\Support\Facades\Gate;

/**
 * رسیدِ خریدِ اشتراک که مدیرِ مجتمع می‌فرستد.
 *
 * از `Api/SubscriptionController.php::uploadReceipt()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UploadSubscriptionReceiptRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],

        ];
    }

    /**
     * رسید فقط برای مجتمعِ موجود — پیش از هر اعتبارسنجی (مرحلهٔ ۹ · فاز ۵؛ F1، F2).
     *
     * ⚠️ اینجا و نه در کنترلر: قاعده‌های فیلد پیش از متدِ کنترلر اجرا می‌شوند و
     * حسابِ اولیه به‌جای «مجاز نیستید» خطای فیلد می‌گرفت.
     */
    public function authorize(): bool
    {
        Gate::authorize('uploadReceipt', Subscription::class);

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipt.mimes' => 'فایل رسید باید تصویر (jpg/png) یا PDF باشد.',
            'receipt.max' => 'حجم فایل رسید نباید از ۴ مگابایت بیشتر باشد.',
            'paid_on.before_or_equal' => 'تاریخ واریز نمی‌تواند در آینده باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plan' => 'پلن', 'paid_on' => 'تاریخ واریز', 'receipt' => 'فایل رسید',
        ];
    }
}
