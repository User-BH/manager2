<?php

namespace App\Services\Backup;

use App\Support\PrivateFiles;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * مقصدِ بکاپ **بیرون از سرورِ اصلی** (R58).
 *
 * ─── 🔴 شکافی که پر می‌کند ─────────────────────────────────────────────────
 * ⚠️ همه‌ی بکاپ‌ها روی همان دیسکی می‌نشستند که خودِ داده روی آن است. این
 * در برابرِ «مدیر اشتباهی چیزی را پاک کرد» کار می‌کند و در برابرِ «سرور
 * مُرد» **هیچ**: دیسک که برود، داده و همه‌ی نسخه‌های پشتیبانش با هم رفته‌اند.
 *
 * ─── چرا هیچ پکیجی اضافه نشد ───────────────────────────────────────────────
 * وسوسه‌ی طبیعی، افزودنِ درایورِ S3 است. ولی آن یعنی یک وابستگیِ تازه برای
 * نصبی که یک سرور دارد. به‌جایش این کلاس **نامِ یک دیسکِ لاراول** را
 * می‌گیرد، هرچه باشد. پس:
 *
 *   - امروز، بدونِ هیچ پکیجی: یک مسیرِ mountشده (rclone / NFS / SSHFS)
 *     که با درایورِ `local` معرفی می‌شود.
 *   - فردا، اگر خواستید: `composer require league/flysystem-aws-s3-v3`
 *     و `BACKUP_OFFSITE_DISK=s3`. هیچ خطی از این کد عوض نمی‌شود.
 *
 * ─── ⚠️ و یک تله که صریح سنجیده می‌شود ─────────────────────────────────────
 * «دیسکِ دوم» با «خارج از سرور» یکی نیست. اگر کسی
 * `BACKUP_OFFSITE_ROOT=/var/backups` بگذارد، احساسِ امنیت می‌کند و در
 * عمل هر دو نسخه روی همان دستگاه‌اند.
 *
 * `sameDeviceAsPrivate()` شماره‌ی دستگاهِ فایل‌سیستم را مقایسه می‌کند و
 * `backups:verify` هشدار می‌دهد. اگر اندازه‌گیری ممکن نباشد `null`
 * برمی‌گرداند — **حدس نمی‌زند**.
 */
class OffsiteTarget
{
    /** نامِ دیسکِ مقصد، یا `null` اگر تنظیم نشده باشد. */
    public static function name(): ?string
    {
        $disk = config('backup.offsite.disk');

        return is_string($disk) && trim($disk) !== '' ? trim($disk) : null;
    }

    public static function configured(): bool
    {
        return self::name() !== null;
    }

    public static function disk(): Filesystem
    {
        $name = self::name();

        if ($name === null) {
            throw new RuntimeException(
                'مقصدِ بکاپِ خارج از سرور تنظیم نشده است (BACKUP_OFFSITE_DISK). '
                .'رویه در docs/DISASTER_RECOVERY.md آمده است.',
            );
        }

        /*
         * ⚠️ «آیا در config هست؟» با «آیا حل می‌شود؟» یکی نیست.
         *
         * نسخه‌ی اول همان بررسیِ config را می‌کرد و تستِ خودم لوش داد:
         * دیسکی که در زمانِ اجرا ثبت شده ولی در فایلِ config نیست، کاملاً
         * سالم کار می‌کند و آن بررسی ردش می‌کرد. سنجیدنِ چیزی که واقعاً
         * اهمیت دارد — حل‌شدنِ دیسک — هم درست‌تر است و هم پیامِ بهتری
         * می‌دهد.
         */
        try {
            return Storage::disk($name);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                "دیسکِ «{$name}» شناخته نشد: ".$e->getMessage()
                .' — آن را در config/filesystems.php تعریف کنید.',
            );
        }
    }

    /** مسیرِ کاملِ یک فایل داخلِ مقصد. */
    public static function path(string $file): string
    {
        $prefix = trim((string) config('backup.offsite.path', ''), '/');

        return $prefix === '' ? $file : $prefix.'/'.$file;
    }

    /**
     * آیا مقصد روی همان دستگاهِ فایل‌سیستمِ داده‌هاست؟
     *
     * @return bool|null `null` یعنی قابلِ اندازه‌گیری نبود
     */
    public static function sameDeviceAsPrivate(): ?bool
    {
        $target = self::name();

        if ($target === null) {
            return null;
        }

        $private = PrivateFiles::name();

        // مقصدِ ابری/راه‌دور اصلاً دستگاهِ محلی ندارد
        if (config("filesystems.disks.{$target}.driver") !== 'local'
            || config("filesystems.disks.{$private}.driver") !== 'local') {
            return null;
        }

        $a = self::deviceOf((string) config("filesystems.disks.{$target}.root"));
        $b = self::deviceOf((string) config("filesystems.disks.{$private}.root"));

        if ($a === null || $b === null) {
            return null;
        }

        return $a === $b;
    }

    /**
     * شماره‌ی دستگاهِ فایل‌سیستمِ یک مسیر — با بالارفتن تا اولین پوشه‌ی موجود.
     *
     * پوشه‌ی مقصد ممکن است هنوز ساخته نشده باشد؛ والدش هم همان دستگاه است.
     */
    private static function deviceOf(string $path): ?int
    {
        $path = rtrim($path, '/'.DIRECTORY_SEPARATOR);

        for ($i = 0; $i < 8 && $path !== ''; $i++) {
            $stat = @stat($path);

            if (is_array($stat)) {
                return (int) $stat['dev'];
            }

            $parent = dirname($path);

            if ($parent === $path) {
                break;
            }

            $path = $parent;
        }

        return null;
    }
}
