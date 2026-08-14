<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * یک اندازه‌گیریِ Core Web Vitals از دستگاهِ یک کاربرِ واقعی (R38).
 *
 * عمداً `BelongsToComplex` ندارد و `updated_at` هم ندارد: این ردیف هرگز
 * ویرایش نمی‌شود و داده‌ی عملیاتیِ پلتفرم است، نه دادهٔ یک مجتمع.
 *
 * @property int $id
 * @property string $metric
 * @property float $value
 * @property string $rating
 * @property string $path
 * @property string $device
 * @property Carbon|null $created_at
 */
class WebVital extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['metric', 'value', 'rating', 'path', 'device'];

    protected function casts(): array
    {
        return ['value' => 'float', 'created_at' => 'datetime'];
    }

    /**
     * صدکِ ۷۵ — همان معیاری که گوگل با آن قضاوت می‌کند.
     *
     * ⚠️ میانگین اینجا **جواب اشتباه** می‌دهد: سایتی که ۷۰٪ کاربرانش تجربه‌ی
     * عالی و ۳۰٪ تجربه‌ی افتضاح دارند میانگینِ قابلِ‌قبول می‌گیرد ولی در
     * Search Console مردود است. صدک آن دُمِ بد را نشان می‌دهد.
     *
     * محاسبه در PHP و نه SQL: `PERCENTILE_CONT` در MySQL 8 نیست و در
     * SQLite (که تست‌ها با آن اجرا می‌شوند) اصلاً وجود ندارد.
     */
    public static function percentile75(string $metric, string $device, int $days = 28): ?float
    {
        $values = static::query()
            ->where('metric', $metric)
            ->where('device', $device)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('value')
            ->pluck('value')
            ->all();

        if ($values === []) {
            return null;
        }

        $index = (int) ceil(0.75 * count($values)) - 1;

        return (float) $values[max(0, $index)];
    }

    /** @param  Builder<WebVital>  $query */
    public function scopeRecent(Builder $query, int $days = 28): void
    {
        $query->where('created_at', '>=', now()->subDays($days));
    }
}
