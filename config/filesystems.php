<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | دیسکِ فایل‌های خصوصیِ کاربران (R45)
    |--------------------------------------------------------------------------
    |
    | رسیدِ پرداخت، تصویرِ آگهی، فایلِ بکاپ و PDFهای تولیدشده اینجا می‌نشینند.
    |
    | ⚠️ برای اجرای چندگره‌ای این **باید** ذخیره‌سازِ اشتراکی باشد. با
    | `local`، فایلی که روی گره‌ی A آپلود شده از گره‌ی B قابلِ خواندن نیست و
    | کاربر بسته به اینکه متعادل‌کننده کدام گره را انتخاب کند، گاهی فایل را
    | می‌بیند و گاهی ۴۰۴ می‌گیرد.
    |
    | همه‌ی کد از `App\Support\PrivateFiles::disk()` می‌گذرد، پس عوض‌کردنِ
    | همین یک مقدار کافی است.
    |
    */

    'private' => env('PRIVATE_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | مقصدِ بکاپِ خارج از سرور (R58)
        |----------------------------------------------------------------------
        |
        | ⚠️ این تنها دیسکی است که **نباید** روی همان ماشین باشد. مقصدش یک
        | مسیرِ mountشده است — rclone، NFS یا SSHFS — که به فضای ذخیره‌سازیِ
        | دیگری اشاره می‌کند.
        |
        | با درایورِ `local` هیچ پکیجِ تازه‌ای لازم نیست؛ کارِ اتصال با
        | سیستم‌عامل است، نه با PHP.
        |
        | ⚠️ اگر مسیر را روی همان دیسکِ سرور بگذارید، احساسِ امنیت می‌کنید و
        | محافظتی ندارید. `backups:verify` شماره‌ی دستگاهِ فایل‌سیستم را
        | مقایسه می‌کند و همین حالت را قرمز گزارش می‌دهد.
        |
        | برای S3/رایانشِ ابری: `composer require league/flysystem-aws-s3-v3`
        | و `BACKUP_OFFSITE_DISK=s3`. رویه در docs/DISASTER_RECOVERY.md.
        |
        */

        'offsite' => [
            'driver' => 'local',
            /*
             * ⚠️ `?:` و نه پیش‌فرضِ دومِ `env()` — همان درسِ CI در R55:
             * کلیدِ **خالی** رشته‌ی تهی است نه null، پس پیش‌فرضِ دوم هرگز
             * اجرا نمی‌شد و ریشه‌ی دیسک رشته‌ی خالی می‌ماند.
             */
            'root' => env('BACKUP_OFFSITE_ROOT') ?: storage_path('app/offsite'),
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
