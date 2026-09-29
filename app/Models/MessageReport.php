<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * گزارشِ پیام به مدیریت (مرحلهٔ ۶ · فاز ۱۴).
 *
 * ساکن پیامی را که می‌بیند گزارش می‌کند؛ مدیرِ همان مجتمع پنهانش می‌کند یا
 * گزارش را کنار می‌گذارد. دامنه‌ی مستأجر با `BelongsToComplex`.
 *
 * @property int $id
 * @property int $complex_id
 * @property int $message_id
 * @property int $user_id
 * @property string|null $reason
 * @property string|null $resolution
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
class MessageReport extends Model
{
    use BelongsToComplex;

    public const HIDE = 'hidden';

    public const DISMISS = 'dismissed';

    protected $fillable = ['complex_id', 'message_id', 'user_id', 'reason', 'resolution', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
