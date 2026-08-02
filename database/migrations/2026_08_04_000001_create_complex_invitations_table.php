<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دعوتِ کاربرِ از پیش ثبت‌نام‌کرده به یک مجتمع (R21).
 *
 * ─── چرا این جدول لازم شد ──────────────────────────────────────────────────
 * کسی که خودش ثبت‌نام کرده بود در بن‌بست می‌ماند: خودش نمی‌توانست وارد شود
 * (حساب غیرفعال ساخته می‌شد) و مدیرِ مجتمع هم نمی‌توانست اضافه‌اش کند، چون
 * شماره‌ی تلفن یکتاست و اعتبارسنجی ۴۲۲ می‌داد.
 *
 * راهِ ساده این بود که مدیر بتواند حسابِ موجود را مستقیم به مجتمعِ خودش وصل
 * کند. آن راه عمداً انتخاب **نشد**: یعنی هر مدیری با دانستنِ یک شماره‌ی
 * موبایل می‌توانست آن حساب را به مجتمعِ خودش بکشد و نقشش را عوض کند — بدونِ
 * اینکه صاحبِ حساب خبر داشته باشد.
 *
 * پس پیوستن با **رضایتِ صریح** انجام می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // واحدِ پیشنهادی؛ اختیاری است چون مدیر ممکن است بعداً تخصیص بدهد
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();

            // نقشی که پس از پذیرش می‌گیرد
            $table->string('role', 20);

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            /*
             * یک دعوتِ در انتظار برای هر زوجِ (کاربر، مجتمع).
             * بدونِ این، مدیر می‌توانست با تکرارِ فرم صندوقِ کاربر را پر کند.
             */
            $table->unique(['complex_id', 'user_id', 'status'], 'invitation_unique_pending');

            // صفحه‌ی «دعوت‌های من» با این می‌خواند
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_invitations');
    }
};
