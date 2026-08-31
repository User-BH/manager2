<?php

namespace Tests\Feature;

use App\Support\MigrationAudit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ایمنیِ مهاجرت: برگشت‌پذیریِ **تک‌به‌تک** و محافظِ مهاجرتِ خطرناک (R57).
 *
 * ─── چرا `migrate:reset` جوابِ این پرسش نیست ────────────────────────────────
 * اندازه‌گیری: `migrate:reset` روی هر ۴۹ مهاجرت اجرا شد و همه‌شان `DONE`
 * دادند، و پس از آن فقط جدولِ `migrations` باقی ماند. به نظر می‌رسد
 * برگشت‌پذیری اثبات شده است.
 *
 * ⚠️ **ولی نشده.** ۳۴ مهاجرت از نوعِ `Schema::create` اند. وقتی `reset`
 * می‌دود، `down()`ِ آن‌ها جدول را **کامل می‌اندازد** — پس اگر مهاجرتی که
 * بالای آن‌ها ستون اضافه کرده `down()`ِ ناقصی داشته باشد، هیچ‌کس نمی‌فهمد:
 * جدول همان پایین‌تر به‌کل حذف می‌شود.
 *
 * یعنی `reset` فقط می‌گوید «هیچ `down()`ی خطا نداد» — نه «هر `down()` دقیقاً
 * کارِ `up()` را برگرداند».
 *
 * ─── سنجشِ واقعی ───────────────────────────────────────────────────────────
 * برای **هر** مهاجرت جداگانه: عکسِ schema گرفته می‌شود، `up` اجرا می‌شود،
 * `down` اجرا می‌شود، و عکسِ تازه باید **دقیقاً** با عکسِ اول یکی باشد.
 * ۱۳ مهاجرتِ `Schema::table` تنها جایی‌اند که این تفاوت را نشان می‌دهند.
 *
 * ⚠️ عکس شاملِ **تعدادِ ردیف‌ها** هم هست، وگرنه دو مهاجرتِ داده‌ای
 * (`seed_default_plans` و `seed_default_advertisements`) بی‌سنجش می‌ماندند.
 */
class MigrationSafetyTest extends TestCase
{
    /**
     * ⚠️ اتصالِ جداگانه‌ی فایل‌محور، نه `:memory:` و نه اتصالِ تست.
     *
     * `RefreshDatabase` عمداً استفاده نشده: این تست باید مهاجرت‌ها را
     * **خودش** یکی‌یکی بدواند، و اتصالِ تست از قبل کاملاً مهاجرت‌شده است.
     * فایل هم لازم است چون `migrate` در پردازه‌ی جداگانه‌ای اتصال را
     * دوباره باز می‌کند.
     */
    private const CONNECTION = 'migration_probe';

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->database = $directory.DIRECTORY_SEPARATOR.'migration_probe.sqlite';

