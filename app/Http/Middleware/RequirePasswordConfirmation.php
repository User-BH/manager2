<?php

namespace App\Http\Middleware;

use App\Services\Auth\PasswordConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نگهبانِ مسیرهای حساس: بدونِ تأییدِ تازه‌ی رمز، ۴۰۳ با کدِ
 * `auth.reauth_required` (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ⚠️ پیش از کنترلر می‌ایستد، پس درخواستِ ردشده هیچ اثری نگذاشته و فرانت
 * می‌تواند پس از تأیید همان را بی‌خطر دوباره بفرستد.
 *
 * دانلودِ بکاپ پیوندِ معمولیِ مرورگر است نه درخواستِ JSON؛ برای آن ۴۰۳ِ
 * ساده برمی‌گردد و رابط پیش از رفتن به پیوند تأیید را می‌گیرد.
 */
class RequirePasswordConfirmation
{
    public function __construct(private readonly PasswordConfirmation $confirmation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->expectsJson() && ! $this->confirmation->isRecent($request)) {
            abort(403, 'برای این کار ابتدا رمز عبورتان را در برنامه دوباره وارد کنید.');
        }

        $this->confirmation->ensure($request);

        return $next($request);
    }
}
