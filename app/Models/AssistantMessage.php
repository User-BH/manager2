<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک پرسش و پاسخِ دستیار — مالِ یک حساب در یک زمینه (بندِ ۴.۱۴). هیچ‌وقت در پرسشِ بعدی خوانده نمی‌شود؛ فقط برای
 * نمایشِ همان گفت‌وگو به همان حساب در همان زمینه.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $complex_id
 * @property string $context_key
 * @property string $question
 * @property string|null $answer
 * @property string $status
 * @property string|null $tool
 * @property bool $redacted
 * @property Carbon|null $created_at
 */
class AssistantMessage extends Model
{
    protected $fillable = ['user_id', 'complex_id', 'context_key', 'question', 'answer', 'status', 'tool', 'redacted'];

    protected function casts(): array
    {
        return ['redacted' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
