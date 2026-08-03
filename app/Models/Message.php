<?php

namespace App\Models;

use App\Enums\MessageAudience;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property MessageAudience $audience
 * @property int|null $unit_id
 */
class Message extends Model
{
    use BelongsToComplex;

    protected $fillable = [
        'complex_id', 'user_id', 'body', 'audience', 'unit_id',
        'author_name', 'author_role', 'unit_label',
        'is_hidden', 'hidden_by',
    ];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'audience' => MessageAudience::class,
        ];
    }

    /**
     * واحدهای گیرنده — فقط برای پیامِ `units`.
     *
     * @return BelongsToMany<Unit, $this>
     */
    public function recipientUnits(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'message_units');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * پیام‌هایی که این کاربر حق دیدنشان را دارد (R23).
     *
     * ─── چرا اینجا و نه در کنترلر ──────────────────────────────────────────
     * این تنها جایی است که تصمیم می‌گیرد چه کسی چه پیامی را می‌بیند. اگر در
     * کنترلر می‌ماند، هر مسیرِ تازه‌ای که روزی پیام‌ها را بخواند (جست‌وجو،
     * خروجی، اعلان) باید همین شرط را دوباره و درست می‌نوشت — و یکی‌شان
     * نمی‌نوشت.
     *
     * مدیر همه‌ی مجتمعِ خودش را می‌بیند. ساکن سه چیز می‌بیند:
     *   ۱. پیام‌های عمومی
     *   ۲. رشته‌ی گفت‌وگوی واحدهای خودش با مدیریت
     *   ۳. پیام‌هایی که مدیر مشخصاً به واحدهای او فرستاده
     *
     * @param  Builder<Message>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $unitIds = $user->units()->pluck('units.id')->all();

        $query->where(function (Builder $inner) use ($unitIds, $user): void {
            $inner->where('audience', MessageAudience::All->value);

            /*
             * هرکس پیامِ **خودش** را می‌بیند، حتی اگر به هیچ واحدی وصل نباشد.
             * بدونِ این، ساکنِ بدونِ واحد پیام می‌فرستاد و بلافاصله ناپدید
             * می‌شد — از دیدِ خودش انگار اصلاً ارسال نشده.
             */
            $inner->orWhere('user_id', $user->id);

            if ($unitIds === []) {
                return;
            }

            $inner
                ->orWhere(fn (Builder $q) => $q
                    ->where('audience', MessageAudience::Management->value)
                    ->whereIn('unit_id', $unitIds))
                ->orWhere(fn (Builder $q) => $q
                    ->where('audience', MessageAudience::Units->value)
                    ->whereHas('recipientUnits', fn ($r) => $r->whereIn('units.id', $unitIds)));
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
