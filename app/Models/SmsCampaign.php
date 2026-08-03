<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک بار مصرفِ سهمیه‌ی ماهانه‌ی پیامک (R27).
 *
 * وجودِ ردیف برای یک (مجتمع، دوره) یعنی سهمیه‌ی آن ماه مصرف شده. شمارش از
 * روی همین است و نه یک شمارنده‌ی جدا، چون شمارنده می‌تواند با ردیف‌ها
 * ناسازگار شود.
 *
 * @property int $id
 * @property int $complex_id
 * @property string $period
 * @property int|null $sent_by
 * @property int $recipients
 * @property int $delivered
 * @property int $failed
 * @property string $template
 * @property Carbon $created_at
 */
class SmsCampaign extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'period', 'sent_by',
        'recipients', 'delivered', 'failed', 'template',
    ];

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
