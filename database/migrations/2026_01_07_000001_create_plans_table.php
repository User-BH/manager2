<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پکیج‌های اشتراک، پویا و قابلِ‌تعریف از پنلِ ادمینِ کل.
 *
 * پیش از این پلن‌ها در enum هاردکد بودند؛ حالا ادمین می‌تواند پکیج بسازد،
 * قیمت و امکاناتش را عوض کند و فعال/غیرفعالشان کند. قابلیت‌هایی که کد واقعاً
 * اعمال می‌کند (سقفِ واحد، درگاهِ واقعی، خروجی Excel) ستون‌های مشخص دارند؛
 * بقیه‌ی امکانات فقط نمایشی‌اند و در `features` می‌آیند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('price')->default(0); // تومان
            $table->unsignedInteger('months')->default(1);
            $table->unsignedInteger('unit_limit')->nullable(); // null = نامحدود
            $table->boolean('real_gateway')->default(false);
            $table->boolean('excel_export')->default(false);
            $table->json('features')->nullable(); // برچسب‌های نمایشیِ امکانات
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
