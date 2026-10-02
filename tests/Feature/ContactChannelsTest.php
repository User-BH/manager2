<?php

namespace Tests\Feature;

use App\Support\SiteContent;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * راهِ تماسِ عمومی: فقط تلفن و شبکه‌های اجتماعی (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ تصمیمِ کارفرما: «شماره تلفن فقط باشد و تمامی شبکه‌های اجتماعی بمانند.»
 * ایمیلِ پشتیبانی در پروژه وجود ندارد و نشانی و نقشه مقدارِ نمونه بودند —
 * و قاعده‌ی فایلِ ایرادات صریح است: راهی که در واقعیت نیست معرفی نشود.
 */
class ContactChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_footer_offers_only_the_phone_and_the_social_networks(): void
    {
        $this->getJson('/api/v1/site-settings')
            ->assertOk()
            ->assertJsonPath('contact.phone', '۰۲۱-۸۸۷۷۶۶۵۵')
            ->assertJsonPath('contact.email', null)
            ->assertJsonPath('contact.address', null)
            ->assertJsonPath('contact.mapEmbedUrl', null)
            ->assertJsonCount(count(SiteContent::SOCIAL_IDS), 'social');
    }

    /**
     * دادهٔ ساخت‌یافته‌ی گوگل هم نباید ایمیلی معرفی کند که نیست.
     */
    public function test_the_structured_data_names_no_email(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"telephone":"+982188776655"', $html);
        $this->assertStringNotContainsString('"email"', $html);
        $this->assertStringNotContainsString('info@sakena.app', $html);
    }

    /**
     * 🔴 مقدارِ ذخیره‌شده از پنلِ ادمین بر پیش‌فرض می‌چربید؛ بی‌این مهاجرت
     * ایمیلِ نمونه همچنان در فوترِ محصول می‌ماند.
     */
    public function test_the_migration_switches_off_what_the_panel_had_saved(): void
    {
        SystemSettings::set('site_footer', [
            'contact' => [
                'title' => 'تماس',
                'phone' => '۰۲۱-۱۱۱۱۱۱۱۱',
                'email' => 'info@sakena.app',
                'showEmail' => true,
                'address' => 'نشانیِ نمونه',
                'showAddress' => true,
                'mapEmbedUrl' => 'https://maps.example',
                'showMap' => true,
                'showPhone' => true,
            ],
            'social' => [['id' => 'instagram', 'href' => 'https://instagram.com/real', 'enabled' => true]],
        ]);

        $migration = require database_path('migrations/2026_10_02_000002_contact_phone_only.php');
        $migration->up();
        // اجرای دوباره بی‌اثر است
        $migration->up();

        $this->getJson('/api/v1/site-settings')
            ->assertJsonPath('contact.phone', '۰۲۱-۱۱۱۱۱۱۱۱')
            ->assertJsonPath('contact.title', 'تماس')
            ->assertJsonPath('contact.email', null)
            ->assertJsonPath('contact.address', null)
            ->assertJsonPath('contact.mapEmbedUrl', null)
            ->assertJsonPath('social.0.href', 'https://instagram.com/real');
    }
}
