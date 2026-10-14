<?php

namespace App\Models\Concerns;

use App\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * صاحبِ تاریخچه با Eloquent حذفِ سخت نمی‌شود (مرحلهٔ ۱۰ · فاز ۱۴؛ بندِ ۷۹).
 *
 * مجتمع تعلیق می‌شود، عضویت و دوره‌ی سکونت پایان می‌یابند، پیام «حذف‌شده» علامت می‌خورد و گفت‌وگو فقط‌خواندنی؛ هیچ‌کدام
 * عملیاتِ کاربریِ «حذف» ندارند. مدلِ دارای `SoftDeletes` (واحد) همچنان نرم حذف می‌شود و فقط `forceDelete` بسته است.
 *
 * ⚠️ این سدِ Eloquent است؛ سدِ پایگاه‌داده کلیدهای `restrictOnDelete` است
 * (`2026_10_18_000001_restrict_deletes_on_history`) — بازیابیِ بکاپ با کلیدهای خاموش عمداً از کنارِ هر دو رد می‌شود.
 */
trait KeepsHistory
{
    protected static function bootKeepsHistory(): void
    {
        $refuse = function (Model $model): void {
            throw DomainException::invalid(
                'این رکورد بخشی از تاریخچه است و حذف نمی‌شود؛ پایانش دهید، تعلیقش کنید یا پنهانش کنید.',
                'history.not_deletable',
            );
        };

        static::registerModelEvent(
            in_array(SoftDeletes::class, class_uses_recursive(static::class), true) ? 'forceDeleting' : 'deleting',
            $refuse,
        );
    }
}
