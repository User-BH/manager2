<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the active complex id for the request so the global ComplexScope
 * isolates every tenant-scoped query. Non-super-admins are locked to their
 * own complex; the super-admin may opt into one via session selection.
 */
class SetCurrentComplex
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $complexId = $user->isSuperAdmin()
                ? $request->session()->get('active_complex_id')
                : $user->complex_id;

            if ($complexId) {
                app(TenantContext::class)->set((int) $complexId);
            }
        }

        return $next($request);
    }
}
