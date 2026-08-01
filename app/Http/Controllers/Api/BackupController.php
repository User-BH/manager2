<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Jobs\BuildBackupJob;
use App\Models\Backup;
use App\Support\Audit;
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

        /*
         * رکورد **پیش از** صف‌شدن ساخته می‌شود تا کاربر بلافاصله ردیفش را با
         * وضعیت «در حال ساخت» ببیند. اگر برعکس بود، دکمه را می‌زد و تا اجرای
         * Job هیچ نشانه‌ای از کارش نمی‌دید.
         */
        $backup = Backup::create([
            'complex_id' => $complex->id,
            'type' => 'complex',
            'status' => 'pending',
            'disk' => 'local',
            'note' => 'بکاپ دستی مجتمع',
            'created_by' => Auth::id(),
        ]);

        BuildBackupJob::dispatch($backup->id);

        Audit::log('backup.created', 'ساخت نسخه پشتیبان مجتمع', $backup);

        // ۲۰۲ و نه ۲۰۱: کار پذیرفته شده ولی هنوز تمام نشده
        return response()->json([
            'message' => 'ساخت بکاپ شروع شد. تا لحظاتی دیگر آماده می‌شود.',
            'backup' => $this->present($backup),
        ], 202);
    }

    public function download(Backup $backup): StreamedResponse
    {
        // بکاپ یک مجتمع نباید از مجتمع دیگری قابل دانلود باشد.
        $this->authorize('download', $backup);
        abort_if(! $backup->path || ! Storage::disk('local')->exists($backup->path), 404);

        return Storage::disk('local')->download($backup->path);
    }

    /**
     * شکلِ خروجی حالا در `BackupResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Backup $backup): array
    {
        return (new BackupResource($backup))->toArray(request());
    }
}
