<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * سه پکیجِ پیش‌فرض تا سامانه از روزِ اول پلن داشته باشد. ادمین می‌تواند
 * این‌ها را ویرایش، غیرفعال یا حذف کند و پکیجِ تازه بسازد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('plans')->exists()) {
            return;
        }

        $now = now();

        DB::table('plans')->insert([
            [
                'name' => 'پایه',
                'slug' => 'basic',
                'price' => 249_000,
                'months' => 1,
                'unit_limit' => 25,
                'real_gateway' => false,
                'excel_export' => false,
                'features' => json_encode([
                    'تا ۲۵ واحد',
                    'صدور قبض و شارژ ماهانه',
                    'اطلاعیه و پیام‌رسان',
                    'پرداخت با آپلود رسید',
                    'خروجی PDF فاکتور و تسویه‌حساب',
                ], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'حرفه‌ای',
                'slug' => 'plus',
                'price' => 549_000,
                'months' => 1,
                'unit_limit' => 120,
                'real_gateway' => true,
                'excel_export' => true,
                'features' => json_encode([
                    'تا ۱۲۰ واحد',
                    'اتصال درگاه پرداخت بانکی (ملت / سامان)',
                    'خروجی Excel از قبوض هر دوره',
                    'همه‌ی امکانات پکیج پایه',
                ], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'سازمانی',
                'slug' => 'business',
                'price' => 990_000,
                'months' => 1,
                'unit_limit' => null,
                'real_gateway' => true,
                'excel_export' => true,
                'features' => json_encode([
                    'واحد نامحدود',
                    'اتصال درگاه پرداخت بانکی',
                    'خروجی Excel',
                    'همه‌ی امکانات پکیج حرفه‌ای',
                ], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('slug', ['basic', 'pro', 'business'])->delete();
    }
};
