<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عاملِ دومِ سوپر ادمین: برنامه‌ی Authenticator (TOTP) و کدهای بازیابیِ یک‌بارمصرف (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵،
 * بخشِ ۵؛ تصمیمِ نهاییِ د-۱).
 *
 * - `two_factor_secret`: کلیدِ TOTP ِ فعال، **رمزنگاری‌شده** با کلیدِ برنامه (cast ِ `encrypted`)؛ هرگز در پاسخ، لاگ یا
 *   مخزن نیست.
 * - `two_factor_pending_secret`: کلیدِ تازه تا وقتی با یک کدِ درست تأیید شود؛ کلیدِ فعال تا آن لحظه معتبر می‌ماند.
 * - `two_factor_recovery_codes`: فقط HMAC ِ کدهای بازیابی (رمزنگاری‌شده)، نه خودِ کدها.
 * - `two_factor_last_step`: آخرین گامِ زمانیِ پذیرفته‌شده — جلوی استفاده‌ی دوباره از همان کد را می‌گیرد.
 *
 * همه nullable؛ هیچ حسابِ موجودی عاملِ دوم نمی‌گیرد مگر خودش فعالش کند. `down()` فقط همین ستون‌ها را برمی‌دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_pending_secret')->nullable();
            $table->timestamp('two_factor_pending_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret', 'two_factor_pending_secret', 'two_factor_pending_at',
                'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step',
            ]);
        });
    }
};
