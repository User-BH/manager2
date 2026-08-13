<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ساختِ صفحه‌های آغازینِ iOS از روی `config/pwa.php`.
 *
 * ─── چرا دستور، و نه فایل‌هایی که یک‌بار ساخته و فراموش شوند ────────────────
 * فهرستِ دستگاه‌ها در config است و هر بار که سطری اضافه شود باید فایلش هم
 * ساخته شود. اگر این کار دستی باشد، همان سطرِ تازه بی‌صدا به فایلِ ناموجود
 * اشاره می‌کند — و iOS به‌جای خطا، صفحه‌ی سفید نشان می‌دهد. با یک دستور،
 * ساختنِ دوباره‌شان یک خط است و تست هم می‌تواند نبودِ فایل را بگیرد.
 */
class GenerateSplashScreens extends Command
{
    protected $signature = 'pwa:splash {--force : بازنویسیِ فایل‌های موجود}';

    protected $description = 'ساخت تصاویر صفحه‌ی آغازین iOS از روی config/pwa.php';

    public function handle(): int
    {
        $logoPath = public_path('icons/icon-512.png');

        if (! is_file($logoPath)) {
            $this->error('آیکونِ منبع پیدا نشد: '.$logoPath);

            return self::FAILURE;
        }

        $directory = public_path('icons/splash');

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->error('پوشه‌ی مقصد ساخته نشد: '.$directory);

            return self::FAILURE;
        }

        $logo = imagecreatefrompng($logoPath);

        if ($logo === false) {
            $this->error('آیکونِ منبع خوانده نشد.');

            return self::FAILURE;
        }

        $logo = $this->dropOuterWhite($logo);

        [$r, $g, $b] = $this->rgb((string) config('pwa.splash_background', '#0f1411'));

        $made = 0;
        $skipped = 0;

        foreach ((array) config('pwa.splash', []) as $splash) {
            $width = (int) $splash['width'] * (int) $splash['ratio'];
            $height = (int) $splash['height'] * (int) $splash['ratio'];
            $target = public_path(ltrim((string) $splash['href'], '/'));

            if (is_file($target) && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));

            /*
             * لوگو یک‌سومِ کوچک‌ترین بُعد است.
             *
             * نسبتِ ثابت (نه اندازه‌ی ثابت) لازم است چون همین یک تصویر هم
             * روی iPhone SE دیده می‌شود هم روی iPad Pro؛ با اندازه‌ی ثابت،
             * روی یکی غول می‌شد و روی دیگری نقطه.
             */
            $size = (int) (min($width, $height) / 3);
            $x = (int) (($width - $size) / 2);
            $y = (int) (($height - $size) / 2);

            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $logo, $x, $y, 0, 0, $size, $size, imagesx($logo), imagesy($logo));

            imagepng($canvas, $target, 9);
            imagedestroy($canvas);

            $made++;
        }

        imagedestroy($logo);

        $this->info("صفحه‌ی آغازین: {$made} ساخته شد، {$skipped} از قبل موجود بود.");

        return self::SUCCESS;
    }

    /**
     * شفاف‌کردنِ زمینه‌ی سفیدِ **بیرونیِ** آیکون.
     *
     * ─── چرا لازم شد ───────────────────────────────────────────────────────
     * آیکونِ منبع زمینه‌ی سفیدِ مات دارد. اولین باری که splash را ساختم و
     * نگاهش کردم، وسطِ صفحه‌ی تیره یک **مربعِ سفید** نشسته بود که شبیه
     * اشتباهِ رندر به‌نظر می‌رسید، نه طراحی.
     *
     * ⚠️ راهِ ساده — «هر پیکسلِ سفید شفاف شود» — پنجره‌های ساختمان و درِ
     * آسانسور را هم سوراخ می‌کند، چون آن‌ها هم سفیدند. پس از لبه‌ها
     * flood-fill می‌شود تا فقط ناحیه‌ی سفیدِ **متصل به بیرون** برداشته شود.
     *
     * @param  \GdImage  $source
     * @return \GdImage
     */
    private function dropOuterWhite($source)
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        $seen = [];
        $queue = [];

        $isWhite = function (int $x, int $y) use ($canvas): bool {
            $color = imagecolorat($canvas, $x, $y);

            return (($color >> 16) & 0xFF) > 236
                && (($color >> 8) & 0xFF) > 236
                && ($color & 0xFF) > 236
                && (($color >> 24) & 0x7F) < 64;
        };

        for ($x = 0; $x < $width; $x++) {
            foreach ([0, $height - 1] as $y) {
                if ($isWhite($x, $y)) {
                    $queue[] = [$x, $y];
                }
            }
        }

        for ($y = 0; $y < $height; $y++) {
            foreach ([0, $width - 1] as $x) {
                if ($isWhite($x, $y)) {
                    $queue[] = [$x, $y];
                }
            }
        }

        while ($queue !== []) {
            [$x, $y] = array_pop($queue);
            $key = $y * $width + $x;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            imagesetpixel($canvas, $x, $y, $transparent);

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;

                if ($nx >= 0 && $nx < $width && $ny >= 0 && $ny < $height
                    && ! isset($seen[$ny * $width + $nx]) && $isWhite($nx, $ny)) {
                    $queue[] = [$nx, $ny];
                }
            }
        }

        return $canvas;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
