<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Messenger\Presence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ضربانِ حضور («چند نکته»، بندِ ۳) — روی همان درخواست‌هایی که داشبوردِ باز به هر حال می‌فرستد.
 *
 * فقط عضوِ فعالِ یک مجتمع: `SetCurrentComplex` مجتمع را فقط وقتی ثبت می‌کند که عضویتِ فعالِ هم‌خوان باشد؛ عضوِ
 * معلق یا رفته پیش از این‌جا ۴۰۳ گرفته، و ادمینِ کل عضوِ هیچ گروهی نیست. خروج و «آفلاین شدم» خودشان حضور را
 * پاک می‌کنند و ضربان نمی‌زنند. پیش از کنترلر، تا پاسخِ خطای کنترلر هم «دیده شدن» را نگه دارد.
 */
class TouchPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $request->attributes->has(SetCurrentComplex::COMPLEX)
            && ! $request->routeIs('logout', '*.logout', 'presence.offline', '*.presence.offline')) {
            Presence::touch($user);
        }

        return $next($request);
    }
}
