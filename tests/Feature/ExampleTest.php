<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_landing_page_and_protected_pages_require_login(): void
    {
        // `/` is the public landing page for visitors, not a redirect.
        $this->get('/')->assertStatus(200)->assertSee(config('brand.name'), false);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertStatus(200);
    }

    public function test_signed_in_users_are_sent_from_the_landing_page_to_their_dashboard(): void
    {
        $user = User::create([
            'name' => 'تست', 'phone' => '09121234567', 'role' => UserRole::Tenant,
            'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }
}
