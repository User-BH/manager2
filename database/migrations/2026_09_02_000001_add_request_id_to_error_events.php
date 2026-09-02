<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شناسه‌ی درخواست روی ردیفِ خطا (R63).
 *
 * ─── چرا لازم است ──────────────────────────────────────────────────────────
 * ⚠️ جدولِ `error_events` تا امروز `url` و `method` داشت، ولی آن دو
 * **درخواست را مشخص نمی‌کنند**: در ساعتِ شلوغ ده‌ها `POST /api/v1/pay/12`
 * در یک دقیقه هست.
 *
 * با این ستون، ردیفِ خطا مستقیم به خطوطِ لاگِ همان درخواست وصل می‌شود — و
 * کاربری که کدِ کوتاه را خوانده، در یک جست‌وجو پیدا می‌شود.
 *
 * ─── ⚠️ چرا شناسه‌ی آخرین رخداد و نه اولین ────────────────────────────────
 * ردیف‌ها با `fingerprint` ادغام می‌شوند: یک خطای تکراری یک ردیف است با
 * شمارنده. پس این ستون شناسه‌ی **آخرین** رخداد را نگه می‌دارد، همان‌طور
 * که `last_seen_at`.
 *
 * دلیلش عملی است: وقتی کاربر همین حالا زنگ می‌زند، شناسه‌ای که او در دست
 * دارد مالِ همین لحظه است. شناسه‌ی سه هفته پیش کمکی نمی‌کند و لاگش هم
 * دیگر روی دیسک نیست.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            // ⚠️ nullable، چون ردیف‌های موجود شناسه‌ای ندارند و نباید داشته باشند
            $table->string('request_id', 20)->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->dropColumn('request_id');
        });
    }
};
