<?php

/**
 * سنجشِ بار — بدونِ هیچ وابستگی (R66).
 *
 * ─── چرا این فایل framework را بوت نمی‌کند ─────────────────────────────────
 * 🔴 این اسکریپت باید روی ماشینی اجرا شود که **سرور نیست**. اگر تولیدکننده‌ی
 * بار روی همان ماشینِ برنامه بدود، CPU را از خودِ برنامه می‌دزدد و عددی که
 * می‌گیرید ترکیبی از دو بار است، نه ظرفیتِ سرور.
 *
 * پس باید بشود فایل را برداشت و روی هر ماشینی با PHP گذاشت. تنها نیازش
 * افزونه‌ی cURL است که در هر نصبِ PHP هست.
 *
 * ⚠️ و طبقِ قیدِ پروژه، هیچ پکیجی نصب نمی‌شود (k6، ab، wrk).
 *
 * ─── ⚠️ مهم‌ترین چیزی که این ابزار می‌سنجد ─────────────────────────────────
 * پاسخِ ۴۲۹ **جدا** شمرده می‌شود و نه در کنارِ خطاها.
 *
 * این سامانه محدودکننده‌ی نرخ دارد و بیشترِ مسیرهای حساسش سقفِ دقیقه‌ای
 * دارند. اجرای بی‌دقتِ سنجشِ بار روی `login` از یک ماشین، پس از بیست
 * درخواست فقط **محدودکننده‌ی نرخ** را می‌سنجد نه برنامه را — و عددش
 * درخشان به نظر می‌رسد، چون رد‌کردنِ درخواست ارزان است.
 *
 * جدولِ سقف‌ها و راهِ درستِ سنجیدنِ هر مسیر در `docs/LOAD_TESTING.md` است.
 *
 * ─── استفاده ───────────────────────────────────────────────────────────────
 *
 *   php scripts/load-probe.php --url=https://example.com/up --concurrency=100
 *   php scripts/load-probe.php --url=... --concurrency=100,500,1000 --duration=30
 *   php scripts/load-probe.php --url=... --cookie="sakena_session=..." --json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (! function_exists('curl_multi_init')) {
    fwrite(STDERR, "افزونه‌ی cURL نصب نیست؛ این اسکریپت بدونِ آن کار نمی‌کند.\n");
    exit(1);
}

/**
 * @return array<string, string>
 */
function options(array $argv): array
{
    $out = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) === 1) {
            $out[$match[1]] = $match[2] ?? '1';
        }
    }

    return $out;
}

$options = options($argv);

if (! isset($options['url'])) {
    fwrite(STDERR, <<<'TEXT'
        سنجشِ بار (R66)

          --url=            نشانیِ هدف (اجباری)
          --concurrency=    هم‌زمانی؛ چند پله با کاما: 100,500,1000
          --duration=       ثانیه‌ی هر پله (پیش‌فرض ۲۰)
          --method=         GET (پیش‌فرض) یا POST — ورود و پرداخت POST‌اند
          --body=            بدنه‌ی JSON برای POST
          --cookie=         کوکیِ نشست، برای مسیرهای احراز هویت‌شده
          --header=         هدرِ اضافه (قابلِ تکرار نیست؛ یکی کافی است)
          --timeout=        مهلتِ هر درخواست به ثانیه (پیش‌فرض ۳۰)
          --json            خروجیِ ماشین‌خوان

        ⚠️ این را روی ماشینِ سرور اجرا نکنید؛ دلیلش بالای فایل نوشته شده.

        TEXT);
    exit(1);
}

$url = $options['url'];
$duration = max(1, (int) ($options['duration'] ?? 20));
$timeout = max(1, (int) ($options['timeout'] ?? 30));
$asJson = isset($options['json']);

$steps = array_values(array_filter(array_map(
    static fn (string $value): int => (int) trim($value),
    explode(',', $options['concurrency'] ?? '10'),
), static fn (int $value): bool => $value > 0));

