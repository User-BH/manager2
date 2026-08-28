<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذفِ نرم برای هزینه و درآمد (R51).
 *
 * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────────
 * ⚠️ دو مسیرِ API وجود داشت که دادهٔ مالی را **برای همیشه** می‌بردند:
 *
 *     DELETE /api/v1/finance/expenses/{expense}   →  $expense->delete()
 *     DELETE /api/v1/finance/incomes/{income}     →  $income->delete()
 *
 * هر دو برای مدیرِ مجتمع باز بودند. سیاههٔ ممیزی ثبت می‌کرد **چه کسی** حذف
 * کرده، ولی خودِ مبلغ و شرح می‌رفت و فقط با بازیابیِ کلِ بکاپِ شبانه
 * برمی‌گشت — یعنی برگرداندنِ یک ردیفِ اشتباه، همه‌ی کارِ آن روز را هم
 * برمی‌گرداند.
 *
 * ─── چرا فقط این دو جدول ───────────────────────────────────────────────────
 * قبض، پرداخت و دفترِ کیف مسیرِ حذف ندارند و حالا در سطحِ مدل هم بسته
 * شده‌اند (`NeverDeleted` و `Immutable`). برای آن‌ها حذفِ نرم لازم نیست و
 * حتی ضعیف‌تر است، چون `forceDelete()` را باز می‌گذارد.
 *
 * ⚠️ ستون **nullable** است و مقدارِ پیش‌فرض ندارد، پس روی دادهٔ موجود هیچ
 * تأثیری نمی‌گذارد: هر ردیفِ فعلی `deleted_at = null` می‌گیرد، یعنی
 * «حذف‌نشده» — همان چیزی که هست.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['expenses', 'incomes'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        /*
         * ⚠️ بازگشت، ردیف‌های حذف‌شده را **برنمی‌گرداند** بلکه فقط ستون را
         * برمی‌دارد — و با آن، اطلاعِ اینکه کدام ردیف حذف شده بود از بین
         * می‌رود. اجرای این `down` روی محصول یعنی ردیف‌های حذف‌شده ناگهان
         * دوباره در گزارش‌ها ظاهر شوند.
         *
         * پیش از اجرایش روی داده‌ی واقعی، بکاپ بگیرید.
         */
        foreach (['expenses', 'incomes'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
