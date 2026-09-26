<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بلاگِ عمومیِ سایت (مرحلهٔ ۶ · فاز ۱۱).
 *
 * ⚠️ مالِ سکوست نه مجتمع: هیچ ستونِ `complex_id` و هیچ `BelongsToComplex`ی
 * ندارد. نویسنده فقط سوپرادمین است و خواننده همه‌ی دنیا.
 *
 * ─── چرا متنِ رندرشده ذخیره می‌شود ─────────────────────────────────────────
 * `body` مارک‌داونِ نویسنده است و `body_html` خروجیِ **پاک‌سازی‌شده‌ی** آن که
 * هنگامِ ذخیره یک بار ساخته می‌شود. صفحه‌ی مقاله پرخواننده‌ترین صفحه‌ی بلاگ
 * است؛ رندرِ مارک‌داون در هر بازدید هزینه‌ای بود که یک بار پرداخت می‌شد.
 * `search_text` همان متن، بی‌برچسب و یک‌دست‌شده (ی/ک)، برای جست‌وجو.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('blog_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 60)->unique();
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->string('excerpt', 400)->nullable();
            $table->longText('body');
            $table->longText('body_html');
            $table->json('toc')->nullable();
            $table->longText('search_text');
            $table->unsignedSmallInteger('reading_minutes')->default(1);
            $table->string('cover_path')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestamps();

            // فهرستِ عمومی: «منتشرشده، تا امروز، تازه‌ترین اول»
            $table->index(['status', 'published_at']);
        });

        Schema::create('blog_post_tag', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['blog_post_id', 'blog_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_tags');
        Schema::dropIfExists('blog_categories');
    }
};