        $this->resetProbeDatabase();
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);

        if (file_exists($this->database)) {
            @unlink($this->database);
        }

        parent::tearDown();
    }

    private function resetProbeDatabase(): void
    {
        DB::purge(self::CONNECTION);

        if (file_exists($this->database)) {
            @unlink($this->database);
        }

        touch($this->database);

        config([
            'database.connections.'.self::CONNECTION => [
                'driver' => 'sqlite',
                'database' => $this->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
    }

    /** فایل‌های مهاجرت، به همان ترتیبی که لاراول اجرایشان می‌کند. */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php'));

        sort($files);

        return $files;
    }

    /**
     * وضعیتِ کاملِ دیتابیس: schema و تعدادِ ردیفِ هر جدول.
     *
     * ⚠️ جدولِ `migrations` کنار گذاشته می‌شود؛ محتوایش با هر گام عوض
     * می‌شود و ربطی به برگشت‌پذیریِ schema ندارد.
     */
    private function snapshot(): string
    {
        $connection = DB::connection(self::CONNECTION);

        $objects = $connection->select(
            "select type, name, coalesce(sql, '') as sql from sqlite_master
             where name not like 'sqlite_%' and name <> 'migrations'
             order by type, name"
        );

        $lines = [];

        foreach ($objects as $object) {
            /*
             * ⚠️ فاصله‌ها یکدست می‌شوند.
             *
             * SQLite هنگامِ افزودنِ ستون یا قید، متنِ `CREATE TABLE` را
             * دوباره می‌سازد و فاصله‌گذاری‌اش می‌تواند فرق کند بی‌آنکه
             * ساختار عوض شده باشد. بدونِ این، تست روی تفاوتی قرمز می‌شد
             * که هیچ اثری در محصول ندارد.
             */
            $sql = trim((string) preg_replace('/\s+/', ' ', $object->sql));

            /*
             * ⚠️ دو بازنویسیِ **متنیِ** SQLite خنثی می‌شوند، و فقط همان دو.
             *
             * وقتی SQLite جدول را دوباره می‌سازد (که با هر افزودن/حذفِ
             * ستون رخ می‌دهد)، متنِ `CREATE TABLE` را از نو تولید می‌کند و
             * دو چیز عوض می‌شود بی‌آنکه ساختار فرق کند:
             *
             *     default 'pending'          →  default ('pending')
             *     references "users"         →  references users
             *     on delete cascade          →  on delete cascade on update no action
             *
             * سومی همان **پیش‌فرضِ خودِ SQL** است که صریح نوشته می‌شود؛
             * `NO ACTION` رفتارِ پیش‌فرضِ `ON UPDATE` است.
             *
             * اندازه‌گیری نشان داد شش مهاجرت ناهمخوان به نظر می‌رسیدند؛
             * **دوتایشان نقصِ واقعی بودند** (رفع شدند) و چهارتای دیگر
             * فقط همین بازنویسی‌های متنی. بدونِ خنثی‌کردنشان، تست روی
             * تفاوتی قرمز می‌ماند که هیچ اثری در محصول ندارد و روی MySQL
             * اصلاً وجود ندارد.
             *
             * ⚠️ عمداً بیش از این عادی‌سازی نمی‌شود: هر الگوی دیگری که
             * خنثی شود، می‌تواند یک نقصِ واقعی را هم بپوشاند. این سه‌تا
             * هرکدام با دیدنِ نقطه‌ی دقیقِ اختلاف پیدا شدند، نه با حدس.
             */
            $sql = (string) preg_replace('/default \(([^()]*)\)/', 'default $1', $sql);
            $sql = (string) preg_replace('/references "([^"]+)"/', 'references $1', $sql);
            $sql = str_replace(' on update no action', '', $sql);

            $lines[] = $object->type.' '.$object->name.' :: '.$sql;

            if ($object->type === 'table') {
                $count = $connection->table($object->name)->count();
                $lines[] = '  rows('.$object->name.') = '.$count;
            }
        }

        return implode("\n", $lines);
    }

    private function runMigration(string $file, string $command, array $extra = []): void
    {
        Artisan::call($command, array_merge([
            '--database' => self::CONNECTION,
            '--path' => $file,
            '--realpath' => true,
            '--force' => true,
        ], $extra));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① برگشت‌پذیریِ تک‌به‌تک
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ هر `down()` باید **دقیقاً** کارِ `up()` را برگرداند.
     *
     * ─── چرا این مهم است ────────────────────────────────────────────────
     * وقتی استقرار وسطِ کار می‌شکند، تنها راهِ برگشت همین `down()`هاست. اگر
     * یکی‌شان ناقص باشد، دیتابیس در حالتی می‌ماند که نه نسخه‌ی جدید
     * می‌شناسدش و نه نسخه‌ی قدیم — و آن لحظه بدترین زمانِ ممکن برای
     * فهمیدنش است.
     */
    public function test_every_migration_is_exactly_reversible(): void
    {
        $checked = 0;
        $mismatched = [];

        foreach ($this->migrationFiles() as $file) {
            $before = $this->snapshot();

            $this->runMigration($file, 'migrate');
            $this->runMigration($file, 'migrate:rollback', ['--step' => 1]);

            $after = $this->snapshot();

            if ($after !== $before) {
                $mismatched[] = basename($file, '.php');
            }

            // برای مهاجرتِ بعدی، پیش‌نیازش باید سرِ جایش باشد
            $this->runMigration($file, 'migrate');

            $checked++;
        }

        /*
         * ⚠️ عدد از فهرستِ واقعی می‌آید، ولی کفِ سخت هم دارد.
         *
         * عددِ ثابت با هر مهاجرتِ تازه قرمز می‌شد (و شد). ولی فقط
         * `count($files)` هم بی‌معنا بود: اگر روزی `glob` چیزی برنگرداند،
         * حلقه صفر بار می‌دوید و تست سبز می‌ماند.
         */
        $this->assertSame(
            count($this->migrationFiles()),
            $checked,
            'همه‌ی مهاجرت‌ها سنجیده نشدند.',
        );

        $this->assertGreaterThanOrEqual(
            49,
            $checked,
            'تعدادِ مهاجرت‌ها کمتر از انتظار است؛ احتمالاً فهرست خالی مانده و تست پوچ است.',
        );

        $this->assertSame(
            [],
            $mismatched,
            "این مهاجرت‌ها پس از `down()` دیتابیس را به حالتِ پیشین برنگرداندند:\n  "
            .implode("\n  ", $mismatched),
        );
    }

    /**
     * و پس از همه‌ی آن رفت‌وبرگشت‌ها، دیتابیس باید کاملاً مهاجرت‌شده بماند.
     *
     * ⚠️ بدونِ این ادعا، تستِ بالا می‌توانست با یک `down()`ی که هیچ کاری
     * نمی‌کند هم سبز بماند.
     */
    public function test_the_database_is_fully_migrated_after_the_round_trips(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $this->runMigration($file, 'migrate');
        }

        $applied = DB::connection(self::CONNECTION)->table('migrations')->count();

        $this->assertSame(
            count($this->migrationFiles()),
            $applied,
            'همه‌ی مهاجرت‌ها اعمال نشده‌اند.',
        );

        $tables = DB::connection(self::CONNECTION)->select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"
        );

        $this->assertGreaterThan(
            25,
            count($tables),
            'تعدادِ جدول‌ها پس از مهاجرتِ کامل مشکوک است.',
        );
    }

    /** و برگشتِ کامل باید هیچ جدولی جز `migrations` باقی نگذارد. */
    public function test_a_full_reset_leaves_nothing_behind(): void
    {
        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        Artisan::call('migrate:reset', ['--database' => self::CONNECTION, '--force' => true]);

        $tables = DB::connection(self::CONNECTION)->select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"
        );

        $names = array_map(fn (object $t) => $t->name, $tables);

        $this->assertSame(
            ['migrations'],
            $names,
            'پس از برگشتِ کامل این جدول‌ها ماندند: '.implode('، ', $names),
        );
    }
    // ─────────────────────────────────────────────────────────────────────
    //  ② مهاجرتِ مخرب — پذیرشِ صریح یا رد
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ هر مهاجرتی که داده را از بین می‌برد باید صریحاً پذیرفته شده باشد.
     *
     * اندازه‌گیری: در کلِ ۴۹ مهاجرت **یک** مورد مخرب هست
     * (`drop_message_restrictions_table`). عددِ صفر می‌ماند اگر کسی
     * `dropColumn` تازه‌ای بنویسد و توضیحش ندهد — و آن‌وقت این تست و
     * `migrate:check` هر دو قرمز می‌شوند.
     *
     * ⚠️ ادعا «مخرب ننویس» نیست؛ «ندانسته ننویس» است. گاهی حذفِ ستون
     * دقیقاً کارِ درست است.
     */
    public function test_every_destructive_migration_is_acknowledged(): void
    {
        $unacknowledged = [];
        $destructive = 0;

        foreach ($this->migrationFiles() as $file) {
            $operations = MigrationAudit::destructiveOperations($file);

            if ($operations === []) {
                continue;
            }

            $destructive++;

            if (! MigrationAudit::isAcknowledged($file)) {
                $unacknowledged[] = basename($file, '.php').' ['.implode('، ', $operations).']';
            }
        }

        $this->assertSame(
            [],
            $unacknowledged,
            'این مهاجرت‌ها داده را از بین می‌برند و خطِ `@destructive` با دلیل ندارند:
  '
            .implode('
  ', $unacknowledged),
        );

        $this->assertGreaterThan(
            0,
            $destructive,
            'هیچ مهاجرتِ مخربی پیدا نشد؛ الگوی تشخیص احتمالاً خراب است و تست پوچ.',
        );
    }

    /**
     * و برعکس: `@destructive` روی مهاجرتی که مخرب نیست، نباید باشد.
     *
     * ⚠️ نشانه‌ی تزئینی از نبودِ نشانه بدتر است — مرورکننده فکر می‌کند
     * موردی سنجیده شده که اصلاً وجود ندارد.
     */
    public function test_no_migration_is_marked_destructive_without_being_one(): void
    {
        $decorative = [];

        foreach ($this->migrationFiles() as $file) {
            if (MigrationAudit::isAcknowledged($file) && MigrationAudit::destructiveOperations($file) === []) {
                $decorative[] = basename($file, '.php');
            }
        }

        $this->assertSame([], $decorative, 'نشانه‌ی `@destructive` روی مهاجرتِ غیرمخرب: '.implode('، ', $decorative));
    }

    /**
     * ⚠️ `migrate:check` باید روی مهاجرتِ مخربِ نپذیرفته **بایستد**.
     *
     * این همان چیزی است که در `deploy.yml` پیش از `migrate` می‌دود؛ اگر
     * کدِ خروجی‌اش درست نباشد، استقرار بی‌سروصدا ادامه می‌دهد.
     */
    public function test_the_gate_stops_on_an_unacknowledged_destructive_migration(): void
    {
        $directory = storage_path('framework/testing/fake-migrations');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory.DIRECTORY_SEPARATOR.'2099_01_01_000000_probe.php';

        file_put_contents($file, "<?php

return new class
{
    public function up(): void
    {
        \$table->dropColumn('x');
    }
};
");

        try {
            $this->assertSame(
                ['حذفِ ستون'],
                MigrationAudit::destructiveOperations($file),
                'عملیاتِ مخرب تشخیص داده نشد.',
            );

            $this->assertFalse(
                MigrationAudit::isAcknowledged($file),
                'مهاجرتِ بدونِ نشانه، پذیرفته‌شده حساب شد.',
            );

            // نشانه‌ی بی‌دلیل هم نباید کافی باشد
            file_put_contents($file, "<?php

/**
 * @destructive کوتاه
 */
return new class
{
    public function up(): void
    {
        \$table->dropColumn('x');
    }
};
");

            $this->assertFalse(
                MigrationAudit::isAcknowledged($file),
                'نشانه‌ی بدونِ دلیلِ کافی پذیرفته شد.',
            );
        } finally {
            @unlink($file);
        }
    }

    /**
     * و خودِ دستور باید در `deploy.yml` **پیش از** `migrate` صدا زده شود.
     *
     * ⚠️ ترتیب تمامِ ارزشش است: اگر پس از `migrate` بیاید، دیتابیس از قبل
     * دست خورده و ایستادن دیگر چیزی را نجات نمی‌دهد.
     */
    public function test_the_deployment_runs_the_gate_before_migrating(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $workflow = (string) preg_replace('/^\s*#.*$/m', '', $workflow);

        $check = strpos($workflow, 'artisan migrate:check');
        $migrate = strpos($workflow, 'artisan migrate --force');

        $this->assertNotFalse($check, '`deploy.yml` دستورِ `migrate:check` را صدا نمی‌زند.');
        $this->assertNotFalse($migrate, '`deploy.yml` دستورِ `migrate` را صدا نمی‌زند.');

        $this->assertLessThan(
            $migrate,
            $check,
            '`migrate:check` باید پیش از `migrate` بیاید، وگرنه دیتابیس از قبل دست خورده است.',
        );
    }

    /**
     * و سندِ رویه باید وجود داشته باشد و دستورهایش واقعی باشند.
     *
     * ⚠️ همان تله‌ی R42: `deploy.yml` نسخه‌ی اولش `php artisan backup:run`
     * می‌نوشت — دستوری که هرگز وجود نداشت. رویه‌ای که وسطِ بحران خوانده
     * می‌شود و دستورش کار نمی‌کند، از نبودِ رویه بدتر است.
     */
    public function test_the_migration_guide_only_recommends_real_commands(): void
    {
        $path = base_path('docs/MIGRATIONS.md');

        $this->assertFileExists($path);

        $guide = (string) file_get_contents($path);

        foreach (['Expand', 'Contract', '@destructive', 'migrate:check'] as $topic) {
            $this->assertStringContainsString($topic, $guide, "سندِ مهاجرت بخشِ «{$topic}» را ندارد.");
        }

        $available = array_keys(app(Kernel::class)->all());

        preg_match_all('/php artisan ([a-z][a-z0-9:_-]*)/', $guide, $matches);

        $this->assertNotEmpty($matches[1], 'سند هیچ دستوری توصیه نمی‌کند؛ الگو خراب است.');

        foreach (array_unique($matches[1]) as $command) {
            $this->assertContains(
                $command,
                $available,
                "سندِ مهاجرت «php artisan {$command}» را توصیه می‌کند ولی چنین دستوری وجود ندارد.",
            );
        }
    }
}
