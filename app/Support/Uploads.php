<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * ذخیره و سروِ امنِ فایل‌های آپلودی (R19).
 *
 * سه قاعده اینجا جمع شده‌اند چون هر سه‌شان از آن دسته‌اند که در یک مسیر
 * رعایت می‌شوند و در مسیر بعدی یادِ کسی نمی‌ماند.
 */
class Uploads
{
    /**
     * نوع‌هایی که حاضریم برگردانیم — و بس.
     *
     * نگاشت از پسوندِ ذخیره‌شده به نوعِ محتوا. هر چیزی بیرون از این فهرست
     * به `application/octet-stream` می‌افتد، یعنی مرورگر دانلودش می‌کند و
     * هرگز اجرا/رندرش نمی‌کند.
     */
    private const SERVABLE = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    /**
     * فایل را ذخیره می‌کند و اگر کارِ پس از آن شکست بخورد، پاکش می‌کند.
     *
     * ─── مشکلی که حل می‌کند ────────────────────────────────────────────────
     * نوشتنِ فایل تراکنشی نیست. اگر فایل پیش از `INSERT` روی دیسک برود و بعد
     * تراکنش شکست بخورد (یا اعتبارسنجیِ داخلِ تراکنش رد کند)، فایل می‌ماند و
     * **هیچ ردیفی در دیتابیس به آن اشاره نمی‌کند** — یعنی هیچ‌وقت هم پاک
     * نمی‌شود.
     *
     * سنجیده شد نه فرض: ارسالِ دوباره‌ی رسید برای یک قبض، ۴۲۲ می‌گرفت ولی
     * فایلِ ۴ مگابایتی‌اش روی دیسک می‌ماند. با سقفِ ۲۰ آپلود در ساعت، هر
     * ساکن می‌توانست ساعتی ۸۰ مگابایت زباله بسازد.
     *
     * @template T
     *
     * @param  Closure(string): T  $then  کاری که باید موفق شود تا فایل بماند
     * @return T
     */
    public static function keepIf(UploadedFile $file, string $directory, Closure $then, ?string $disk = null): mixed
    {
        $disk ??= PrivateFiles::name();

        $path = $file->store($directory, $disk);

        try {
            return $then($path);
        } catch (Throwable $e) {
            /*
             * پاک‌کردن نباید خطای اصلی را بپوشاند. اگر خودِ حذف هم شکست بخورد،
             * بدترین حالت یک فایلِ یتیم است — نه گم‌شدنِ دلیلِ واقعیِ خطا.
             */
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // عمداً بلعیده می‌شود؛ استثنای اصلی مهم‌تر است
            }

            throw $e;
        }
    }

    /**
     * نامِ اصلیِ فایل، تمیزشده برای ذخیره.
     *
     * نامِ کلاینت کاملاً در اختیارِ فرستنده است و می‌تواند خطِ جدید، نقل‌قول یا
     * مسیر داشته باشد. امروز فقط ذخیره می‌شود، ولی روزی که کسی آن را در هدرِ
     * `Content-Disposition` بگذارد، `\r\n` داخلش یعنی تزریقِ هدر. تمیزکردن در
     * لحظه‌ی نوشتن ارزان‌تر از یادآوری در همه‌ی مصرف‌کننده‌هاست.
     */
    public static function safeOriginalName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '', $name) ?? '';

        return Str::limit(trim($name), 120, '') ?: 'receipt';
    }

    /**
     * سروِ فایلِ ذخیره‌شده با نوعِ محتوای **صریح**.
     *
     * `Storage::response()` نوع را در لحظه‌ی سرو از روی محتوا حدس می‌زند. آن
     * حدس معمولاً درست است، ولی تضمینی که به کاربر می‌دهیم نباید به حدس وابسته
     * باشد: اینجا فقط نوع‌های فهرستِ بالا فرستاده می‌شوند و هر چیز دیگری
     * `octet-stream` می‌شود.
     *
     * همراه با `X-Content-Type-Options: nosniff` (R16) این یعنی فایلی که
     * کاربر آپلود کرده، هر چه باشد، در مرورگر به‌عنوان HTML اجرا نمی‌شود.
     */
    public static function serve(string $path, array $headers = [], ?string $disk = null): StreamedResponse
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = self::SERVABLE[$extension] ?? 'application/octet-stream';

        $disk ??= PrivateFiles::name();

        return Storage::disk($disk)->response($path, null, array_merge([
            'Content-Type' => $contentType,
            /*
             * PDF درون‌خطی می‌ماند و به `attachment` تبدیل نشد: مدیر باید رسید
             * را با یک کلیک ببیند، و نمایشگرِ PDFِ مرورگرها جداسازی‌شده است —
             * جاوااسکریپتِ داخلِ PDF به کوکی و DOMِ سایت نمی‌رسد. هزینه‌ی
             * تبدیلش (دانلودِ اجباریِ هر رسید) از فایده‌اش بیشتر بود.
             */
            'Content-Disposition' => 'inline',
        ], $headers));
    }
}
