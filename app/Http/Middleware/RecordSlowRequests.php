<?php

namespace App\Http\Middleware;

use App\Services\Health\SlowRequests;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * درخواستِ کندتر از آستانه شمرده و لاگ می‌شود (مرحلهٔ ۵ · فاز ۹ · بندِ ۷۸).
 *
 * ⚠️ در لاگ **نامِ مسیر** می‌آید نه آدرس: کوئری جایی است که دادهٔ خصوصی
 * می‌نشیند (همان قاعده‌ی فاز ۷). نامِ مسیر برای یافتنِ کندی کافی است.
 */
class RecordSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        $response = $next($request);

        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($ms >= (int) config('alerts.latency.slow_ms', 2000)) {
            SlowRequests::record();

            Log::warning('درخواستِ کند', [
                'route' => $request->route()?->getName() ?? $request->route()?->uri(),
                'method' => $request->method(),
                'ms' => $ms,
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }
}
