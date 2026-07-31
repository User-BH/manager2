<?php

namespace App\Http\Requests;

use App\Models\Complex;
use App\Support\ComplexResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * پایه‌ی همه‌ی FormRequestها.
 *
 * ─── چرا FormRequest و نه `$request->validate()` در کنترلر؟ ─────────────────
 * سه دلیلِ عملی، نه سلیقه‌ای:
 *
 *   ۱. **قابلِ آزمودن بی‌HTTP.** قواعد یک کلاسِ مستقل‌اند و می‌شود مستقیم
 *      تستشان کرد، بی‌آنکه کلِ چرخه‌ی درخواست را بالا آورد.
 *   ۲. **قابلِ استفاده‌ی دوباره.** `store` و `update` معمولاً ۹۰٪ قواعدِ
 *      مشترک دارند؛ در کنترلر این یعنی کپی‌وپیست و واگرا شدنشان با گذشتِ زمان.
 *   ۳. **کنترلر کوتاه می‌ماند.** وقتی ۲۰ خط قاعده از کنترلر بیرون می‌رود،
 *      خودِ اکشن در یک نگاه خوانده می‌شود.
 *
 * ─── `authorize()` اینجا `true` است ────────────────────────────────────────
 * مجوزدهی کارِ Policyهاست (`app/Policies`). اگر اینجا هم بررسی کنیم، دو جای
 * متفاوت مسئولِ یک چیز می‌شوند و دیر یا زود از هم واگرا می‌شوند.
 */
abstract class BaseFormRequest extends FormRequest
{
    /**
     * قواعدِ اعتبارسنجی.
     *
     * صریح اعلام می‌شود (و نه فقط ارث‌بری از FormRequest) تا هم هر زیرکلاس
     * مجبور به تعریفش باشد و هم تحلیلِ ایستا و تولیدکننده‌ی OpenAPI بتوانند
     * رویش حساب کنند.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * شناسه‌ی مجتمعِ جاری — برای قواعدی که باید مستأجرمحور باشند.
     *
     * ─── چرا این متد لازم شد ───────────────────────────────────────────────
     * قواعدی مثل «این واحد باید وجود داشته باشد» بدونِ محدودکردن به مجتمع،
     * **نشتِ بین‌مستأجری** می‌سازند: مدیرِ یک مجتمع می‌تواند شناسه‌ی واحدِ
     * مجتمعِ دیگری را بفرستد و اعتبارسنجی قبولش کند.
     *
     * در کنترلر این با متغیرِ محلیِ `$complex` انجام می‌شد. با انتقالِ قواعد
     * به FormRequest آن متغیر دیگر در دسترس نیست، پس از همان منبعی خوانده
     * می‌شود که خودِ کنترلر می‌خواند (`ComplexResolver`) — نه یک راهِ دوم که
     * ممکن است روزی با آن واگرا شود.
     */
    protected function currentComplexId(): ?int
    {
        return ComplexResolver::idFor($this->user());
    }

    /** خودِ مجتمعِ جاری، وقتی قاعده به بیش از شناسه نیاز دارد. */
    protected function currentComplex(): ?Complex
    {
        $id = $this->currentComplexId();

        return $id ? Complex::find($id) : null;
    }

    /**
     * نام‌های فارسیِ فیلدها.
     *
     * بدونِ این، پیامِ خطا می‌شود «فیلد title الزامی است» که برای کاربرِ
     * فارسی‌زبان بی‌معناست. هر FormRequest این را برای فیلدهای خودش پر می‌کند.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }
}
