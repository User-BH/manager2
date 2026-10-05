<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * بازخوردِ صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴).
 *
 * سطحِ پلتفرم است و `BelongsToComplex` ندارد: فرستنده ممکن است حساب نداشته
 * باشد و فقط ادمینِ کل می‌خواندش (مسیرهای `role:super_admin`).
 *
 * @property int $id
 * @property FeedbackCategory $category
 * @property string $message
 * @property string|null $name
 * @property string|null $phone
 * @property int|null $user_id
 * @property FeedbackStatus $status
 * @property string $message_hash
 * @property string $sender_hash
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    protected $fillable = ['category', 'message', 'name', 'phone', 'user_id', 'status', 'message_hash', 'sender_hash'];

    protected $hidden = ['message_hash', 'sender_hash'];

    protected function casts(): array
    {
        return [
            'category' => FeedbackCategory::class,
            'status' => FeedbackStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * پاسخ‌های ادمینِ کل، به ترتیبِ زمان (مرحلهٔ ۸ · فاز ۶)
     *
     * @return HasMany<FeedbackReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(FeedbackReply::class)->orderBy('id');
    }
}
