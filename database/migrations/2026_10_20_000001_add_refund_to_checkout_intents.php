<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بازپرداختِ «پرداخت شد ولی مجتمع ساخته نشد» (مرحلهٔ ۱۲ · فاز ۲؛ بندِ ۵ ِ تصمیمِ قطعیِ کارفرما).
 *
 * قصدِ `paid` پولِ گرفته‌شده‌ای است که مجتمع نشد (حساب در این فاصله غیرفعال یا ادمینِ کل شد). اگر حساب دوباره
 * واجدِ شرایط شود، بازبینیِ دوره‌ای همان را کامل می‌کند؛ وگرنه ادمینِ کل بازپرداختِ بانکی را با مرجعش ثبت می‌کند —
 * ثبتِ پولِ برگشته، نه فعال‌سازی. `down()` فقط ستون‌ها را برمی‌دارد؛ قصدِ `refunded` به `paid` برمی‌گردد تا
 * معنایش گم نشود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reference', 100)->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('checkout_intents')->where('status', 'refunded')->update(['status' => 'paid']);

        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_at', 'refund_reference']);
        });
    }
};
