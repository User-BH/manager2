<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    /** جدول‌های بکاپ کامل، به ترتیبی که کلید خارجی نشکند. */
    private const TABLES = [
        'complexes', 'users', 'buildings', 'units', 'unit_user',
        'charge_rules', 'expenses', 'incomes', 'bills', 'payments',
        'discounts', 'announcements', 'messages', 'message_restrictions', 'settings',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Backup::where('type', 'full')->latest()->get()
                ->map(fn (Backup $b) => $this->present($b))->values(),
        ]);
    }

    public function store(): JsonResponse
    {
        $data = [];
        foreach (self::TABLES as $table) {
            $data[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        }

        $snapshot = [
            'meta' => ['generated_at' => now()->toIso8601String(), 'type' => 'full'],
            'tables' => $data,
        ];

        $filename = 'backup-system-'.now()->format('Ymd-His').'.json';
        $path = 'backups/'.$filename;

        Storage::disk('local')->put($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $backup = Backup::create([
            'complex_id' => null,
            'type' => 'full',
            'status' => 'completed',
            'disk' => 'local',
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
            'note' => 'بکاپ کامل سیستم',
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'بکاپ کامل سیستم ساخته شد.',
            'backup' => $this->present($backup),
        ], 201);
    }

    public function download(Backup $backup): StreamedResponse
    {
        abort_if(! $backup->path || ! Storage::disk('local')->exists($backup->path), 404);

        return Storage::disk('local')->download($backup->path);
    }

    /**
     * بازیابی کل سیستم از فایل بکاپ. عملیات مخرب است: همه‌ی جدول‌ها خالی و
     * دوباره پر می‌شوند، پس در یک ترنزاکشن انجام می‌شود.
     */
    public function restore(Request $request): JsonResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'mimes:json,txt', 'max:51200'],
        ], [], ['backup' => 'فایل بکاپ']);

        $payload = json_decode($request->file('backup')->get(), true);

        if (! isset($payload['tables'])) {
            return response()->json(['message' => 'فایل بکاپ معتبر نیست.'], 422);
        }

        $driver = DB::getDriverName();

        try {
            $driver === 'sqlite'
                ? DB::statement('PRAGMA foreign_keys = OFF')
                : DB::statement('SET FOREIGN_KEY_CHECKS=0');

            DB::transaction(function () use ($payload) {
                // پاک‌کردن از فرزند به والد. از DELETE استفاده می‌شود نه
                // TRUNCATE، چون TRUNCATE در MySQL ترنزاکشن را commit می‌کند.
                foreach (array_reverse(self::TABLES) as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }

                foreach (self::TABLES as $table) {
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

        // کاربر فعلی هم از جدول users پاک و دوباره درج شده؛ نشست باید بسته شود.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'بازیابی سیستم انجام شد. لطفا دوباره وارد شوید.',
            'loggedOut' => true,
        ]);
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
