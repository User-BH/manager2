<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * یک کیف برای هر حساب (مرحلهٔ ۹ · تصمیمِ نهاییِ کارفرما، ۱۸ مهر).
 *
 * مانده از این پس جمعِ **همه‌ی** ردیف‌های صاحب است، نه هر مجتمع جدا. ساختارِ
 * مالکیت عوض نمی‌شود: `user_id` از `2026_10_10_000001` اجباری است، پس هیچ ردیفی
 * صاحبِ تازه نمی‌گیرد و هیچ مبلغی جابه‌جا نمی‌شود — جمعِ جیب‌های هر شخص دقیقاً
 * مانده‌ی تازه‌ی اوست.
 *
 * دو افزوده:
 * • `idempotency_key`: پرداختِ تکراری از کیف (کلیکِ دوباره، retry) ردیفِ دوم
 *   نسازد — یکتا برای هر صاحب.
 * • ایندکسِ `(user_id, id)` برای مانده و صورت‌حسابِ کلِ کیف.
 *
 * ⚠️ پیش از هر تغییری می‌سنجد که هیچ ردیفی بی صاحب نیست؛ اگر باشد، **می‌ایستد**
 * و حدس نمی‌زند.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('wallet_transactions')->whereNull('user_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "{$orphans} ردیفِ دفترِ کیف صاحب ندارد؛ مهاجرت متوقف شد. پیش از ادامه صاحبِ هر ردیف باید روشن شود."
            );
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('note');
            $table->unique(['user_id', 'idempotency_key'], 'wallet_owner_idempotency_unique');
            $table->index(['user_id', 'id'], 'wallet_owner_ledger_index');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_owner_ledger_index');
            $table->dropUnique('wallet_owner_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
