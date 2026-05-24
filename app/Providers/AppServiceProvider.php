<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Active complex for the multi-tenant query scope. A null id means
        // "no scoping" (super-admin or console). Middleware sets it per request.
        $this->app->singleton(\App\Support\TenantContext::class);
    }

    public function boot(): void
    {
        Paginator::useTailwind();
        Date::macro('jalali', fn () => \App\Support\Jalali::date($this));
    }
}
