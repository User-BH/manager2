<?php

use App\Models\BlogPost;
use App\Services\Blog\BlogSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ستون‌های رتبه‌بندیِ جست‌وجوی بلاگ (مرحلهٔ ۸ · فاز ۴) — توضیح در
 * `BlogSearchIndex`. مقاله‌های موجود همین‌جا پر می‌شوند تا جست‌وجو پس از
 * استقرار بی‌هیچ دستورِ جدایی کار کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->string('search_title')->nullable()->after('search_text');
            $table->text('search_meta')->nullable()->after('search_title');
        });

        BlogPost::query()->with(['category', 'tags'])->chunkById(100, function ($posts): void {
            foreach ($posts as $post) {
                BlogSearchIndex::refresh($post);
            }
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn(['search_title', 'search_meta']);
        });
    }
};
