<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class BackupController extends Controller
{
    public function index()
    {
        $backups = Backup::where('complex_id', $this->currentComplex()?->id)
            ->latest()
            ->get();

        return view('admin.backup.index', compact('backups'));
    }

    /** Export the complex's data to a self-contained JSON snapshot. */
    public function store()
    {
        $complex = $this->requireComplex();

        $snapshot = [
            'meta' => ['generated_at' => now()->toIso8601String(), 'complex_id' => $complex->id],
            'complex' => $complex->toArray(),
            'buildings' => $complex->buildings()->get()->toArray(),
            'units' => $complex->units()->with('residents')->get()->toArray(),
            'users' => $complex->users()->get()->makeHidden('password')->toArray(),
            'charge_rules' => $complex->chargeRules()->get()->toArray(),
            'expenses' => $complex->expenses()->get()->toArray(),
            'incomes' => $complex->incomes()->get()->toArray(),
            'bills' => $complex->bills()->get()->toArray(),
            'payments' => $complex->payments()->get()->toArray(),
            'announcements' => $complex->announcements()->get()->toArray(),
        ];

        $filename = 'backup-complex-'.$complex->id.'-'.now()->format('Ymd-His').'.json';
        $path = 'backups/'.$filename;
        Storage::disk('local')->put($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        Backup::create([
            'complex_id' => $complex->id,
            'type' => 'complex',
            'status' => 'completed',
            'disk' => 'local',
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
            'note' => 'بکاپ دستی مجتمع',
            'created_by' => Auth::id(),
        ]);

        return Storage::disk('local')->download($path, $filename);
    }
}
