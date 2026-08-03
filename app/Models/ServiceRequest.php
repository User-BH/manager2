<?php

namespace App\Models;

use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Models\Concerns\BelongsToComplex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * درخواستِ ساکن (R25).
 *
 * @property int $id
 * @property int $complex_id
 * @property int|null $unit_id
 * @property int $user_id
 * @property int|null $assigned_to
 * @property ServiceRequestCategory $category
 * @property ServiceRequestPriority $priority
 * @property ServiceRequestStatus $status
 * @property string $title
 * @property string $description
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 */
class ServiceRequest extends Model
{
    use BelongsToComplex;
    use SoftDeletes;

    protected $fillable = [
        'complex_id', 'unit_id', 'user_id', 'assigned_to',
        'category', 'priority', 'status',
        'title', 'description',
        'attachment_path', 'attachment_name',
        'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => ServiceRequestCategory::class,
            'priority' => ServiceRequestPriority::class,
            'status' => ServiceRequestStatus::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<ServiceRequestComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(ServiceRequestComment::class)->orderBy('id');
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    /**
     * درخواست‌هایی که این کاربر حق دیدنشان را دارد.
     *
     * ─── سه دایره، نه دو ───────────────────────────────────────────────────
     * مدیر همه را می‌بیند. ساکن درخواست‌های **واحد خودش** را می‌بیند — نه
     * فقط آن‌هایی که خودش ثبت کرده — چون مالک و مستاجرِ یک واحد یک پرونده
     * دارند و اگر مستاجر درخواست بدهد و برود، مالک باید سابقه را ببیند.
     *
     * دایره‌ی سوم **مسئول** است: کسی که درخواستی به او واگذار شده باید
     * بتواند ببیندش، حتی اگر مالِ واحدِ دیگری باشد. بدونِ این، واگذاری به
     * ساکنی که عملاً سرایدار است بی‌فایده می‌شد.
     *
     * @param  Builder<ServiceRequest>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $unitIds = $user->units()->pluck('units.id')->all();

        $query->where(function (Builder $inner) use ($unitIds, $user): void {
            $inner->where('user_id', $user->id)
                ->orWhere('assigned_to', $user->id);

            if ($unitIds !== []) {
                $inner->orWhereIn('unit_id', $unitIds);
            }
        });
    }

    /** فقط درخواست‌های باز — شمارنده‌ی داشبورد و فهرستِ پیش‌فرضِ مدیر. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (ServiceRequestStatus $s) => $s->value,
            array_filter(ServiceRequestStatus::cases(), fn ($s) => $s->isOpen()),
        ));
    }
}
