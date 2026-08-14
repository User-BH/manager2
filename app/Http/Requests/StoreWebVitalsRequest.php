<?php

namespace App\Http\Requests;

/**
 * گزارشِ Core Web Vitals از مرورگر (R38).
 *
 * ⚠️ ورودی از مرورگرِ کاربر می‌آید و **قابلِ اعتماد نیست**. `metric` و
 * `rating` به فهرستِ بسته محدود شده‌اند تا کسی نتواند جدول را با نامِ
 * دلخواه پر کند، و `value` سقف دارد چون عددِ نجومی فقط نمودار را خراب
 * می‌کند.
 */
class StoreWebVitalsRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'metrics' => ['required', 'array', 'min:1', 'max:8'],
            'metrics.*.name' => ['required', 'string', 'in:LCP,CLS,INP,TTFB,FCP'],
            'metrics.*.value' => ['required', 'numeric', 'min:0', 'max:600000'],
            'metrics.*.rating' => ['required', 'string', 'in:good,needs-improvement,poor'],
            'path' => ['required', 'string', 'max:191'],
            'device' => ['required', 'string', 'in:mobile,tablet,desktop'],
        ];
    }
}
