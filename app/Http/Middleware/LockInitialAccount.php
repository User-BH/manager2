<?php

namespace App\Http\Middleware;

use App\Enums\AccountState;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حسابِ «حالتِ اولیه» فقط‌خواندنی است (R21).
 *
 * ─── چرا میان‌افزار و نه بررسی در هر کنترلر ────────────────────────────────
 * قفلی که در هر کنترلر جداگانه نوشته شود، در کنترلرِ بعدی که کسی اضافه
 * می‌کند نوشته نمی‌شود. اینجا قاعده یک جاست و **پیش‌فرضش بستن** است: هر
 * مسیرِ تازه‌ای هم که فردا اضافه شود خودبه‌خود قفل است.
 *
 * ─── چرا پذیرشِ دعوت استثناست ──────────────────────────────────────────────
 * تنها نوشتنی که این کاربر باید بتواند انجام دهد، همان کاری است که او را از
 * این حالت بیرون می‌برد. بدونِ این استثنا، قفل خودش را قفل می‌کرد.
 */
class LockInitialAccount
{
    /**
     * مسیرهایی که حتی در حالتِ اولیه باز می‌مانند.
     *
     * الگوها با `Request::is()` سنجیده می‌شوند، پس هر دو پیشوندِ `api/` و
     * `api/v1/` را پوشش می‌دهند.
     */
    private const ALLOWED = [
        // بیرون‌رفتن از حالتِ اولیه
        '*/invitations/*',
        // خرید اشتراک — راهِ دوم بیرون‌رفتن
        '*/subscription/*',
        '*/subscription',
        // حسابِ خودش
        '*/profile',
        '*/profile/*',
        '*/logout',
        // گزارشِ خطای مرورگر (نوشتنی است ولی داده‌ی مجتمع نیست)
        '*/client-errors',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isReadOnlyRequest($request) || ! $this->isInitialAccount($request)) {
            return $next($request);
        }

        foreach (self::ALLOWED as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'برای این کار باید ابتدا به یک مجتمع بپیوندید یا اشتراک تهیه کنید.',
            'code' => 'account.initial_read_only',
            // فرانت با این پرچم راهنمای «چه کنم؟» را نشان می‌دهد
            'accountState' => AccountState::Initial->value,
        ], 403);
    }

    private function isReadOnlyRequest(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function isInitialAccount(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && ! AccountState::of($user)->canWrite();
    }
}
