<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خرید اشتراک با «واریز و آپلود رسید».
 *
 * تا پیش از این تنها راه خرید، درگاه آنلاین بود؛ و چون درگاه اشتراک روی
 * نصب واقعی فعال نیست، عملاً هیچ راهی برای خرید وجود نداشت. این ستون‌ها
 * همان گردش‌کارِ رسیدِ شارژِ واحدها را برای اشتراک هم ممکن می‌کنند:
 * آپلود توسط مدیر مجتمع ← بررسی توسط ادمین کل ← فعال شدن اشتراک.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // online | receipt — تفکیک مسیر خرید
            $table->string('method', 10)->default('online')->after('status');

            $table->string('receipt_path')->nullable()->after('paid_at');
            $table->string('receipt_original_name')->nullable()->after('receipt_path');
            $table->date('receipt_paid_on')->nullable()->after('receipt_original_name');

            // بازبینی توسط ادمین کل
            $table->foreignId('reviewed_by')->nullable()->after('receipt_paid_on')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'method', 'receipt_path', 'receipt_original_name',
                'receipt_paid_on', 'reviewed_at', 'review_note',
            ]);
        });
    }
};
