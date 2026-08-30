<?php

namespace App\Http\Requests;

use App\Models\Bill;

/**
 * رسیدِ پرداختِ قبض که ساکن آپلود می‌کند.
 *
 * از `Api/PaymentController.php::uploadReceipt()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UploadPaymentReceiptRequest extends BaseFormRequest
{
    /**
     * ⚠️ استثنای آگاهانه بر قاعده‌ی «`authorize()` همیشه true» (R53).
     *
     * ─── نشتی که تستِ حمله پیدا کرد ─────────────────────────────────────
     * اعتبارسنجی **پیش از** بدنه‌ی کنترلر اجرا می‌شود، پس ساکنی که شناسه‌ی
     * قبضِ همسایه را در URL می‌گذاشت به‌جای ۴۰۳، **۴۲۲** می‌گرفت. دو چیز
     * از همین ۴۲۲ بیرون می‌آمد:
     *
     *   ① وجودِ آن قبض تایید می‌شد (۴۲۲ در برابرِ ۴۰۴ برای شناسه‌ی نبوده).
     *   ② `maxAmount()` سقف را از **مانده‌ی همان قبض** می‌سازد، پس با
     *      چند تلاش و جست‌وجوی دودویی روی `amount` می‌شد بدهیِ همسایه را
     *      تخمین زد.
     *
     * ─── چرا این قاعده‌ی معماری را نمی‌شکند ──────────────────────────────
     * `BaseFormRequest` می‌گوید مجوزدهی کارِ Policy است تا دو جا مسئولِ یک
     * چیز نشوند. اینجا **قاعده‌ی تازه‌ای نوشته نمی‌شود**: همان
     * `BillPolicy::pay` صدا زده می‌شود که کنترلر هم صدایش می‌زند. تنها
     * چیزی که عوض می‌شود **ترتیب** است — مجوز پیش از اعتبارسنجی.
     */
    public function authorize(): bool
    {
        $bill = $this->route('bill');

        return $bill instanceof Bill && $this->user()?->can('pay', $bill) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1000', 'max:'.$this->maxAmount()],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.max' => 'مبلغ رسید نمی‌تواند بیشتر از مانده‌ی قبض باشد.',
            'paid_on.before_or_equal' => 'تاریخ واریز نمی‌تواند در آینده باشد.',
            'receipt.mimes' => 'فایل رسید باید تصویر (jpg/png) یا PDF باشد.',
            'receipt.max' => 'حجم فایل رسید نباید از ۴ مگابایت بیشتر باشد.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'مبلغ',
            'paid_on' => 'تاریخ واریز',
            'receipt' => 'فایل رسید',
        ];
    }

    /**
     * سقفِ مبلغِ رسید: کمی بیشتر از مانده‌ی قبض (برای سرراست‌کردنِ مبلغ یا
     * کارمزد)، ولی نه هر عددی.
     *
     * پیش از این هیچ سقفی نبود و ساکن می‌توانست رسیدی با مبلغِ نجومی ثبت کند
     * که تاییدِ سهویِ آن، مانده‌ی واحد را منفی و اعتبارِ ساختگی می‌ساخت.
     *
     * قبض از پارامترِ مسیر خوانده می‌شود، پس قاعده و زمینه‌اش کنارِ هم‌اند.
     */
    private function maxAmount(): int
    {
        $bill = $this->route('bill');

        return $bill instanceof Bill ? max(1000, (int) ceil($bill->remaining() * 1.2)) : 1000;
    }
}
