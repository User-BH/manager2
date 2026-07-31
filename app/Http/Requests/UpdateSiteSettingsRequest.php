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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact.title' => ['nullable', 'string', 'max:100'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.email' => ['nullable', 'string', 'max:150'],
            'contact.mapEmbedUrl' => ['nullable', 'string', 'max:1500'],
            'contact.showAddress' => ['boolean'],
            'contact.showPhone' => ['boolean'],
            'contact.showEmail' => ['boolean'],
            'contact.showMap' => ['boolean'],
            'social' => ['array', 'max:5'],
            'social.*.id' => ['required', 'string', 'in:'.implode(',', SiteContent::SOCIAL_IDS)],
            'social.*.label' => ['nullable', 'string', 'max:50'],
            'social.*.href' => ['nullable', 'string', 'max:300'],
            'social.*.enabled' => ['boolean'],
        ];
    }
}
