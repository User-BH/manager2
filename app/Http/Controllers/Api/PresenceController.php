<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Messenger\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * «آفلاین شدم» — بستنِ صفحه (`pagehide`، با `fetch` ِ keepalive) را همان لحظه آفلاین می‌کند («چند نکته»، بندِ ۳).
 *
 * فقط حضورِ خودِ همین حساب را پاک می‌کند. اگر نرسد (موبایل همیشه `pagehide` نمی‌دهد)، پنجره‌ی
 * `Presence::WINDOW` همان کار را دیرتر می‌کند.
 */
class PresenceController extends Controller
{
    public function offline(): JsonResponse
    {
        Presence::forget(Auth::user());

        return response()->json(['online' => false]);
    }
}
