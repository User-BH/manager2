<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | سقفِ زمانیِ کوئریِ خواندنی (R59)
    |--------------------------------------------------------------------------
    |
    | 🔴 «دیتابیسِ کُند» بدتر از «دیتابیسِ مرده» است: مرده بی‌درنگ خطا
    | می‌دهد و صفحه‌ی ۵۰۳ بالا می‌آید، ولی کُند هیچ نشانه‌ای نمی‌دهد و فقط
    | کارگرهای PHP-FPM را یکی‌یکی می‌بلعد تا کلِ سایت — از جمله مسیرهایی که
    | اصلاً به آن جدول کار ندارند — ۵۰۲ بدهد.
    |
    | ⚠️ فقط روی `SELECT`های فقط-خواندنی اثر دارد. نوشتنِ مالی عمداً
    | بی‌سقف می‌ماند: گزارشِ کُند بمیرد، تسویه‌ی پرداخت نه.
    |
    | صفر یعنی خاموش.
    |
    */

    'read_timeout_ms' => (int) env('DB_READ_TIMEOUT_MS', 15000),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),

            /*
             * تفکیکِ خواندن از نوشتن (R45).
             *
             * ⚠️ فقط وقتی فعال می‌شود که `DB_READ_HOST` مقدار داشته باشد.
             * `array_filter` روی آرایه‌ی `read` تضمین می‌کند که با متغیرِ
             * خالی، هیچ اتصالِ خواندنی ساخته نشود و رفتار دقیقاً همان
             * تک‌سروریِ قبلی بماند — وگرنه هر نصبِ ساده به سرورِ ناموجود
             * وصل می‌شد.
             *
             * ⚠️ نکته‌ای که در عمل مردم را می‌سوزاند: لاراول `sticky` را
             * روشن نگه می‌دارد، یعنی پس از هر نوشتن، خواندن‌های **همان
             * درخواست** از سرورِ اصلی می‌آیند. بدونِ آن، تأخیرِ همانندسازی
             * یعنی کاربر پس از ثبتِ پرداخت، صفحه‌ای ببیند که هنوز
             * پرداختش را ندارد — و فکر کند پولش گم شده.
             */
            'read' => array_filter([
                'host' => env('DB_READ_HOST'),
            ]),
            'write' => [
                'host' => env('DB_HOST', '127.0.0.1'),
            ],
            'sticky' => true,

            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                /*
                 * ⚠️ سقفِ زمانِ **اتصال** (R59).
                 *
                 * بدونِ این، اتصال به میزبانی که پاسخ نمی‌دهد تا timeoutِ
                 * سیستم‌عامل (ده‌ها ثانیه) بلوکه می‌ماند و در همان مدت کارگرِ
                 * PHP-FPM اشغال است. با چند درخواستِ هم‌زمان، همه‌ی کارگرها
                 * روی یک اتصالِ مرده پارک می‌شوند و سایت ۵۰۲ می‌دهد.
                 */
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),

                /*
                 * 🔴 سقفِ زمانِ **کوئری**، به‌صورتِ فرمانِ آغازِ اتصال.
                 *
                 * ─── چرا اینجا و نه در یک شنونده‌ی رویداد ───────────────────
                 * ⚠️ نسخه‌ی اولِ همین کار با شنونده‌ی `ConnectionEstablished`
                 * نوشته شده بود که `SET SESSION` می‌زد — و **کلِ مجموعه‌ی
                 * تست را خواباند**.
                 *
                 * دلیلش: آن رویداد وقتی شیءِ Connection ساخته می‌شود پرتاب
                 * می‌شود، نه وقتی PDO وصل می‌شود. اجرای یک دستور در آن
                 * لحظه، اتصالِ **تنبل** را حریص می‌کند: صرفِ نام‌بردن از یک
                 * اتصال — مثلاً `DB::connection('mysql')` فقط برای ساختنِ
                 * یک کوئریِ نمونه — به تلاشِ واقعی برای وصل‌شدن تبدیل شد و
                 * روی ماشینی که MySQL ندارد، بی‌پایان ماند.
                 *
                 * `ATTR_INIT_COMMAND` همان کار را می‌کند ولی **فقط وقتی
                 * اتصالِ واقعی برقرار شود** — و یک کلاس و یک شنونده هم کمتر.
                 *
                 * ⚠️ فقط روی `SELECT`های فقط-خواندنی اثر دارد. نوشتنِ مالی
                 * عمداً بی‌سقف می‌ماند: گزارشِ کُند بمیرد، تسویه‌ی پرداخت نه.
                 */
                Mysql::ATTR_INIT_COMMAND => ($ms = (int) env('DB_READ_TIMEOUT_MS', 15000)) > 0
                    ? 'SET SESSION max_execution_time = '.$ms
                    : null,
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                /*
                 * ⚠️ سقفِ زمانِ **اتصال** (R59).
                 *
                 * بدونِ این، اتصال به میزبانی که پاسخ نمی‌دهد تا timeoutِ
                 * سیستم‌عامل (ده‌ها ثانیه) بلوکه می‌ماند و در همان مدت کارگرِ
                 * PHP-FPM اشغال است. با چند درخواستِ هم‌زمان، همه‌ی کارگرها
                 * روی یک اتصالِ مرده پارک می‌شوند و سایت ۵۰۲ می‌دهد.
                 */
                PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),

                // ⚠️ MariaDB نامِ دیگری دارد و واحدش **ثانیه**‌ی اعشاری است
                Mysql::ATTR_INIT_COMMAND => ($ms = (int) env('DB_READ_TIMEOUT_MS', 15000)) > 0
                    ? 'SET SESSION max_statement_time = '.($ms / 1000)
                    : null,
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
