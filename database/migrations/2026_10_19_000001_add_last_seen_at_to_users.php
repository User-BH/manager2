<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حضورِ آنلاین در پیام‌رسان («چند نکته»، بندِ ۳).
 *
 * `users.last_seen_at`: آخرین درخواستِ واردشده‌ی این حساب از داشبوردِ باز — نه بیشتر از یک نوشتن در هر ۳۰ ثانیه
 * (`Presence::THROTTLE`). «آنلاین» یعنی همین زمان در ۹۰ ثانیه‌ی اخیر (`Presence::WINDOW`). روی حساب است، نه
 * عضویت: دیدنش را عضویتِ فعالِ مشترک محدود می‌کند، نه جای ذخیره. نمایه برای شمارِ آنلاینِ گروه.
 *
 * `down()` فقط ستون را برمی‌دارد؛ چیزی جز «آخرین بار» از دست نمی‌رود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
