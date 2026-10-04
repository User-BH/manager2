<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBlogPostRequest;
use App\Http\Requests\UploadBlogImageRequest;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Services\Blog\BlogDeleter;
use App\Services\Blog\BlogRenderer;
use App\Services\Blog\BlogWriter;
use App\Support\Jalali;
use App\Support\PersianText;
use App\Support\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نوشتنِ بلاگ در پنلِ سوپرادمین (مرحلهٔ ۶ · فاز ۱۱).
 */
class BlogPostController extends Controller
{
    public function __construct(private readonly BlogWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $term = mb_substr(str_replace(['%', '_', '\\'], '', PersianText::normalize(trim((string) $request->query('q', '')))), 0, 80);
        $status = $request->query('status');

        $posts = BlogPost::with('category')
            ->when(in_array($status, [BlogPost::DRAFT, BlogPost::PUBLISHED], true), fn ($q) => $q->where('status', $status))
            ->when($term !== '', fn ($q) => $q->where('title', 'like', "%{$term}%"))
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => collect($posts->items())->map(fn (BlogPost $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'status' => $post->status,
                // منتشرشده ولی هنوز زمانش نرسیده
                'scheduled' => $post->status === BlogPost::PUBLISHED && ! $post->isVisible(),
                'category' => $post->category?->name,
                'publishedAt' => $post->published_at ? Jalali::dateTime($post->published_at) : null,
                'updatedAt' => Jalali::dateTime($post->updated_at),
            ])->values(),
            'meta' => ['currentPage' => $posts->currentPage(), 'lastPage' => $posts->lastPage(), 'total' => $posts->total()],
        ]);
    }

    public function show(BlogPost $post): JsonResponse
    {
        $post->load('tags');

        return response()->json(['post' => [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'categoryId' => $post->category_id,
            'tags' => $post->tags->map(fn (BlogTag $t) => $t->name)->values(),
            'status' => $post->status,
            'publishedAt' => $post->published_at?->toIso8601String(),
            'cover' => $post->cover_path ? basename($post->cover_path) : null,
            'metaTitle' => $post->meta_title,
            'metaDescription' => $post->meta_description,
        ]]);
    }

    public function store(SaveBlogPostRequest $request): JsonResponse
    {
        $post = $this->writer->save(null, $this->payload($request), $request->user());

        return response()->json(['message' => 'مقاله ذخیره شد.', 'id' => $post->id, 'slug' => $post->slug], 201);
    }

    public function update(SaveBlogPostRequest $request, BlogPost $post): JsonResponse
    {
        $post = $this->writer->save($post, $this->payload($request), $request->user());

        return response()->json(['message' => 'مقاله ذخیره شد.', 'id' => $post->id, 'slug' => $post->slug]);
    }

    /** حذفِ کامل — تصویرهای بی‌استفاده، برچسبِ بی‌مقاله، و بی‌بازگشت با `blog:import` (مرحلهٔ ۸ · فاز ۳) */
    public function destroy(BlogPost $post, BlogDeleter $deleter): JsonResponse
    {
        $deleter->delete($post);

        return response()->json(['message' => 'مقاله حذف شد.']);
    }

    /** تصویرِ مقاله: نامِ فایل و آدرسِ عمومی‌اش، و خطِ مارک‌داونِ آماده. */
    public function image(UploadBlogImageRequest $request): JsonResponse
    {
        $path = $request->file('image')->store('blog', PrivateFiles::name());
        $file = basename((string) $path);
        $url = BlogRenderer::MEDIA_PREFIX.$file;

        return response()->json([
            'file' => $file,
            'url' => $url,
            'markdown' => '![]('.$url.')',
        ], 201);
    }

    /** @return array<string, mixed> */
    private function payload(SaveBlogPostRequest $request): array
    {
        $data = $request->validated();

        return [
            ...$data,
            'slug' => $data['slug'] ?? null,
            'tags' => $data['tags'] ?? [],
            'cover_path' => isset($data['cover']) ? 'blog/'.$data['cover'] : null,
        ];
    }
}
