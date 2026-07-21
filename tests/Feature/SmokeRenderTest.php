<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every GET page for each role against the demo data, so a Blade or
 * runtime error anywhere in the UI fails the suite instead of being found by
 * clicking through the app by hand.
 */
class SmokeRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function as(string $phone): User
    {
        return User::where('phone', $phone)->firstOrFail();
    }

    private function visit(User $user, array $urls): void
    {
        foreach ($urls as $url) {
            $response = $this->actingAs($user)->get($url);

            $this->assertSame(200, $response->status(), "GET {$url} returned {$response->status()}");
        }
    }

    public function test_guest_pages_render(): void
    {
        $this->get('/')->assertStatus(200);
        $this->get('/login')->assertStatus(200);
    }

    public function test_complex_admin_pages_render(): void
    {
        $admin = $this->as('09120000000');

        $this->visit($admin, [
            '/dashboard',
            '/announcements',
            '/messenger',
            '/good-payers',
            '/admin/units',
            '/admin/units/create',
            '/admin/residents',
            '/admin/residents/create',
            '/admin/managers',
            '/admin/charge-rules',
            '/admin/expenses',
            '/admin/bills',
            '/admin/payments',
            '/admin/discounts',
            '/admin/announcements',
            '/admin/settings',
            '/admin/backup',
        ]);

        $unit = Unit::firstOrFail();
        $this->visit($admin, [
            "/admin/units/{$unit->id}/edit",
            "/admin/units/{$unit->id}/statement",
        ]);
    }

    public function test_super_admin_pages_render(): void
    {
        $super = $this->as('09120000001');

        $this->visit($super, [
            '/dashboard',
            '/system/complexes',
            '/system/sms',
            '/system/backup',
        ]);
    }

    public function test_resident_pages_render(): void
    {
        $owner = $this->as('09121111101');

        $this->visit($owner, [
            '/dashboard',
            '/my/bills',
            '/announcements',
            '/messenger',
            '/good-payers',
        ]);

        $bill = Bill::whereIn('unit_id', $owner->units()->pluck('units.id'))->first();

        if ($bill) {
            $this->visit($owner, ["/my/bills/{$bill->id}", "/pay/{$bill->id}"]);
        }

        $tenant = $this->as('09122222202');
        $this->visit($tenant, ['/dashboard', '/my/bills']);
    }
}
