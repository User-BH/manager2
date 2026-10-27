<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * نمونه‌ای از سریِ تکرارشونده که کنار گذاشته شد — «فقط همین» در ویرایش یا حذف (ایرادات ۱۰، بندِ ۸۵).
 *
 * @property int $reminder_id
 * @property CarbonImmutable $occurrence
 */
class ReminderSkip extends Model
{
    protected $fillable = ['reminder_id', 'occurrence'];

    protected function casts(): array
    {
        return ['occurrence' => 'immutable_date'];
    }
}
