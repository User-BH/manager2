<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * انتقال سه بنر ثابتِ داخل کد به دیتابیس.
 *
 * تا پیش از این، این سه بنر در `resources/js/data/adSlides.ts` هاردکد بودند.
 * اگر جدول خالی بماند، صفحه‌ی فرود پس از استقرار بدون تبلیغ بالا می‌آمد؛ پس
 * همان‌ها اینجا درج می‌شوند تا سایت بدون هیچ کار دستی، مثل قبل کار کند.
 *
 * تصاویرشان فایل‌های webp موجود در public هستند، پس `image_path` ندارند و
 * از `image_url` خوانده می‌شوند.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        [
            'title' => 'نیترو پنل — میزبانی و سرور ابری',
            'subtitle' => 'سرور مجازی و هاست پرسرعت با پشتیبانی شبانه‌روزی برای کسب‌وکار شما',
            'href' => 'https://nitropanel.ir/',
            'image_url' => '/images/ad-nitropanel.webp',
        ],
        [
            'title' => 'کانال تلگرام Send Network',
            'subtitle' => 'آخرین اخبار، آموزش‌ها و پیشنهادهای ویژه را در تلگرام دنبال کنید',
            'href' => 'https://t.me/SendNetwork',
            'image_url' => '/images/ad-sendnetwork.webp',
        ],
        [
            'title' => 'Quotex — پلتفرم معاملات آنلاین',
            'subtitle' => 'معامله روی بازارهای جهانی با حساب آزمایشی رایگان و اجرای سریع',
            'href' => 'https://qxbroker.com',
            'image_url' => '/images/ad-qxbroker.webp',
        ],
    ];

    public function up(): void
    {
        // اگر ادمین پیش از اجرای این مهاجرت خودش بنری ساخته، دست نمی‌زنیم
        if (DB::table('advertisements')->exists()) {
            return;
        }

        $now = now();

        DB::table('advertisements')->insert(
            collect(self::DEFAULTS)->map(fn (array $ad, int $index) => $ad + [
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        // فقط بنرهای پیش‌فرض برداشته می‌شوند، نه چیزی که ادمین ساخته
        DB::table('advertisements')
            ->whereIn('image_url', array_column(self::DEFAULTS, 'image_url'))
            ->delete();
    }
};
