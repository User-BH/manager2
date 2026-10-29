<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `route:cache` ِ سرور نامِ تکراری را نمی‌پذیرد (Preview، ۲۰۲۶-۱۰-۲۹): نامِ مستعارِ بی‌نسخه‌ی `/api/login` با `login` ِ وب
 * هم‌نام بود و استقرار کشِ مسیر را نمی‌ساخت. CI خودش `route:cache` نمی‌زد، پس این این‌جا سنجیده می‌شود.
 */
class RouteNamesTest extends TestCase
{
    public function test_no_two_routes_share_a_name(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if (($name = $route->getName()) !== null) {
                $names[$name][] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], array_filter($names, fn (array $uris) => count($uris) > 1), 'چند مسیر یک نام دارند.');
    }

    public function test_the_routes_can_be_cached_as_on_the_server(): void
    {
        try {
            $this->assertSame(0, Artisan::call('route:cache'));
        } finally {
            Artisan::call('route:clear');
        }
    }
}
