<?php

namespace App\Services\Messenger;

use Illuminate\Http\UploadedFile;

/**
 * کدام فایل پیوستِ پیام می‌شود، از چه نوعی، و تا چه اندازه (مرحلهٔ ۱۰ · فاز ۱۲؛ بندهای ۴۳، ۴۶، ۴۷ و ۱۲۰).
 *
 * ⚠️ نه فقط پسوند، نه فقط محتوا — **هر دو، و با هم**: پسوندِ نامِ کاربر باید در فهرست باشد و نوعِ خوانده‌شده از
 * محتوا (`finfo`) باید یکی از نوع‌هایی باشد که **همان پسوند** مجاز است. پس `photo.jpg` با محتوای HTML، `run.php` با
 * محتوای تصویر، و `report.pdf` با محتوای JPEG همه رد می‌شوند. نوعِ پیوست (`image`، `video`، `audio`، `file`) از همین
 * جفت می‌آید و سقفِ اندازه‌اش از `config/messenger.php`.
 *
 * فایلی که هم تصویر است و هم چیزِ دیگری (polyglot) از این‌جا با نوعِ تصویر می‌گذرد؛ دفاعِ آن در سرو است: نوعِ پاسخ
 * از فهرستِ سرور، `nosniff`، و هر چیزی جز تصویر و صدا و ویدیو و PDF به‌صورتِ دانلود (`Uploads::serve`).
 */
final class AttachmentPolicy
{
    private const SNIFF_BYTES = 1024 * 1024;

    /** پسوند ← نوع‌های محتوایی که برای آن پذیرفته‌اند، و نوعِ پیوست */
    private const TYPES = [
        'jpg' => ['image', ['image/jpeg']],
        'jpeg' => ['image', ['image/jpeg']],
        'png' => ['image', ['image/png']],
        'webp' => ['image', ['image/webp']],
        'gif' => ['image', ['image/gif']],

        'mp4' => ['video', ['video/mp4']],
        'webm' => ['video', ['video/webm']],
        'mov' => ['video', ['video/quicktime']],

        'mp3' => ['audio', ['audio/mpeg', 'audio/mp3']],
        'm4a' => ['audio', ['audio/mp4', 'audio/x-m4a', 'video/mp4']],
        'ogg' => ['audio', ['audio/ogg', 'application/ogg']],
        'wav' => ['audio', ['audio/wav', 'audio/x-wav', 'audio/vnd.wave']],
        'aac' => ['audio', ['audio/aac', 'audio/x-hx-aac-adts']],

        'pdf' => ['file', ['application/pdf']],
        'txt' => ['file', ['text/plain']],
        'csv' => ['file', ['text/csv', 'text/plain', 'application/csv']],
        // OOXML زیپ است؛ بسته به نسخه‌ی libmagic یا نوعِ دقیق یا zip خوانده می‌شود
        'docx' => ['file', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
        'xlsx' => ['file', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
        'pptx' => ['file', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']],
        'doc' => ['file', ['application/msword', 'application/x-ole-storage', 'application/CDFV2']],
        'xls' => ['file', ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2']],
    ];

    /** پیامِ صوتی و ویدیوییِ ضبط‌شده — MediaRecorder: WebM/Ogg در کرومیوم و فایرفاکس، MP4 در Safari */
    private const RECORDED = [
        'voice' => ['audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a'],
        'video_message' => ['video/webm', 'video/mp4', 'video/quicktime'],
    ];

    /** نوعِ پیوست، یا null اگر این فایل پذیرفتنی نیست */
    public static function kindOf(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $rule = self::TYPES[$extension] ?? null;

        if ($rule === null || ! in_array(self::sniff($file), $rule[1], true)) {
            return null;
        }

        return $rule[0];
    }

    public static function acceptsRecorded(UploadedFile $file, string $kind): bool
    {
        return in_array(self::sniff($file), self::RECORDED[$kind] ?? [], true);
    }

    /**
     * نوع از **سرآیندِ** فایل — نخستین مگابایت، نه کلِ آن (بندِ ۴۸).
     *
     * `finfo->file()` ِ libmagic بافری ثابت و بزرگ (~۱۶ مگابایت) می‌گیرد، هر چقدر فایل بزرگ باشد؛ سرآیند برای همه‌ی
     * نوع‌های این فهرست کافی است (ftyp ِ MP4، EBML ِ WebM، ID3، `%PDF`، زیپِ OOXML، …).
     */
    private static function sniff(UploadedFile $file): string
    {
        $handle = @fopen((string) $file->getRealPath(), 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $head = (string) fread($handle, self::SNIFF_BYTES);
        } finally {
            fclose($handle);
        }

        return (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
    }

    /** سقفِ هر نوع به کیلوبایت، از پیکربندی */
    public static function limitKb(string $kind): int
    {
        return (int) config('messenger.uploads.'.$kind.'_kb');
    }

    /** بزرگ‌ترین سقف — برای ردِ زودهنگامِ فایلِ بی‌ربط پیش از خواندنِ محتوا */
    public static function largestKb(): int
    {
        return max(array_map(fn (string $k) => self::limitKb($k), ['image', 'file', 'audio', 'video']));
    }

    /** پسوندهای پذیرفته — برای `accept` ِ کلاینت */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return array<string, int> */
    public static function limits(): array
    {
        return collect(['image', 'file', 'audio', 'video', 'voice', 'video_message'])
            ->mapWithKeys(fn (string $k) => [$k => self::limitKb($k)])
            ->all();
    }
}
