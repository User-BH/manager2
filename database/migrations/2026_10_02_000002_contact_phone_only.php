<?php

use App\Support\SystemSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * راهِ تماسِ عمومی: فقط تلفن (مرحلهٔ ۷ · فاز ۴) — تصمیمِ کارفرما.
 *
 * «شماره تلفن فقط باشد و تمامی شبکه‌های اجتماعی بمانند.» ایمیلِ پشتیبانی در
 * این پروژه وجود ندارد و نشانی و نقشه مقدارِ نمونه بودند. پیش‌فرض‌ها در
 * `SiteContent` خاموش شدند؛ این مهاجرت همان را روی تنظیمی می‌نشاند که شاید
 * پیش‌تر از پنلِ ادمین ذخیره شده باشد — وگرنه مقدارِ ذخیره‌شده بر پیش‌فرض
 * می‌چربید و ایمیلِ نمونه همچنان در فوتر می‌ماند.
 *
 * فقط همین سه نمایش خاموش و مقدارشان خالی می‌شود؛ تلفن، عنوان و شبکه‌ها دست
 * نمی‌خورند. ادمین اگر بعداً نشانیِ واقعی داشت، از همان پنل روشنش می‌کند.
 * اجرای دوباره هیچ کاری نمی‌کند.
 */
return new class extends Migration
{
    private const KEY = 'site_footer';

    public function up(): void
    {
        $saved = SystemSettings::getJson(self::KEY);

        if ($saved === [] || ! isset($saved['contact']) || ! is_array($saved['contact'])) {
            return;
        }

        $saved['contact'] = array_merge($saved['contact'], [
            'email' => '',
            'showEmail' => false,
            'address' => '',
            'showAddress' => false,
            'mapEmbedUrl' => '',
            'showMap' => false,
        ]);

        SystemSettings::set(self::KEY, $saved);
    }

    /** مقدارِ پاک‌شده‌ی نمونه بازساختنی نیست و نباید باشد. */
    public function down(): void {}
};
