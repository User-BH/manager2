<?php

namespace App\Console\Commands;

use App\Support\MigrationAudit;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * دروازه‌ی مهاجرت، پیش از اینکه به دیتابیسِ محصول دست بخورد (R57).
 *
 * ─── چرا `migrate --pretend` کافی نیست ─────────────────────────────────────
 * ⚠️ `--pretend` فقط SQL را چاپ می‌کند؛ کسی باید بخواندش و تصمیم بگیرد. در
 * یک استقرارِ خودکار هیچ‌کس نمی‌خواند.
 *
 * این دستور دو چیز را **اجبار** می‌کند:
 *
 *   ① فهرستِ مهاجرت‌های در انتظار را نشان می‌دهد، تا در لاگِ استقرار بماند.
 *   ② اگر مهاجرتِ در انتظاری **مخرب** باشد و نویسنده‌اش صریحاً نپذیرفته
 *      باشد، با کدِ خطا برمی‌گردد و استقرار همان‌جا می‌ایستد.
 *
 * ─── «مخرب» یعنی چه ────────────────────────────────────────────────────────
 * حذفِ ستون، تغییرِ نوعِ ستون، تغییرِ نام، حذفِ جدول، یا حذفِ ردیف. این‌ها
 * برخلافِ `addColumn` **داده را از بین می‌برند** و `down()` هم برشان
 * نمی‌گرداند — یک ستونِ حذف‌شده را می‌شود دوباره ساخت، ولی محتوایش رفته.
 *
 * پذیرشِ صریح یعنی خطِ `@destructive` در docblockِ همان فایل، با دلیل. هزینه‌اش
 * یک خط است؛ فایده‌اش این است که کسی نمی‌تواند **ندانسته** ستونی را بیندازد.
 */
class CheckMigrations extends Command
{
    protected $signature = 'migrate:check {--database= : اتصالی که سنجیده شود}';

    protected $description = 'نمایشِ مهاجرت‌های در انتظار و ایستادن روی مهاجرتِ مخربِ نپذیرفته';

    public function handle(Migrator $migrator): int
    {
        $connection = (string) ($this->option('database') ?: config('database.default'));

        $migrator->setConnection($connection);

        $pending = $this->pending($migrator);

        if ($pending === []) {
            $this->info('هیچ مهاجرتِ در انتظاری نیست.');

            return self::SUCCESS;
        }

        $this->line('مهاجرت‌های در انتظار ('.count($pending).'):');

        $unacknowledged = [];

        foreach ($pending as $file) {
            $name = basename($file, '.php');
            $operations = MigrationAudit::destructiveOperations($file);

            if ($operations === []) {
                $this->line('  • '.$name);

                continue;
            }

            if (MigrationAudit::isAcknowledged($file)) {
                $this->line('  ⚠ '.$name.'  ['.implode('، ', $operations).'] — پذیرفته‌شده');

                continue;
            }

            $this->line('  ✗ '.$name.'  ['.implode('، ', $operations).']');
            $unacknowledged[] = $name;
        }

        if ($unacknowledged === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('این مهاجرت‌ها داده را از بین می‌برند و پذیرشِ صریح ندارند:');

        foreach ($unacknowledged as $name) {
            $this->error('  - '.$name);
        }

        $this->newLine();
        $this->line(
            'اگر عمدی است، در docblockِ همان فایل خطِ «@destructive <دلیل>» را '
            .'بگذارید. اگر نه، الگوی Expand → Migrate → Contract را در '
            .'docs/MIGRATIONS.md ببینید.'
        );

        return self::FAILURE;
    }

    /**
     * فایل‌های مهاجرتی که هنوز اجرا نشده‌اند.
     *
     * ⚠️ اگر جدولِ `migrations` هنوز ساخته نشده باشد (نصبِ تازه)، **همه**
     * در انتظارند. آن حالت خطا نیست و نباید استقرارِ اول را بشکند.
     *
     * @return array<int, string>
     */
    private function pending(Migrator $migrator): array
    {
        $files = $migrator->getMigrationFiles(database_path('migrations'));

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_filter(
            $files,
            fn (string $file, string $name) => ! in_array($name, $ran, true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
