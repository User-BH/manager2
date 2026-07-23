<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    /**
     * جدول‌هایی که در بکاپ کامل ذخیره می‌شوند، به ترتیبی که کلید خارجی نشکند.
     *
     * جدول‌های گذرا (`sessions`, `cache`, `jobs`, `otp_codes`,
     * `password_reset_tokens`) عمداً نیستند؛ بازگرداندنشان بی‌معنی است و فقط
     * حجم فایل را بالا می‌برد. خودِ `backups` هم نیست چون فهرست فایل‌هاست، نه
     * داده‌ی کسب‌وکار.
     */
    private const BACKUP_TABLES = [
        'complexes', 'users', 'buildings', 'units', 'unit_user',
        'charge_rules', 'expenses', 'incomes', 'bills', 'payments',
        'discounts', 'announcements', 'announcement_reads', 'messages',
        'settings', 'subscriptions', 'advertisements', 'audit_logs',
    ];

    /**
     * جدول‌هایی که هنگام بازیابی خالی و دوباره پر می‌شوند.
     *
     * `audit_logs` عمداً بیرون است: اگر بازیابی لاگ فعالیت را هم پاک کند، هرکس
     * می‌تواند با یک بازیابی رد پای خودش را بشوید. لاگ باید از بازیابی جان
     * سالم به در ببرد، هرچند در فایل بکاپ ذخیره می‌شود.
     */
    private const RESTORE_TABLES = [
        'complexes', 'users', 'buildings', 'units', 'unit_user',
        'charge_rules', 'expenses', 'incomes', 'bills', 'payments',
        'discounts', 'announcements', 'announcement_reads', 'messages',
        'settings', 'subscriptions', 'advertisements',
    ];

    /** عبارتی که ادمین باید دقیقاً تایپ کند تا بازیابی انجام شود. */
    private const CONFIRM_PHRASE = 'بازیابی';

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Backup::where('type', 'full')->latest()->get()
                ->map(fn (Backup $b) => $this->present($b))->values(),
        ]);
    }

    public function store(): JsonResponse
    {
        return response()->json([
            'message' => 'بکاپ کامل سیستم ساخته شد.',
            'backup' => $this->present($this->snapshot('بکاپ کامل سیستم')),
        ], 201);
    }

    /** گرفتن یک بکاپ کامل و ثبت رکوردش. */
    private function snapshot(string $note): Backup
    {
        $data = [];
        foreach (self::BACKUP_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $data[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
            }
        }

        $snapshot = [
            'meta' => ['generated_at' => now()->toIso8601String(), 'type' => 'full'],
            'tables' => $data,
        ];

        $path = 'backups/backup-system-'.now()->format('Ymd-His').'-'.Str::random(6).'.json';

        Storage::disk('local')->put($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return Backup::create([
            'complex_id' => null,
            'type' => 'full',
            'status' => 'completed',
            'disk' => 'local',
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
            'note' => $note,
            'created_by' => Auth::id(),
        ]);
    }

    public function download(Backup $backup): StreamedResponse
    {
        abort_if(! $backup->path || ! Storage::disk('local')->exists($backup->path), 404);

        return Storage::disk('local')->download($backup->path);
    }

    /**
     * بازیابی کل سیستم از فایل بکاپ.
     *
     * مخرب‌ترین عملیات کل سامانه است: همه‌ی جدول‌ها خالی و دوباره پر می‌شوند.
     * پس چند محافظ دارد که هیچ‌کدام تزئینی نیستند:
     *
     *  - فایل **پیش از** هر تغییری کامل اعتبارسنجی می‌شود، نه اینکه وسط کار
     *    به خطا بخوریم و به rollback تکیه کنیم.
     *  - `dry_run` فقط گزارش می‌دهد چه چیزی جایگزین می‌شود و دست به داده
     *    نمی‌زند.
     *  - پیش از پاک‌کردن، یک بکاپ ایمنی خودکار گرفته می‌شود تا بازیابیِ اشتباه
     *    برگشت‌پذیر باشد.
     *  - ادمین باید عبارت تایید را دقیقاً تایپ کند؛ کلیک تنها کافی نیست.
     *  - نتیجه در `audit_logs` ثبت می‌شود، و آن جدول عمداً بازیابی نمی‌شود تا
     *    نشود با یک restore رد پا را شست.
     */
    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'mimes:json,txt', 'max:20480'],
            'dry_run' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'string'],
        ], [
            'backup.max' => 'حجم فایل بکاپ نباید از ۲۰ مگابایت بیشتر باشد.',
        ], ['backup' => 'فایل بکاپ']);

        $payload = json_decode((string) $request->file('backup')->get(), true);

        $error = $this->validateSnapshot($payload);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        $incoming = $this->rowCounts($payload['tables']);

        // آزمایشی: فقط گزارش، بدون هیچ تغییری
        if ($request->boolean('dry_run')) {
            return response()->json([
                'dryRun' => true,
                'message' => 'فایل بکاپ سالم است و آماده‌ی بازیابی.',
                'generatedAt' => $payload['meta']['generated_at'] ?? null,
                'tables' => $incoming,
                'current' => $this->currentRowCounts(),
            ]);
        }

        // تایید تایپ‌شده. عمداً بعد از اعتبارسنجی است تا ادمین اول بتواند
        // بی‌دردسر dry-run بگیرد و بعد تایید کند.
        if (trim((string) $request->input('confirm')) !== self::CONFIRM_PHRASE) {
            return response()->json([
                'message' => 'برای انجام بازیابی باید عبارت «'.self::CONFIRM_PHRASE.'» را تایپ کنید.',
            ], 422);
        }

        // بکاپ ایمنی پیش از تخریب؛ تنها راه برگشت از یک بازیابی اشتباه
        $safety = $this->snapshot('بکاپ خودکار پیش از بازیابی');

        // هویت انجام‌دهنده پیش از تخریب برداشته می‌شود: بعد از بازیابی ممکن
        // است این کاربر اصلاً در جدول users نباشد.
        $actorId = Auth::id();
        $actorLabel = Auth::user()?->name.' ('.Auth::user()?->phone.')';

        $driver = DB::getDriverName();

        try {
            $driver === 'sqlite'
                ? DB::statement('PRAGMA foreign_keys = OFF')
                : DB::statement('SET FOREIGN_KEY_CHECKS=0');

            DB::transaction(function () use ($payload) {
                // پاک‌کردن از فرزند به والد. از DELETE استفاده می‌شود نه
                // TRUNCATE، چون TRUNCATE در MySQL ترنزاکشن را commit می‌کند.
                foreach (array_reverse(self::RESTORE_TABLES) as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                foreach (self::RESTORE_TABLES as $table) {
                    if (! Schema::hasTable($table) || ! isset($payload['tables'][$table])) {
                        continue;
                    }

                    foreach (array_chunk($payload['tables'][$table], 200) as $chunk) {
                        if ($chunk) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                }
            });
        } finally {
            $driver === 'sqlite'
                ? DB::statement('PRAGMA foreign_keys = ON')
                : DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // لاگ بعد از بازیابی نوشته می‌شود چون جدولش جزو بازیابی‌شونده‌ها نیست
        // و باید بماند؛ کاربر فعلی هم دیگر ممکن است وجود نداشته باشد.
        AuditLog::create([
            'complex_id' => null,
            // اگر فایل بکاپ از سامانه‌ی دیگری آمده باشد، این کاربر دیگر وجود
            // ندارد و کلید خارجی می‌شکند؛ پس نامش در properties می‌ماند.
            'user_id' => User::whereKey($actorId)->exists() ? $actorId : null,
            'action' => 'system.restored',
            'description' => 'بازیابی کل سیستم از فایل بکاپ',
            'ip_address' => $request->ip(),
            'properties' => [
                'actor' => $actorLabel,
                'file' => $request->file('backup')->getClientOriginalName(),
                'generated_at' => $payload['meta']['generated_at'] ?? null,
                'restored_rows' => $incoming,
                'safety_backup' => $safety->path,
            ],
            'created_at' => now(),
        ]);

        // کاربر فعلی هم از جدول users پاک و دوباره درج شده؛ نشست باید بسته شود.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'بازیابی سیستم انجام شد. لطفا دوباره وارد شوید.',
            'safetyBackup' => $safety->path,
            'loggedOut' => true,
        ]);
    }

    /**
     * بررسی سلامت فایل بکاپ، پیش از آنکه چیزی پاک شود.
     *
     * تکیه بر rollback کافی نیست: خطای وسط کار پیام گنگ دیتابیس می‌دهد و ادمین
     * نمی‌فهمد فایلش مشکل داشته یا سرور.
     *
     * @return string|null پیام خطا، یا null اگر فایل سالم باشد
     */
    private function validateSnapshot(mixed $payload): ?string
    {
        if (! is_array($payload) || ! isset($payload['tables']) || ! is_array($payload['tables'])) {
            return 'فایل بکاپ معتبر نیست یا ساختار درستی ندارد.';
        }

        if (($payload['meta']['type'] ?? null) !== 'full') {
            return 'این فایل بکاپ کامل سیستم نیست. بکاپ یک مجتمع را نمی‌توان اینجا بازیابی کرد.';
        }

        foreach ($payload['tables'] as $table => $rows) {
            // جدولی که نمی‌شناسیم نباید بی‌سروصدا نادیده گرفته شود؛ یعنی فایل
            // از نسخه‌ی دیگری از برنامه آمده و بازیابی‌اش داده را ناقص می‌کند.
            if (! in_array($table, self::BACKUP_TABLES, true)) {
                return "فایل بکاپ شامل جدول ناشناخته‌ی «{$table}» است.";
            }

            if (! is_array($rows)) {
                return "داده‌ی جدول «{$table}» در فایل بکاپ معتبر نیست.";
            }

            if (! Schema::hasTable($table) || $rows === []) {
                continue;
            }

            // ستون‌های ناشناخته هنگام insert خطای SQL می‌دهند؛ بهتر است همین
            // حالا با پیام روشن رد شوند.
            $columns = Schema::getColumnListing($table);
            $unknown = array_diff(array_keys((array) $rows[0]), $columns);

            if ($unknown !== []) {
                return "جدول «{$table}» ستون ناشناخته دارد: ".implode('، ', $unknown);
            }
        }

        return null;
    }

    /**
     * @param  array<string,array<int,mixed>>  $tables
     * @return array<string,int>
     */
    private function rowCounts(array $tables): array
    {
        return collect(self::RESTORE_TABLES)
            ->mapWithKeys(fn (string $t) => [$t => count($tables[$t] ?? [])])
            ->all();
    }

    /** @return array<string,int> */
    private function currentRowCounts(): array
    {
        return collect(self::RESTORE_TABLES)
            ->mapWithKeys(fn (string $t) => [$t => Schema::hasTable($t) ? DB::table($t)->count() : 0])
            ->all();
    }

    private function present(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'type' => $backup->type,
            'status' => $backup->status,
            'note' => $backup->note,
            'sizeKb' => (int) round(((int) $backup->size) / 1024),
            'createdAt' => Jalali::dateTime($backup->created_at),
            'downloadUrl' => route('api.system.backups.download', $backup),
        ];
    }
}
