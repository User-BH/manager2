<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * قراردادِ API: شکلِ خطاها، نسخه‌بندی، و مستند.
 *
 * این‌ها قیدهایی‌اند که شکستنشان بی‌صدا است: کسی یک استثنای تازه اضافه می‌کند
 * و شکلِ پاسخش با بقیه فرق دارد، یا مسیری فقط روی یکی از دو نسخه ثبت می‌شود.
 * هیچ‌کدام تا وقتی کاربر به آن نخورد دیده نمی‌شوند.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_use_the_shared_shape(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'validation_failed'])
            ->assertJsonStructure(['success', 'message', 'code', 'errors']);
    }

    public function test_unauthenticated_requests_use_the_shared_shape(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'unauthenticated',
                'message' => 'برای این کار باید وارد شوید.',
            ]);
    }

    public function test_forbidden_requests_use_the_shared_shape(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner, 'is_active' => true]))
            ->getJson('/api/v1/system/members')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'code' => 'forbidden']);
    }

    public function test_missing_records_use_the_shared_shape(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]))
            ->getJson('/api/v1/my-bills/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'code' => 'not_found']);
    }

    /*
     * دو تستِ زیر رندرر را مستقیم صدا می‌زنند و نه از راهِ HTTP.
     *
     * دلیلش: ثبتِ مسیرِ ساختگی در زمانِ اجرا فایده ندارد، چون `web.php` یک
     * catch-all برای اسپا دارد که زودتر ثبت شده و درخواست را می‌قاپد. صدا
     * زدنِ مستقیم هم دقیق‌تر است و هم چیزی را که واقعاً می‌سنجیم جدا می‌کند.
     */
    public function test_domain_exception_carries_its_machine_code(): void
    {
        $request = Request::create('/api/v1/anything');
        $request->headers->set('Accept', 'application/json');

        $response = ApiExceptionRenderer::render(
            DomainException::conflict('همین حالا نمی‌شود.', 'test.conflict'),
            $request,
        );

        $this->assertNotNull($response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'همین حالا نمی‌شود.',
            'code' => 'test.conflict',
        ], $response->getData(true));
    }

    public function test_server_errors_do_not_leak_internals_in_production(): void
    {
        // `app.debug` خاموش ⇒ کاربر نباید پیامِ خام (مسیر فایل، نام جدول) ببیند
        config()->set('app.debug', false);

        $request = Request::create('/api/v1/anything');
        $request->headers->set('Accept', 'application/json');

        $response = ApiExceptionRenderer::render(
            new \RuntimeException('SQLSTATE[42S02]: Base table users_secret not found'),
            $request,
        );

        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('server_error', $response->getData(true)['code']);
        $this->assertStringNotContainsString('users_secret', $response->getData(true)['message']);
    }

    public function test_debug_mode_still_shows_the_real_message_to_developers(): void
    {
        config()->set('app.debug', true);

        $request = Request::create('/api/v1/anything');
        $request->headers->set('Accept', 'application/json');

        $response = ApiExceptionRenderer::render(new \RuntimeException('جزئیاتِ فنی'), $request);

        $this->assertSame('جزئیاتِ فنی', $response?->getData(true)['message']);
    }

    public function test_every_route_is_reachable_on_both_v1_and_the_legacy_prefix(): void
    {
        $versioned = [];
        $legacy = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/v1/')) {
                $versioned[] = substr($uri, 7);
            } elseif (str_starts_with($uri, 'api/')) {
                $legacy[] = substr($uri, 4);
            }
        }

        sort($versioned);
        sort($legacy);

        // اگر روزی یکی از دو ثبت حذف شود، مصرف‌کننده‌های قدیمی بی‌صدا می‌شکنند
        $this->assertSame($versioned, $legacy);
        $this->assertNotEmpty($versioned);
    }

    public function test_the_same_endpoint_answers_on_both_prefixes(): void
    {
        $this->getJson('/api/v1/csrf-token')->assertOk();
        $this->getJson('/api/csrf-token')->assertOk();
    }

    public function test_openapi_spec_is_generated_from_real_routes(): void
    {
        $output = 'storage/framework/testing/openapi-test.json';

        $this->artisan('openapi:generate', ['--output' => $output])->assertSuccessful();

        $spec = json_decode(File::get(base_path($output)), true);

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/api/v1/csrf-token', $spec['paths']);
        $this->assertArrayHasKey('Error', $spec['components']['schemas']);

        // مسیرِ سازگاری نباید در قرارداد ظاهر شود
        $this->assertArrayNotHasKey('/api/csrf-token', $spec['paths']);

        File::delete(base_path($output));
    }

    public function test_openapi_reads_field_rules_from_form_requests(): void
    {
        $output = 'storage/framework/testing/openapi-body.json';
        $this->artisan('openapi:generate', ['--output' => $output])->assertSuccessful();

        $spec = json_decode(File::get(base_path($output)), true);
        $schema = $spec['paths']['/api/v1/announcements']['post']['requestBody']['content']['application/json']['schema'];

        // قواعد از StoreAnnouncementRequest می‌آیند، نه از متنِ دستی
        $this->assertSame(['title', 'body', 'audience'], $schema['required']);
        $this->assertSame('boolean', $schema['properties']['is_pinned']['type']);
        $this->assertSame('عنوان', $schema['properties']['title']['description']);

        File::delete(base_path($output));
    }
}