if ($steps === []) {
    fwrite(STDERR, "هم‌زمانی نامعتبر است.\n");
    exit(1);
}

/*
 * ⚠️ هشدارِ «روی خودِ سرور اجرا نکن» بی‌سروصدا رد نمی‌شود.
 *
 * این ساده‌ترین اشتباهِ ممکن است و عددش کاملاً بی‌معنی — ولی چون خطایی
 * نمی‌دهد و جدولِ قشنگی چاپ می‌کند، به‌راحتی باور می‌شود.
 */
$host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

if (! $asJson && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
    fwrite(STDERR, "⚠️  هدف روی همین ماشین است. تولیدکننده‌ی بار CPU را از خودِ برنامه می‌دزدد\n");
    fwrite(STDERR, "   و عددِ حاصل ظرفیتِ سرور نیست. برای عددِ واقعی، این را از ماشینِ دیگری اجرا کنید.\n\n");
}

/**
 * یک پله: `$concurrency` درخواست را برای `$duration` ثانیه در پرواز نگه می‌دارد.
 *
 * @return array<string, mixed>
 */
function step(string $url, int $concurrency, int $duration, int $timeout, ?string $cookie, ?string $header, string $method, ?string $body): array
{
    $multi = curl_multi_init();
    $handles = [];
    $latencies = [];
    $codes = [];
    $transportErrors = 0;

    $deadline = microtime(true) + $duration;
    $started = microtime(true);

    $spawn = static function () use ($multi, &$handles, $url, $timeout, $cookie, $header, $method, $body): void {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // ⚠️ ریدایرکت دنبال نمی‌شود: یک ۳۰۲ به صفحه‌ی ورود، درخواستِ
            // دومی می‌سازد که در آمار با اولی قاطی می‌شود و تأخیر را
            // دو برابر نشان می‌دهد.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_values(array_filter([
                'Accept: application/json',
                $body === null ? null : 'Content-Type: application/json',
                $header,
            ])),
            CURLOPT_COOKIE => $cookie ?? '',
            CURLOPT_USERAGENT => 'sakena-load-probe/1.0',
        ]);

        curl_multi_add_handle($multi, $ch);
        $handles[(int) $ch] = $ch;
    };

    for ($i = 0; $i < $concurrency; $i++) {
        $spawn();
    }

    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);

        while (($info = curl_multi_info_read($multi)) !== false) {
            $ch = $info['handle'];

            if ($info['result'] === CURLE_OK) {
                $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $latencies[] = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            } else {
                $transportErrors++;
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($handles[(int) $ch]);

            // پله تا پایانِ مهلت، هم‌زمانی را ثابت نگه می‌دارد
            if (microtime(true) < $deadline) {
                $spawn();
            }
        }

        $running = $running > 0 || $handles !== [];
    } while ($running);

    curl_multi_close($multi);

    $elapsed = microtime(true) - $started;
    sort($latencies);

    $bucket = static fn (callable $test): int => count(array_filter($codes, $test));

    return [
        'concurrency' => $concurrency,
        'seconds' => round($elapsed, 1),
        'requests' => count($codes) + $transportErrors,
        'ok' => $bucket(static fn (int $c): bool => $c >= 200 && $c < 300),
        'throttled' => $bucket(static fn (int $c): bool => $c === 429),
        'client_errors' => $bucket(static fn (int $c): bool => $c >= 400 && $c < 500 && $c !== 429),
        'server_errors' => $bucket(static fn (int $c): bool => $c >= 500),
        'transport_errors' => $transportErrors,
        'rps' => $elapsed > 0 ? round((count($codes) + $transportErrors) / $elapsed, 1) : 0.0,
        'p50' => percentile($latencies, 0.50),
        'p90' => percentile($latencies, 0.90),
        'p95' => percentile($latencies, 0.95),
        'p99' => percentile($latencies, 0.99),
        'max' => $latencies === [] ? null : (int) round(end($latencies)),
    ];
}

