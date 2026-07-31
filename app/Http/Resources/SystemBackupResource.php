<?php

namespace App\Http\Resources;

use App\Models\Backup;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * بکاپ در پنلِ ادمینِ کل (شاملِ بکاپِ کلِ سیستم).
 *
 * از `present()` در `Api/System/BackupController.php` بیرون کشیده شد (R9b). شکلِ خروجی کلمه‌به‌کلمه
 * همان است؛ فقط حالا یک نقطه‌ی حقیقت دارد و افزودنِ فیلدِ تازه فقط همین‌جا
 * انجام می‌شود، نه در هر کنترلری که همان مدل را برمی‌گرداند.
 *
 * کلیدها camelCase‌اند چون مصرف‌کننده TypeScript است؛ تبدیل در همین لایه است
 * تا تغییرِ نامِ ستونِ دیتابیس قراردادِ API را نشکند.
 *
 * @property-read Backup $resource
 */
class SystemBackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $backup = $this->resource;

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
