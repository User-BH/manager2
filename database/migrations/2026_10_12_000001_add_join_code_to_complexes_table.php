<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کدِ ورودِ عمومیِ مجتمع — همان که در QR است (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲ و ۲۵ ِ فایلِ معماری).
 *
 * ⚠️ عمداً متنِ ساده است، نه هش: QR در لابی چاپ می‌شود و مدیر باید هر بار دوباره ببیندش. «راز» نیست؛
 * فقط **حدس‌نزدنی** است (۱۲۸ بیت) تا نشود مجتمع‌ها را پیمود، و **عوض‌شدنی** تا QR ِ قدیمی را بشود از
 * کار انداخت. اسکنش هیچ دسترسی‌ای نمی‌دهد: فقط درخواستِ عضویت می‌سازد که مدیر باید تأیید کند.
 * تهی یعنی ورودِ عمومی برای این مجتمع خاموش است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->string('join_code', 32)->nullable()->unique();
            $table->timestamp('join_code_created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complexes', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn(['join_code', 'join_code_created_at']);
        });
    }
};
