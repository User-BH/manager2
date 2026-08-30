<?php

namespace App\Http\Requests;

use App\Support\SiteContent;

/**
 * تماس و شبکه‌های اجتماعیِ فوتر.
 *
 * از `Api/System/SiteSettingsController.php::update()` بیرون کشیده شد (R9b). قواعد کلمه‌به‌کلمه همان‌اند؛ این
 * جابه‌جایی عمداً رفتار را عوض نمی‌کند.
 */
class UpdateSiteSettingsRequest extends BaseFormRequest
{
    /**
     * ⚠️ رشته‌ی خالی به `null` تبدیل می‌شود، پیش از اعتبارسنجی.
     *
     * `nullable` فقط `null` را رد می‌کند، نه `''` را. و رابط برای شبکه‌ی
     * اجتماعیِ خاموش رشته‌ی خالی می‌فرستد — بدونِ این تبدیل، قاعده‌ی `url`
     * همان فرمِ عادی را ۴۲۲ می‌کرد.
     */
    protected function prepareForValidation(): void
    {
        $contact = $this->input('contact', []);

        if (is_array($contact) && ($contact['mapEmbedUrl'] ?? null) === '') {
            $contact['mapEmbedUrl'] = null;
        }

        $social = $this->input('social', []);

        if (is_array($social)) {
            foreach ($social as $index => $item) {
                if (is_array($item) && ($item['href'] ?? null) === '') {
                    $social[$index]['href'] = null;
                }
            }
        }

        $this->merge(['contact' => $contact, 'social' => $social]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /*
         * ⚠️ آدرس‌ها باید **طرحِ http/https** داشته باشند.
         *
         * ─── چرا این قاعده اینجا هم لازم بود ───────────────────────────
         * دقیقاً همین محافظت از قبل روی `href`ِ بنرِ تبلیغاتی هست، با این
         * توضیح که «`javascript:...` در href می‌نشست و صفحه‌ی فرودِ عمومی
         * به ناقل XSS تبدیل می‌شد».
         *
         * ولی همان رفع به **همسایه‌اش** نرسیده بود: لینکِ شبکه‌های
         * اجتماعی و آدرسِ نقشه در فوترِ **همان صفحه** رندر می‌شوند —
         * یکی در `<a href>` و دیگری در `<iframe src>` — و هیچ قیدی جز
         * «رشته باشد و از ۳۰۰ نویسه بیشتر نباشد» نداشتند.
         *
         * `iframe` بدتر هم هست: `data:text/html,...` در `src` یک سندِ
         * کاملِ مهاجم را داخلِ صفحه بالا می‌آورد.
         *
         * ⚠️ این محافظت جای CSP و پاک‌سازیِ سمتِ کلاینت را نمی‌گیرد؛ لایه‌ی
         * اول است، همان‌جا که داده وارد می‌شود.
         */
        $webUrl = ['url', 'starts_with:http://,https://'];

        return [
            'contact.title' => ['nullable', 'string', 'max:100'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.email' => ['nullable', 'string', 'max:150'],
            'contact.mapEmbedUrl' => array_merge(['nullable', 'string', 'max:1500'], $webUrl),
            'contact.showAddress' => ['boolean'],
            'contact.showPhone' => ['boolean'],
            'contact.showEmail' => ['boolean'],
            'contact.showMap' => ['boolean'],
            'social' => ['array', 'max:5'],
            'social.*.id' => ['required', 'string', 'in:'.implode(',', SiteContent::SOCIAL_IDS)],
            'social.*.label' => ['nullable', 'string', 'max:50'],
            'social.*.href' => array_merge(['nullable', 'string', 'max:300'], $webUrl),
            'social.*.enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact.mapEmbedUrl.starts_with' => 'آدرس نقشه باید با http:// یا https:// شروع شود.',
            'contact.mapEmbedUrl.url' => 'آدرس نقشه معتبر نیست.',
            'social.*.href.starts_with' => 'لینک شبکه اجتماعی باید با http:// یا https:// شروع شود.',
            'social.*.href.url' => 'لینک شبکه اجتماعی معتبر نیست.',
        ];
    }
}
