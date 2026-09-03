<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),

            /*
            | 🔴 این عدد باید از **بلندترین** `$timeout` کارها بزرگ‌تر باشد.
            |
            | ─── نقصی که اندازه‌گیریِ R65 پیدا کرد ───────────────────────────
            | پیش‌فرضِ لاراول ۹۰ ثانیه است و همان‌طور مانده بود، در حالی که:
            |
            |     BuildBillsBundleJob::$timeout = 900   ← ۱۵ دقیقه
            |     BuildBackupJob::$timeout      = 600   ← ۱۰ دقیقه
            |     BaseJob::$timeout             = 300   ← ۵ دقیقه
            |
            | `retry_after` یعنی «اگر کاری این‌قدر طول کشید، فرض کن کارگرش
            | مرده و دوباره تحویلش بده». با ۹۰ ثانیه، سندِ دسته‌ی قبضِ یک
            | مجتمعِ ۲۰۰ واحدی — که دقیقاً برای همین در صف است چون دقایق
            | طول می‌کشد — **در حالِ اجرا** به کارگرِ دوم هم داده می‌شد.
            |
            | نتیجه‌اش دو (و با `tries = 3` تا سه) ساختِ هم‌زمانِ یک سند روی
            | یک رکورد بود: کارِ دوبرابر، و رقابت روی همان ردیف.
            |
            | ⚠️ هزینه‌ی این تغییر صادقانه گفته شود: کاری که واقعاً یخ بزند
            | (مثلاً با `kill -9` روی کارگر) حالا تا ۱۷ دقیقه منتظر می‌ماند.
            | این معامله عمدی است — تأخیر در حالتِ نادر، به‌جای کارِ تکراری
            | در حالتِ عادی. یک تست می‌سنجد که این عدد از هر `$timeout`ی
            | بزرگ‌تر بماند.
            */
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 1020),

            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
