<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نظرسنجیِ حرفه‌ای (R24).
 *
 * نظرسنجیِ R23b یک نظرخواهیِ ساده بود: هرکه پیام را می‌دید رأی می‌داد و هر
 * نفر یک رأی داشت. برای تصمیم‌های واقعیِ ساختمان کافی نیست:
 *
 *   • **واحد** رأی می‌دهد نه **نفر**. مالک و مستاجرِ یک واحد با هم دو رأی
 *     داشتند، پس واحدی که سه ساکن دارد سه برابرِ واحدِ تک‌نفره وزن داشت.
 *   • تصمیمِ نما و آسانسور به مالکان مربوط است، تصمیمِ ساعتِ نظافت به همه.
 *     نظرسنجی راهی برای گفتنِ این تفاوت نداشت.
 *   • نتیجه بدونِ **حد نصاب** و بدونِ **درصدِ مشارکت** عدد است، نه تصمیم.
 *     «۳ رأی به آبی» وقتی معنا دارد که بدانیم از چند واجدِ شرایط.
 *
 * پیش‌فرض‌ها عمداً همان رفتارِ قبلی‌اند (`residents` + `per_person` + بدونِ
 * حد نصاب)، پس نظرسنجی‌های موجود دست‌نخورده می‌مانند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_polls', function (Blueprint $table) {
            // چه کسانی حقِ رأی دارند: همه‌ی ساکنین، یا فقط مالکان
            $table->string('voter_scope', 12)->default('residents')->after('question');

            // رأی چطور شمرده می‌شود: هر نفر، هر واحد، یا وزنی بر اساس متراژ
            $table->string('weight_mode', 12)->default('per_person')->after('voter_scope');

            /*
             * حد نصابِ مشارکت (درصد). تا وقتی مشارکت به آن نرسیده، نتیجه
             * «نامعتبر» اعلام می‌شود — نه اینکه پنهان شود. `null` یعنی
             * نظرسنجی حد نصاب ندارد.
             */
            $table->unsignedTinyInteger('quorum_percent')->nullable()->after('weight_mode');

            /*
             * آیا رأی پس از ثبت قابل تغییر است.
             *
             * پیش‌فرض `true` است تا رفتار R23b حفظ شود، ولی برای تصمیم‌های
             * رسمی مدیر می‌تواند قفلش کند.
             */
            $table->boolean('allow_change')->default(true)->after('quorum_percent');
        });

        Schema::table('poll_votes', function (Blueprint $table) {
            /*
             * واحدی که این رأی به حسابش نوشته شد.
             *
             * برای نظرسنجیِ واحدمحور، «رأیِ تکراری» یعنی رأیِ دوم از همان
             * **واحد**، نه از همان کاربر. بدونِ این ستون راهی برای فهمیدنش
             * نبود.
             */
            $table->foreignId('unit_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            /*
             * وزنِ رأی، **عکسِ لحظه‌ی ثبت**.
             *
             * متراژِ واحد بعداً ممکن است اصلاح شود؛ اگر وزن را در لحظه‌ی
             * شمارش از روی `units.area` می‌خواندیم، نتیجه‌ی یک نظرسنجیِ
             * بسته با یک ویرایشِ ساده در پرونده‌ی واحد عوض می‌شد.
             */
            $table->decimal('weight', 10, 2)->default(1)->after('unit_id');

            $table->index(['message_poll_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('poll_votes', function (Blueprint $table) {
            $table->dropIndex(['message_poll_id', 'unit_id']);
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn('weight');
        });

        Schema::table('message_polls', function (Blueprint $table) {
            $table->dropColumn(['voter_scope', 'weight_mode', 'quorum_percent', 'allow_change']);
        });
    }
};
