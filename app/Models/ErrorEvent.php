<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک خانواده از خطاهای هم‌شکل (نه یک رخدادِ تکی).
 *
 * عمداً از `BelongsToComplex` استفاده **نمی‌کند**: خطاها داده‌ی عملیاتیِ
 * پلتفرم‌اند و فقط ادمینِ کل می‌بیندشان، پس نباید با اسکوپِ مجتمعِ جاری فیلتر
 * شوند — وگرنه ادمینِ کل که مجتمعی انتخاب کرده، نیمی از خطاها را نمی‌دید.
 * `complex_id` اینجا فقط برای زمینه‌ی تشخیص است، نه برای جداسازیِ دسترسی.
 *
 * @property int $id
 * @property string $source
 * @property string $fingerprint
 * @property string $type
 * @property string $message
 * @property string|null $file
 * @property int|null $line
 * @property string|null $stack
 * @property string|null $url
 * @property string|null $method
 * @property int|null $status
 * @property int|null $user_id
 * @property int|null $complex_id
 * @property int $occurrences
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property bool $is_resolved
 * @property-read User|null $user
 * @property-read Complex|null $complex
 */
class ErrorEvent extends Model
{
    protected $fillable = [
        'source',
        'fingerprint',
        'type',
        'message',
        'file',
        'line',
        'stack',
        'url',
        'method',
        'status',
        'user_id',
        'complex_id',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'is_resolved',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_resolved' => 'boolean',
            'occurrences' => 'integer',
            'line' => 'integer',
            'status' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Complex, $this> */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }
}
