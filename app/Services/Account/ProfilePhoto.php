<?php

namespace App\Services\Account;

use App\Exceptions\DomainException;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Support\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * تصویرِ پروفایل — یک تصویرِ canonical برای هر حساب (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۵ ِ فایلِ معماری).
 *
 * ─── چرا دوباره رمزگذاری می‌شود ──────────────────────────────────────────────
 * عکسِ گوشی EXIF دارد — گاهی با مختصاتِ جایی که گرفته شده. این تصویر به همسایه‌ها نشان داده می‌شود؛ پس فایلِ
 * کاربر هرگز همان‌طور نگه داشته نمی‌شود: با GD باز، مربعِ وسط بریده، ۲۵۶×۲۵۶ و JPEG ِ تازه ذخیره می‌شود. هیچ
 * metadata ای از فایلِ اصلی به خروجی نمی‌رسد، حجم کران‌دار است، و فایلی که تصویرِ واقعی نیست رد می‌شود.
 *
 * ─── چه کسی می‌بیند ─────────────────────────────────────────────────────────
 * خودِ شخص، ادمینِ کل، و هر کسی که در مجتمعی **عضوِ فعال** است که این شخص در آن عضو است یا بوده — تا پیام‌های
 * قدیمیِ ساکنِ رفته همچنان چهره‌ی نویسنده‌شان را داشته باشند (سابقه حفظ می‌شود)، ولی غریبه‌ای از مجتمعِ دیگر
 * هرگز. پاسخِ «نمی‌بینی» و «نیست» یکسان (۴۰۴) است.
 */
final class ProfilePhoto
{
    public const SIZE = 256;

    /** بیش از این پیکسل باز نمی‌شود — جلوی بمبِ تصویری (فایلِ کوچک با ابعادِ عظیم) */
    private const MAX_PIXELS = 40_000_000;

    public function store(User $user, UploadedFile $file): User
    {
        $jpeg = $this->encode((string) file_get_contents($file->getRealPath()));
        $path = 'avatars/'.$user->id.'/'.Str::random(40).'.jpg';
        $disk = Storage::disk(PrivateFiles::name());

        $disk->put($path, $jpeg);

        try {
            $previous = DB::transaction(function () use ($user, $path): ?string {
                // `User` دامنه‌ی مستأجر ندارد؛ قفل برای دو بارگذاریِ هم‌زمان
                $account = User::query()->lockForUpdate()->findOrFail($user->id);
                $old = $account->avatar_path;
                $account->forceFill(['avatar_path' => $path])->save();
                $user->setRawAttributes($account->getAttributes(), true);

                return $old;
            }, attempts: 3);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $user;
    }

    public function remove(User $user): void
    {
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();

        if ($previous !== null) {
            Storage::disk(PrivateFiles::name())->delete($previous);
        }
    }

    /** نشانیِ تصویر با نسخه (تا تعویض همان لحظه در همه‌ی کش‌ها دیده شود) — یا null */
    public static function url(?User $user): ?string
    {
        if ($user === null || $user->avatar_path === null) {
            return null;
        }

        return route('api.users.avatar', ['user' => $user->id, 'v' => substr(sha1($user->avatar_path), 0, 12)], false);
    }

    public static function canView(User $viewer, User $subject): bool
    {
        if ($viewer->id === $subject->id || $viewer->isSuperAdmin()) {
            return true;
        }

        return DB::table('complex_memberships as mine')
            ->join('complex_memberships as theirs', 'theirs.complex_id', '=', 'mine.complex_id')
            ->where('mine.user_id', $viewer->id)
            ->where('mine.status', ComplexMembership::ACTIVE)
            ->where('theirs.user_id', $subject->id)
            ->exists();
    }

    private function encode(string $bytes): string
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw $this->invalid();
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw $this->invalid();
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $side = min($width, $height);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        // پس‌زمینه‌ی سفید برای PNG/WebP ِ شفاف (JPEG شفافیت ندارد)
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        imagejpeg($target, null, 85);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $jpeg;
    }

    private function invalid(): DomainException
    {
        return DomainException::invalid('این فایل تصویرِ قابلِ خواندنی نیست.', 'profile.avatar_invalid', [
            'avatar' => ['این فایل تصویرِ قابلِ خواندنی نیست.'],
        ]);
    }
}
