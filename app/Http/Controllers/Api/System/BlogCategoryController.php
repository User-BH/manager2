<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBlogCategoryRequest;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Services\Blog\BlogSearchIndex;
use App\Services\Blog\BlogWriter;
use Illuminate\Http\JsonResponse;

/**
 * دسته‌های بلاگ (مرحلهٔ ۶ · فاز ۱۱). فهرست کوچک است و صفحه‌بندی ندارد.
 *
 * ⚠️ حذفِ دسته مقاله‌هایش را حذف نمی‌کند؛ فقط بی‌دسته می‌شوند (`nullOnDelete`).
 */
class BlogCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BlogCategory::withCount('posts')->orderBy('name')->get()
                ->map(fn (BlogCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'description' => $c->description,
                    'postsCount' => (int) $c->posts_count,
                ]),
        ]);
    }

    public function store(SaveBlogCategoryRequest $request, BlogWriter $writer): JsonResponse
    {
        $data = $request->validated();
        $category = BlogCategory::create([...$data, 'slug' => $this->uniqueSlug($writer, $data['name'])]);

        return response()->json(['message' => 'دسته ساخته شد.', 'id' => $category->id], 201);
    }

    public function update(SaveBlogCategoryRequest $request, BlogCategory $category): JsonResponse
    {
        // نامک عوض نمی‌شود: پیوندهای بیرونی به صفحه‌ی دسته نمی‌شکنند
        $category->update($request->validated());

        // نامِ دسته در ستونِ جست‌وجوی مقاله‌هایش هم هست (مرحلهٔ ۸ · فاز ۴)
        $category->posts()->with(['category', 'tags'])->each(fn (BlogPost $post) => BlogSearchIndex::refresh($post));

        return response()->json(['message' => 'دسته ذخیره شد.']);
    }

    public function destroy(BlogCategory $category): JsonResponse
    {
        $postIds = $category->posts()->pluck('id');
        $category->delete();

        BlogPost::whereKey($postIds)->with(['category', 'tags'])->each(fn (BlogPost $post) => BlogSearchIndex::refresh($post));

        return response()->json(['message' => 'دسته حذف شد؛ مقاله‌هایش بی‌دسته شدند.']);
    }

    private function uniqueSlug(BlogWriter $writer, string $name): string
    {
        $base = $writer->slugify($name) ?: 'category';
        $slug = $base;

        for ($i = 2; BlogCategory::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