/**
 * ⚠️ میانه و صدک، نه میانگین.
 *
 * میانگین یک پاسخِ سی‌ثانیه‌ای را در هزار پاسخِ سریع حل می‌کند و هیچ‌وقت
 * نشانش نمی‌دهد. صدکِ ۹۵ همان کاربری است که واقعاً منتظر مانده.
 *
 * @param  list<float>  $sorted
 */
function percentile(array $sorted, float $q): ?int
{
    if ($sorted === []) {
        return null;
    }

    $index = (int) ceil($q * count($sorted)) - 1;

    return (int) round($sorted[max(0, $index)]);
}

$results = [];

foreach ($steps as $index => $concurrency) {
    if (! $asJson) {
        fwrite(STDERR, "پله‌ی هم‌زمانیِ {$concurrency} ({$duration} ثانیه)…\n");
    }

    $results[] = step(
        $url,
        $concurrency,
        $duration,
        $timeout,
        $options['cookie'] ?? null,
        $options['header'] ?? null,
        strtoupper($options['method'] ?? 'GET'),
        $options['body'] ?? null,
    );

    // ⚠️ فاصله بین پله‌ها: صف و اتصال‌های نیمه‌باز باید خالی شوند، وگرنه
    // پله‌ی بعدی از دنباله‌ی پله‌ی قبلی شروع می‌شود.
    if ($index < count($steps) - 1) {
        sleep(5);
    }
}

$throttled = 0;
$failed = 0;

foreach ($results as $row) {
    $throttled += $row['throttled'];
    $failed += $row['server_errors'] + $row['transport_errors'];
}

/*
 * 🔴 کدِ خروجی در حالتِ JSON هم باید درست باشد.
 *
 * نسخه‌ی اولِ این فایل در حالتِ `--json` همیشه صفر برمی‌گرداند — یعنی
 * اسکریپتی که خروجی را در CI مصرف کند، هرگز نمی‌فهمید اجرا شکست خورده.
 * تستِ خودم گرفتش.
 */
if ($asJson) {
    echo json_encode(['url' => $url, 'steps' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($failed > 0 ? 1 : 0);
}

printf("\n%s\n\n", $url);
printf(
    "%-12s %-9s %-8s %-8s %-8s %-8s %-8s %-8s %-8s\n",
    'هم‌زمانی', 'RPS', 'موفق', '۴۲۹', '۴xx', '۵xx', 'p50', 'p95', 'p99',
);
echo str_repeat('─', 90), PHP_EOL;

foreach ($results as $row) {
    printf(
        "%-12d %-9s %-8d %-8d %-8d %-8d %-8s %-8s %-8s\n",
        $row['concurrency'],
        $row['rps'],
        $row['ok'],
        $row['throttled'],
        $row['client_errors'],
        $row['server_errors'] + $row['transport_errors'],
        $row['p50'] ?? '—',
        $row['p95'] ?? '—',
        $row['p99'] ?? '—',
    );
}

echo PHP_EOL;

/*
 * 🔴 تفسیر، نه فقط عدد.
 *
 * جدولِ خام به‌راحتی اشتباه خوانده می‌شود: ۴۲۹ «سریع» است و RPS را بالا
 * نشان می‌دهد. اگر ابزار خودش این را نگوید، کسی که عجله دارد نتیجه‌ی
 * غلط می‌گیرد.
 */
if ($throttled > 0) {
    printf(
        "🔴 %d پاسخِ ۴۲۹ — بخشی از این اجرا محدودکننده‌ی نرخ را سنجیده، نه برنامه را.\n"
        ."   جدولِ سقف‌ها در docs/LOAD_TESTING.md است.\n\n",
        $throttled,
    );
}

if ($failed > 0) {
    printf("🔴 %d شکستِ واقعی (۵xx یا قطعِ اتصال) — نقطه‌ی شکست همین‌جاست.\n\n", $failed);
}

exit($failed > 0 ? 1 : 0);
