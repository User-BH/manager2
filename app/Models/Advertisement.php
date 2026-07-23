<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * بنر تبلیغاتی صفحه‌ی فرود.
 *
 * برخلاف بیشتر مدل‌های پروژه، این یکی `BelongsToComplex` ندارد: صفحه‌ی فرود
 * عمومی است و پیش از ورود کاربر (و پیش از انتخاب مجتمع) دیده می‌شود، پس
 * تبلیغات سطح پلتفرم‌اند و مدیریتشان با ادمین کل است.
 */
class Advertisement extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'href', 'image_path', 'image_url',
        'is_active', 'sort_order', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // فایل آپلودشده نباید پس از حذف رکورد روی دیسک بماند
        static::deleting(function (self $ad) {
            if ($ad->image_path) {
                Storage::disk('local')->delete($ad->image_path);
            }
        });
    }

    /**
     * تبلیغاتی که همین حالا باید روی صفحه‌ی فرود دیده شوند.
     *
     * بازه‌ی خالی یعنی بدون محدودیت زمانی، پس شرط تاریخ فقط وقتی اعمال
     * می‌شود که مقدار داشته باشد.
     */
    public function scopeVisible(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * آدرس تصویر برای نمایش در مرورگر.
     *
     * فایل‌های آپلودی روی دیسک خصوصی می‌نشینند و از یک مسیر کنترل‌شده سرو
     * می‌شوند تا نیازی به symlink پوشه‌ی storage در زمان استقرار نباشد؛
     * نبودِ آن symlink باعث می‌شد تصاویر بی‌صدا خراب شوند.
     */
    public function displayImageUrl(): ?string
    {
        if ($this->image_path) {
            // updated_at در آدرس می‌آید تا با تعویض تصویر، کشِ مرورگر بشکند
            return route('ads.image', ['advertisement' => $this->id, 'v' => $this->updated_at?->timestamp]);
        }

        return $this->image_url;
    }

    /** آیا این تبلیغ همین حالا روی صفحه‌ی فرود دیده می‌شود؟ */
    public function isLive(): bool
    {
        $now = Carbon::now();

        return $this->is_active
            && (! $this->starts_at || $this->starts_at->lte($now))
            && (! $this->ends_at || $this->ends_at->gt($now));
    }
}
