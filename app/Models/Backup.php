<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک نسخه‌ی پشتیبان — چه مجتمعی، چه کاملِ سیستم.
 *
 * `status` سه حالت دارد: `pending` (در صف)، `completed`، `failed`. تا پیش از
 * R11 همیشه مستقیم `completed` نوشته می‌شد، چون ساختِ فایل همان‌جا و همزمان
 * انجام می‌گرفت؛ حالا که در صف ساخته می‌شود، هر سه حالت واقعاً رخ می‌دهند.
 *
 * @property int $id
 * @property int|null $complex_id
 * @property string $type
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size
 * @property string|null $note
 * @property int|null $created_by
 * @property-read Complex|null $complex
 */
class Backup extends Model
{
    protected $fillable = [
        'complex_id', 'type', 'status', 'disk', 'path', 'size', 'note', 'created_by',
    ];

    /**
     * مجتمعِ صاحبِ این بکاپ — برای بکاپِ کاملِ سیستم `null` است.
     *
     * پیش از R11 این رابطه اصلاً تعریف نشده بود و هیچ‌کس متوجه نشد، چون تا آن
     * موقع هیچ کدی به `$backup->complex` نیاز نداشت؛ Eloquent هم برای ویژگیِ
     * ناموجود بی‌صدا `null` می‌دهد و نه خطا.
     *
     * @return BelongsTo<Complex, $this>
     */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }
}
