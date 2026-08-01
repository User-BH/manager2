<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محافظت از تاریخچه‌ی مالیِ واحد + ایندکسِ ترکیبیِ کاربران (R14).
 *
 * ─── مسئله‌ی اول: حذفِ واحد پول را پاک می‌کرد ──────────────────────────────
 * `bills.unit_id` و `payments.unit_id` هر دو `cascadeOnDelete` بودند. یعنی
 * حذفِ یک واحد **همه‌ی قبض‌ها و پرداخت‌هایش را برای همیشه** می‌برد. برای
 * واحدی که فروخته یا تخلیه شده، این یعنی نابودیِ سابقه‌ی حسابداری — و در یک
 * سامانه‌ی مالی، سابقه‌ای که پاک شود قابل بازسازی نیست.
 *
 * راه‌حل، حذفِ نرم است و نه دست‌زدن به کلیدهای خارجی: با `deleted_at`، حذف
 * دیگر به دیتابیس نمی‌رسد، پس cascade اصلاً شلیک نمی‌شود و قبض‌ها سرِ جایشان
 * می‌مانند. واحد برای کاربر ناپدید می‌شود ولی برای حسابداری باقی است.
 *
 * **پیامدی که باید بدانید:** شماره‌ی واحدِ حذف‌شده همچنان رزرو می‌ماند، چون
 * قیدِ یکتاییِ `(complex_id, unit_number)` سرِ جایش است. افزودنِ `deleted_at`
 * به آن قید، خودِ قید را از کار می‌انداخت: در MySQL چند `NULL` در ایندکسِ
 * یکتا مجازند، پس دو واحدِ زنده با یک شماره هم مجاز می‌شدند — یعنی برای حلِ
 * یک ناراحتیِ کوچک، ضمانتِ اصلی را از دست می‌دادیم. اگر واحدی اشتباهی حذف
 * شد، بازگردانی‌اش کنید نه اینکه دوباره بسازید.
 *
 * ─── مسئله‌ی دوم: ایندکسِ گمشده ────────────────────────────────────────────
 * پروفایلِ R13 نشان داد فهرستِ ساکنین این کوئری را می‌زند:
 *
 *     select * from users where complex_id = ? and role in (?, ?)
 *
 * ایندکسِ جدا روی `role` و `complex_id` بود ولی ترکیبی نه؛ با رشدِ تعدادِ
 * کاربرانِ پلتفرم، این کوئری به پیمایشِ بخشِ بزرگی از جدول می‌رسید.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['complex_id', 'role'], 'users_complex_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_complex_role_index');
        });
    }
};
