<?php

/*
 * سقفِ پیوست‌های پیام‌رسان (مرحلهٔ ۱۰ · فاز ۱۲؛ بندِ ۴۶ ِ فایلِ معماری) — به کیلوبایت.
 *
 * «Limit صریح، server-enforced و configurable». پیش‌فرض‌ها همان پیشنهادِ فایل‌اند؛ هر کدام با متغیرِ محیطِ خودش عوض
 * می‌شود. سرور همین‌ها را با محتوای فایل می‌سنجد (`AttachmentPolicy`) و کلاینت فقط برای پیش‌آگهی می‌خواندشان.
 *
 * ⚠️ محیط باید اجازه‌ی همین اندازه را بدهد: `upload_max_filesize` و `post_max_size` ِ PHP و `client_max_body_size` ِ
 * nginx کمتر از سقفِ این‌جا یعنی درخواست پیش از رسیدن به برنامه رد می‌شود (`docs/MESSENGER_STRUCTURE.md`).
 */
return [
    'uploads' => [
        'image_kb' => (int) env('MESSENGER_MAX_IMAGE_KB', 20 * 1024),
        'file_kb' => (int) env('MESSENGER_MAX_FILE_KB', 100 * 1024),
        'audio_kb' => (int) env('MESSENGER_MAX_AUDIO_KB', 50 * 1024),
        'video_kb' => (int) env('MESSENGER_MAX_VIDEO_KB', 500 * 1024),
        // پیامِ صوتی و ویدیوییِ ضبط‌شده در خودِ پیام‌رسان
        'voice_kb' => (int) env('MESSENGER_MAX_VOICE_KB', 1024),
        'video_message_kb' => (int) env('MESSENGER_MAX_VIDEO_MESSAGE_KB', 20 * 1024),
    ],
];
