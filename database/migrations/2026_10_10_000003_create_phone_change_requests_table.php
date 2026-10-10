<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * درخواستِ در جریانِ تغییرِ شماره (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * شماره‌ی canonical (`users.phone`) فقط پس از تأییدِ کدِ پیامکیِ شماره‌ی تازه عوض
 * می‌شود؛ تا آن لحظه شماره‌ی تازه فقط این‌جاست. برای هر حساب یک درخواستِ زنده
 * (`user_id` یکتا)؛ درخواستِ تازه قبلی را جایگزین می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('new_phone', 20);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('new_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_change_requests');
    }
};
