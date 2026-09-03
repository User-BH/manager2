<?php

use App\Enums\ComplexType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نوعِ مجتمع، و تعرفه‌ی پلن بر پایه‌ی آن (R67).
 *
 * ─── چرا این مهاجرت غیرمخرب است ────────────────────────────────────────────
 * ⚠️ برنامه‌ی کار این مرحله را «بزرگ‌ترین تغییرِ Schemaی پروژه» نامیده بود و
 * درست هم بود — تا وقتی پاسخِ کارفرما رسید: **یک مجتمع یا مسکونی است یا
 * تجاری، هرگز هر دو.**
 *
 * با آن پاسخ، تغییر به دو ستونِ افزودنی تبدیل می‌شود و هیچ داده‌ای جابه‌جا
 * نمی‌شود. اگر پاسخ «هر دو» بود، نوع باید روی **واحد** می‌نشست و آن‌وقت هر
 * محاسبه‌ی مالی باید بازنویسی می‌شد.
 *
 * ─── ⚠️ چرا `complexes.type` پیش‌فرض دارد و `plans.complex_type` ندارد ──────
 * این تفاوت عمدی است و از قاعده‌ی Expand→Migrate→Contract می‌آید
 * (`docs/MIGRATIONS.md`):
 *
 *   complexes.type = 'residential'
 *     هر مجتمعی که پیش از امروز ساخته شده، **قطعاً** مسکونی است — چون
 *     مفهومِ دیگری وجود نداشت. پس پیش‌فرض یک واقعیت است، نه یک حدس.
 *
 *   plans.complex_type = NULL
 *     🔴 اگر پلن‌های موجود «مسکونی» علامت می‌خوردند، اولین مجتمعِ تجاری
 *     صفحه‌ی خرید را **خالی** می‌دید. `NULL` یعنی «برای هر دو نوع» و
 *     رفتارِ امروز را دقیقاً حفظ می‌کند. ادمینِ کل بعداً پلنِ تجاری با
 *     تعرفه‌ی خودش می‌سازد.
 *
 * ─── ⚠️ چرا check constraint گذاشته نشد ────────────────────────────────────
 * درسِ R52: `ALTER TABLE ... ADD CONSTRAINT` روی SQLiteِ رانرِ CI کار
 * نمی‌کند و مهاجرتِ قیدهای مالی برای همین یک probeی جداگانه دارد. تکرارِ
 * آن ماشین‌آلات برای دو ستونی که مقدارشان از enum می‌آید، هزینه‌اش از
 * فایده‌اش بیشتر است.
 *
 * به‌جایش خرابی **پرسروصدا** است: cast روی مقدارِ نامعتبر استثنا می‌دهد،
 * پس ردیفِ خراب در اولین خواندن دیده می‌شود نه اینکه بی‌صدا «پلنی ندارد»
 * بشود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->string('type', 20)
                ->default(ComplexType::Residential->value)
                ->after('slug')
                ->index();
        });

        Schema::table('plans', function (Blueprint $table) {
            /*
             * ⚠️ `nullable` یعنی «برای هر دو نوع».
             *
             * سه حالت دارد و هر سه معنی‌دارند: مسکونی، تجاری، و همگانی.
             * پلنی که ادمین امروز دارد در حالتِ سوم می‌ماند.
             */
            $table->string('complex_type', 20)->nullable()->after('slug')->index();
        });
    }

    public function down(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['complex_type']);
            $table->dropColumn('complex_type');
        });
    }
};
