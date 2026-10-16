<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\Account\ProfilePhoto;
use App\Support\Audit;
use App\Support\SessionContext;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تصویرِ پروفایل (مرحلهٔ ۱۰ · فاز ۷؛ بندِ ۵): خودِ کاربر می‌گذارد و برمی‌دارد؛ سرو فقط از این مسیرِ کنترل‌شده.
 */
class AvatarController extends Controller
{
    public function __construct(private readonly ProfilePhoto $photos) {}

    public function store(UploadAvatarRequest $request): JsonResponse
    {
        $user = $this->photos->store(Auth::user(), $request->file('avatar'));
        Audit::log('profile.avatar_changed', 'تغییرِ تصویرِ پروفایل', $user);

        return response()->json([
            'profile' => (new ProfileResource($user))->toArray($request),
            'message' => 'تصویرِ پروفایل ذخیره شد.',
        ]);
    }

    public function destroy(): JsonResponse
    {
        $user = Auth::user();
        $this->photos->remove($user);
        Audit::log('profile.avatar_removed', 'برداشتنِ تصویرِ پروفایل', $user);

        return response()->json([
            'profile' => (new ProfileResource(SessionContext::fresh($user)))->toArray(request()),
            'message' => 'تصویرِ پروفایل برداشته شد.',
        ]);
    }

    /**
     * سرو برای کسی که حق دیدن دارد (`ProfilePhoto::canView`). ۴۰۴ ِ یکسان برای «نیست»، «تصویر ندارد» و «حق نداری»:
     * پاسخ نمی‌گوید این حساب وجود دارد یا عضوِ کجاست.
     */
    public function show(string $user): StreamedResponse
    {
        $subject = ctype_digit($user) ? User::query()->find((int) $user) : null;

        abort_if($subject === null || $subject->avatar_path === null || ! ProfilePhoto::canView(Auth::user(), $subject), 404);

        // نشانی نسخه دارد (`?v=`)، پس کشِ طولانی بی‌خطر است؛ `private` چون مجوز به بیننده بسته است
        return Uploads::serve($subject->avatar_path, ['Cache-Control' => 'private, max-age=86400']);
    }
}
