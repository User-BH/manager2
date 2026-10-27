<?php

namespace App\Models;

use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک یادآورِ تقویم — یک‌باره یا سریِ تکرارشونده (مرحلهٔ ۱۳ · فاز ۷؛ ایرادات ۱۰، بندهای ۷۷ تا ۹۳).
 *
 * نمونه‌ها از قاعده ساخته می‌شوند، نه از ردیف: نمونه‌ی kام = `target_date + k × repeat_every_days`، تا `repeat_until` یا
 * `repeat_count`، بی آن‌هایی که در `reminder_skips` کنار گذاشته شده‌اند. تاریخ‌ها **تاریخِ تقویمیِ خالص**اند («Y-m-d»)؛
 * فقط لحظه‌ی یادآوری ساعت و منطقه دارد (`remindAtFor`): ساعتِ `remind_time` ِ تهران، `lead_days` روز پیش از رویداد، به UTC.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $complex_id
 * @property string $title
 * @property string|null $description
 * @property CarbonImmutable $target_date
 * @property int $lead_days
 * @property string $remind_time
 * @property int|null $repeat_every_days
 * @property CarbonImmutable|null $repeat_until
 * @property int|null $repeat_count
 * @property CarbonImmutable|null $next_occurrence
 * @property CarbonImmutable|null $next_remind_at
 * @property CarbonImmutable|null $last_notified_at
 * @property int $notified_count
 */
class Reminder extends Model
{
    /** سقفِ نمونه‌هایی که یک پرسش بازسازی می‌کند — بازه‌ی تقویم چند هفته است، نه سال‌ها */
    private const MAX_EXPANSION = 400;

