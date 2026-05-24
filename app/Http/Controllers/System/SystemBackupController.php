<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class SystemBackupController extends Controller
{
    /** Tables included in a full backup, in FK-safe insert order. */
    private const TABLES = [
        'complexes', 'users', 'buildings', 'units', 'unit_user',
        'charge_rules', 'expenses', 'incomes', 'bills', 'payments',
        'discounts', 'announcements', 'messages', 'message_restrictions', 'settings',
    ];

    public function index()
    {
        return view('system.backup.index', [
            'backups' => Backup::where('type', 'full')->latest()->get(),
        ]);
    }

    public function store()
    {
        $data = [];
        foreach (self::TABLES as $table) {
            $data[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        }

        $snapshot = ['meta' => ['generated_at' => now()->toIso8601String(), 'type' => 'full'], 'tables' => $data];

        $filename = 'backup-system-'.now()->format('Ymd-His').'.json';
        $path = 'backups/'.$filename;
        Storage::disk('local')->put($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        Backup::create([
            'complex_id' => null, 'type' => 'full', 'status' => 'completed',
            'disk' => 'local', 'path' => $path, 'size' => Storage::disk('local')->size($path),
            'note' => 'بکاپ کامل سیستم', 'created_by' => Auth::id(),
        ]);

        return Storage::disk('local')->download($path, $filename);
    }

    public function download(Backup $backup)
    {
        abort_if(! $backup->path || ! Storage::disk('local')->exists($backup->path), 404);

        return Storage::disk('local')->download($backup->path);
    }

    /** Restore the whole system from an uploaded backup JSON. Destructive. */
    public function restore(Request $request)
    {
        $request->validate(['backup' => ['required', 'file', 'mimes:json,txt', 'max:51200']]);

        $payload = json_decode($request->file('backup')->get(), true);
        if (! isset($payload['tables'])) {
            return back()->with('error', 'فایل بکاپ معتبر نیست.');
        }

        $driver = DB::getDriverName();

        try {
            $driver === 'sqlite'
                ? DB::statement('PRAGMA foreign_keys = OFF')
                : DB::statement('SET FOREIGN_KEY_CHECKS=0');

            DB::transaction(function () use ($payload) {
                // Clear children first (reverse order). DELETE is used rather than
                // TRUNCATE because TRUNCATE implicitly commits the transaction in MySQL.
                foreach (array_reverse(self::TABLES) as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
                // Re-insert in FK-safe forward order.
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

        Auth::logout();

        return redirect()->route('login')->with('success', 'بازیابی سیستم انجام شد. لطفا دوباره وارد شوید.');
    }
}
