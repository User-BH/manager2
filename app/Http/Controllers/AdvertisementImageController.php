<?php

namespace App\Http\Controllers;

use App\Models\Advertisement;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * سرو کردن تصویر بنرهای آپلودشده.
 *
 * چرا از مسیر PHP و نه مستقیم از پوشه‌ی public؟ چون راه استاندارد لاراول
 * (دیسک public + `storage:link`) به یک symlink در زمان استقرار وابسته است
 * و اگر ساخته نشود، تصاویرِ صفحه‌ی فرود — دیده‌شده‌ترین صفحه‌ی سایت —
 * بی‌سروصدا خراب می‌شوند. اینجا هزینه‌اش یک درخواست PHP است که با هدر کش
 * طولانی عملاً فقط بار اول پرداخت می‌شود.
 */
class AdvertisementImageController extends Controller
{
    public function __invoke(Advertisement $advertisement): StreamedResponse
    {
        abort_if(
            ! $advertisement->image_path
                || ! Storage::disk('local')->exists($advertisement->image_path),
            404,
        );

        // آدرس تصویر پارامتر نسخه (?v=updated_at) دارد، پس با تعویض فایل
        // آدرس عوض می‌شود و می‌توان محتوای فعلی را برای همیشه کش کرد.
        return Storage::disk('local')->response($advertisement->image_path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