    protected $fillable = [
        'title', 'description', 'target_date', 'lead_days', 'remind_time',
        'repeat_every_days', 'repeat_until', 'repeat_count',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'immutable_date',
            'repeat_until' => 'immutable_date',
            'next_occurrence' => 'immutable_date',
            'next_remind_at' => 'immutable_datetime',
            'last_notified_at' => 'immutable_datetime',
            'lead_days' => 'integer',
            'repeat_every_days' => 'integer',
            'repeat_count' => 'integer',
            'notified_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ReminderSkip, $this> */
    public function skips(): HasMany
    {
        return $this->hasMany(ReminderSkip::class);
    }

    public function isRecurring(): bool
    {
        return $this->repeat_every_days !== null && $this->repeat_every_days > 0;
    }

    /** تاریخِ نمونه‌ی kام، یا `null` اگر پس از پایانِ سری است — کنارگذاشته‌ها این‌جا حساب نمی‌شوند */
    public function occurrence(int $k): ?string
    {
        if ($k < 0 || (! $this->isRecurring() && $k > 0)) {
            return null;
        }
        if ($this->repeat_count !== null && $k >= $this->repeat_count) {
            return null;
        }

        $date = $this->day($this->target_date)->addDays($k * (int) $this->repeat_every_days);

        if ($this->repeat_until !== null && $date->gt($this->day($this->repeat_until))) {
            return null;
        }

        return $date->toDateString();
    }

    /**
     * نمونه‌های میانِ دو تاریخ (هر دو شامل)، بی کنارگذاشته‌ها — برای نشانگرِ روزهای تقویم و فهرستِ روز (بندِ ۹۳).
     *
     * @return list<string>
     */
    public function occurrencesBetween(string $from, string $to): array
    {
        $start = $this->day($from);
        $end = $this->day($to);
        $first = $this->day($this->target_date);

        // از نزدیک‌ترین نمونه به ابتدای بازه شروع می‌شود، نه از نمونه‌ی صفرم (سریِ بی‌پایانِ چندساله)
        $k = $this->isRecurring() && $start->gt($first)
            ? intdiv((int) $first->diffInDays($start), (int) $this->repeat_every_days)
            : 0;

        $skipped = $this->skippedDates();
        $found = [];

        for ($guard = 0; $guard < self::MAX_EXPANSION; $guard++, $k++) {
            $date = $this->occurrence($k);
            if ($date === null || $this->day($date)->gt($end)) {
                break;
            }
            if ($this->day($date)->gte($start) && ! in_array($date, $skipped, true)) {
                $found[] = $date;
            }
        }

        return $found;
    }

    /** لحظه‌ی یادآوریِ یک نمونه، به UTC: ساعتِ تهران، `lead_days` روز پیش از رویداد (بندِ ۱۰۰) */
    public function remindAtFor(string $occurrence): CarbonImmutable
    {
        return CarbonImmutable::parse($occurrence.' '.$this->remind_time, Jalali::zone())
            ->subDays($this->lead_days)
            ->utc();
    }

    /**
     * نخستین نمونه‌ی کنارنگذاشته‌ای که یادآوری‌اش هنوز نرسیده (پس از `$now`) — برای زمان‌بند. نمونه‌ای که لحظه‌اش گذشته
     * دوباره فرستاده نمی‌شود؛ پس سرورِ خاموش در بازگشت سیلی از یادآورِ کهنه نمی‌فرستد.
     *
     * @return array{0: string, 1: CarbonImmutable}|null
     */
    public function nextAfter(CarbonImmutable $now): ?array
    {
        $first = $this->day($this->target_date);
        $today = $now->setTimezone(Jalali::zone())->startOfDay();
        $k = 0;
        if ($this->isRecurring()) {
            // نمونه‌ای که یادآوری‌اش امروز یا پس از آن است: رویداد ≥ امروز + پیش‌آگهی
            $earliest = $this->day($today->toDateString())->addDays($this->lead_days);
            $k = $earliest->gt($first) ? max(0, intdiv((int) $first->diffInDays($earliest), (int) $this->repeat_every_days) - 1) : 0;
        }

        $skipped = $this->skippedDates();
        for ($guard = 0; $guard < self::MAX_EXPANSION; $guard++, $k++) {
            $date = $this->occurrence($k);
            if ($date === null) {
                return null;
            }
            $at = $this->remindAtFor($date);
            if ($at->gt($now) && ! in_array($date, $skipped, true)) {
                return [$date, $at];
            }
        }

        return null;
    }

    /** نشانگرِ زمان‌بند را از قاعده و امروز از نو می‌سازد (پس از ساخت، ویرایش یا کنارگذاشتن) */
    public function reschedule(?CarbonImmutable $now = null): void
    {
        $next = $this->nextAfter($now ?? CarbonImmutable::now());
        $this->forceFill([
            'next_occurrence' => $next[0] ?? null,
            'next_remind_at' => $next[1] ?? null,
        ]);
    }

    /** وضعیت در تاریخچه (بندِ ۸۹) */
    public function status(): string
    {
        return match (true) {
            $this->next_remind_at !== null => 'scheduled',
            $this->notified_count > 0 => 'done',
            default => 'ended',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            'scheduled' => 'زمان‌بندی‌شده',
            'done' => 'یادآوری شد',
            default => 'پایان‌یافته',
        };
    }

    public function repeatLabel(): string
    {
        if (! $this->isRecurring()) {
            return 'یک‌باره';
        }

        $every = 'هر '.Jalali::digits((int) $this->repeat_every_days).' روز';

        return match (true) {
            $this->repeat_until !== null => $every.' تا '.Jalali::date($this->repeat_until->toDateString()),
            $this->repeat_count !== null => $every.'، '.Jalali::digits((int) $this->repeat_count).' بار',
            default => $every.'، بی‌پایان',
        };
    }

    /** @return list<string> */
    private function skippedDates(): array
    {
        return $this->skips->map(fn (ReminderSkip $skip) => $skip->occurrence->toDateString())->all();
    }

    /** تاریخِ تقویمیِ خالص: نیمه‌شبِ UTC، بی اثرِ منطقه */
    private function day(CarbonImmutable|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date instanceof CarbonImmutable ? $date->toDateString() : $date, 'UTC')->startOfDay();
    }
}
