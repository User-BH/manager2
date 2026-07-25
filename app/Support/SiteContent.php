<?php

namespace App\Support;

/**
 * محتوای قابلِ‌تنظیمِ فوترِ صفحه‌ی فرود (تماس و شبکه‌های اجتماعی).
 *
 * پیش از این این‌ها در `config/brand.ts` هاردکد بودند و تغییرشان نیازمند بیلد
 * دوباره بود. حالا ادمینِ کل می‌تواند از پنل عوضشان کند؛ اینجا فقط مقادیرِ
 * پیش‌فرض و ترکیبِ آن‌ها با تنظیماتِ ذخیره‌شده نگه داشته می‌شود.
 *
 * آیکونِ هر شبکه در فرانت ثابت است، پس شناسه‌ها ثابت‌اند و فقط
 * برچسب/لینک/فعال‌بودن قابلِ‌تغییرند.
 */
class SiteContent
{
    private const SETTING_KEY = 'site_footer';

    /** شناسه‌های مجازِ شبکه‌ها (هم‌نام با آیکون‌های فرانت). */
    public const SOCIAL_IDS = ['instagram', 'telegram', 'whatsapp', 'rubika', 'bale'];

    /** ساختار و مقادیرِ پیش‌فرض، اگر ادمین چیزی تنظیم نکرده باشد. */
    public static function defaults(): array
    {
        return [
            'contact' => [
                'title' => 'ارتباط با ما',
                'address' => 'تهران، خیابان ولیعصر، بالاتر از میدان ونک، برج نگین، طبقه ۴',
                'phone' => '۰۲۱-۸۸۷۷۶۶۵۵',
                'email' => 'info@sakena.app',
                'mapEmbedUrl' => 'https://maps.google.com/maps?q=35.7595,51.4111&z=15&output=embed',
                'showAddress' => true,
                'showPhone' => true,
                'showEmail' => true,
                'showMap' => true,
            ],
            'social' => [
                ['id' => 'instagram', 'label' => 'اینستاگرام', 'href' => 'https://instagram.com/sakena.app', 'enabled' => true],
                ['id' => 'telegram', 'label' => 'تلگرام', 'href' => 'https://t.me/sakena_app', 'enabled' => true],
                ['id' => 'whatsapp', 'label' => 'واتساپ', 'href' => 'https://wa.me/982188776655', 'enabled' => true],
                ['id' => 'rubika', 'label' => 'روبیکا', 'href' => 'https://rubika.ir/sakena_app', 'enabled' => true],
                ['id' => 'bale', 'label' => 'بله', 'href' => 'https://ble.ir/sakena_app', 'enabled' => true],
            ],
        ];
    }

    /** تنظیماتِ ذخیره‌شده را روی پیش‌فرض‌ها می‌نشاند. */
    public static function footer(): array
    {
        $defaults = self::defaults();
        $saved = SystemSettings::getJson(self::SETTING_KEY, []);

        $contact = array_merge($defaults['contact'], $saved['contact'] ?? []);

        // شبکه‌ها همیشه همان پنج شناسه‌ی ثابت‌اند؛ فقط برچسب/لینک/فعال‌بودنشان
        // از تنظیمات می‌آید تا نشود شناسه‌ی بی‌آیکون اضافه کرد.
        $savedSocial = collect($saved['social'] ?? [])->keyBy('id');
        $social = collect($defaults['social'])->map(function (array $item) use ($savedSocial) {
            $override = (array) $savedSocial->get($item['id'], []);

            return array_merge($item, array_intersect_key($override, array_flip(['label', 'href', 'enabled'])));
        })->all();

        return ['contact' => $contact, 'social' => $social];
    }

    public static function save(array $contact, array $social): void
    {
        SystemSettings::set(self::SETTING_KEY, ['contact' => $contact, 'social' => $social]);
    }
}
