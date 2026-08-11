<?php

namespace App\Models;

use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * یک سندِ تولیدشده در صف (R28).
 *
 * @property int $id
 * @property int $complex_id
 * @property int|null $user_id
 * @property string $type
 * @property string $title
 * @property array<string, mixed>|null $params
 * @property string $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $error
 * @property Carbon $created_at
 */
class GeneratedDocument extends Model
{
    use BelongsToComplex;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = [
        'complex_id', 'user_id', 'type', 'title', 'params',
        'status', 'path', 'size_bytes', 'error',
    ];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->path !== null;
    }
}
