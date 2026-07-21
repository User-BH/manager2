<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * بکاپ مجتمع جاری.
 *
 * برخلاف کنترلر Blade که ساخت و دانلود را در یک درخواست انجام می‌داد، اینجا
 * دو مرحله جداست: POST فایل را می‌سازد و مشخصاتش را برمی‌گرداند تا فهرست
 * به‌روز شود، و دانلود یک GET جداگانه است که مرورگر مستقیم بازش می‌کند.
 */
class BackupController extends Controller
{
    public function index(): JsonResponse
    {
        $complexId = $this->currentComplex()?->id;

        $backups = Backup::where('complex_id', $complexId)->latest()->get();

        return response()->json([
            'data' => $backups->map(fn (Backup $b) => $this->present($b))->values(),
        ]);
    }

    public function store(): JsonResponse
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

        $backup = Backup::create([
            'complex_id' => $complex->id,
            'type' => 'complex',
            'status' => 'completed',
            'disk' => 'local',
            'path' => $path,
            'size' => Storage::disk('local')->size($path),
            'note' => 'بکاپ دستی مجتمع',
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'بکاپ ساخته شد.',
            'backup' => $this->present($backup),
        ], 201);
    }

    public function download(Backup $backup): StreamedResponse
    {
        // بکاپ یک مجتمع نباید از مجتمع دیگری قابل دانلود باشد.
        abort_if($backup->complex_id !== $this->requireComplex()->id, 403);
        abort_if(! $backup->path || ! Storage::disk('local')->exists($backup->path), 404);

        return Storage::disk('local')->download($backup->path);
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
            'downloadUrl' => route('api.backups.download', $backup),
        ];
    }
}
