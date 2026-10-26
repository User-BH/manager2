<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\DeviceLabel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * نشست‌های فعالِ یک کاربر: فهرست و پایان‌دادن (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ─── چرا این ممکن است و در JWT نبود ─────────────────────────────────────────
 * نشست‌ها ردیف‌های جدولِ `sessions` هستند. پایان‌دادن به یک نشست یعنی حذفِ
 * ردیفش؛ درخواستِ بعدیِ همان مرورگر دیگر کاربری پیدا نمی‌کند. همین لحظه، نه
 * «وقتی توکن منقضی شد».
 *
 * 🔴 **شناسه‌ی نشست خودش راز است** — همان چیزی که در کوکی است و هر کس
 * داشته باشد جای کاربر می‌نشیند. پس هرگز به مرورگر نمی‌رود؛ به‌جایش
 * `publicId` (HMAC با کلیدِ برنامه) نشان داده می‌شود که نمی‌شود برعکسش کرد.
 *
 * ⚠️ فقط با درایورِ `database` کار می‌کند — درایورِ همه‌ی محیط‌ها (محافظ:
 * `SessionManagementTest`). روی درایورِ دیگر `supported()` نادرست است و
 * رابط به‌جای وانمود، صریح می‌گوید مدیریتِ نشست در دسترس نیست.
 */
class SessionRegistry
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    public function publicId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /**
     * @return list<array{id: string, device: string, ip: ?string, lastActiveAt: string, current: bool}>
     */
    public function forUser(User $user, string $currentId): array
    {
        if (! $this->supported()) {
            return [];
        }

        $idleCutoff = now()->subMinutes((int) config('session.lifetime'))->timestamp;

        return $this->table()
            ->where('user_id', $user->id)
            // نشستِ بیکارمانده دیگر معتبر نیست حتی اگر ردیفش هنوز پاک نشده
            ->where('last_activity', '>=', $idleCutoff)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'id' => $this->publicId($row->id),
                'device' => DeviceLabel::from($row->user_agent),
                'ip' => $row->ip_address !== null ? (string) $row->ip_address : null,
                'lastActiveAt' => Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
                'current' => hash_equals($row->id, $currentId),
            ])
            ->values()
            ->all();
    }

    /**
     * پایانِ یک نشستِ دیگرِ همین کاربر. نشستِ جاری با این راه بسته نمی‌شود —
     * برای آن «خروج» هست.
     */
    public function end(User $user, string $publicId, string $currentId): bool
    {
        if (! $this->supported()) {
            return false;
        }

        $target = $this->table()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->first(fn (string $id): bool => hash_equals($this->publicId($id), $publicId));

        if ($target === null || hash_equals($target, $currentId)) {
            return false;
        }

        return $this->table()->where('id', $target)->delete() > 0;
    }

    /** پایانِ همه‌ی نشست‌های کاربر جز نشستِ جاری. */
    public function endOthers(User $user, string $currentId): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return $this->table()
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentId)
            ->delete();
    }

    /** پایانِ همه‌ی نشست‌های کاربر — بی نشستِ جاری (فرمانِ سرور، مثلِ `preview:reset-account`). */
    public function endAll(User $user): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return $this->table()->where('user_id', $user->id)->delete();
    }

    private function table(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }
}
