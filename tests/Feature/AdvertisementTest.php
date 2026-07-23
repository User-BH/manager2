<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Advertisement;
use App\Models\Complex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بنرهای تبلیغاتی صفحه‌ی فرود و پنل مدیریتشان.
 */
class AdvertisementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $complexAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // مهاجرتِ درج بنرهای پیش‌فرض هنگام تست هم اجرا می‌شود؛ برای اینکه
        // هر تست دقیقاً داده‌ی خودش را ببیند، جدول را خالی می‌کنیم.
        Advertisement::query()->delete();

        $complex = Complex::create([
            'name' => 'مجتمع تبلیغ', 'slug' => 'ads-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake',
        ]);

        $this->superAdmin = User::create([
            'name' => 'ادمین کل', 'phone' => '09129990001',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->complexAdmin = User::create([
            'complex_id' => $complex->id, 'name' => 'مدیر مجتمع', 'phone' => '09129990002',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    private function makeAd(array $attributes = []): Advertisement
    {
        return Advertisement::create($attributes + [
            'title' => 'بنر آزمایشی',
            'href' => 'https://example.com',
            'image_url' => '/images/ad-nitropanel.webp',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /* ----------------------- نقطه‌ی عمومی ----------------------- */

    public function test_guests_can_read_the_public_ad_feed(): void
    {
        $this->makeAd(['title' => 'بنر عمومی']);

        $this->getJson('/api/ads')
            ->assertOk()
            ->assertJsonCount(1, 'ads')
            ->assertJsonPath('ads.0.title', 'بنر عمومی');
    }

    public function test_public_feed_hides_inactive_and_out_of_window_ads(): void
    {
        $this->makeAd(['title' => 'دیده می‌شود']);
        $this->makeAd(['title' => 'غیرفعال', 'is_active' => false]);
        $this->makeAd(['title' => 'هنوز نرسیده', 'starts_at' => now()->addWeek()]);
        $this->makeAd(['title' => 'تمام شده', 'ends_at' => now()->subDay()]);

        $response = $this->getJson('/api/ads')->assertOk()->assertJsonCount(1, 'ads');

        $this->assertSame('دیده می‌شود', $response->json('ads.0.title'));
    }

    public function test_public_feed_skips_an_ad_whose_image_is_gone(): void
    {
        // بنری بدون هیچ منبع تصویر نباید قاب خالی روی صفحه‌ی فرود بگذارد
        $this->makeAd(['title' => 'بی‌تصویر', 'image_url' => null]);

        $this->getJson('/api/ads')->assertOk()->assertJsonCount(0, 'ads');
    }

    /* ----------------------- دسترسی پنل ----------------------- */

    public function test_a_complex_admin_cannot_manage_platform_ads(): void
    {
        $ad = $this->makeAd();

        $this->actingAs($this->complexAdmin)->getJson('/api/system/ads')->assertStatus(403);
        $this->actingAs($this->complexAdmin)->deleteJson("/api/system/ads/{$ad->id}")->assertStatus(403);
    }

    public function test_a_guest_cannot_manage_ads(): void
    {
        $this->postJson('/api/system/ads', ['title' => 'نفوذی'])->assertStatus(401);
    }

    /* ----------------------- ساخت و ویرایش ----------------------- */

    public function test_super_admin_can_create_an_ad_with_an_image(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin)->post('/api/system/ads', [
            'title' => 'بنر تازه',
            'subtitle' => 'توضیح کوتاه',
            'href' => 'https://nitropanel.ir/',
            'sort_order' => 2,
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('banner.jpg', 1600, 520),
        ])->assertStatus(201);

        $ad = Advertisement::firstWhere('title', 'بنر تازه');

        $this->assertNotNull($ad->image_path);
        Storage::disk('local')->assertExists($ad->image_path);
        $this->assertSame(2, $ad->sort_order);
    }

    public function test_a_dangerous_link_scheme_is_rejected(): void
    {
        Storage::fake('local');

        // بدون این محدودیت، `javascript:` روی صفحه‌ی فرودِ عمومی می‌نشست
        $this->actingAs($this->superAdmin)->post('/api/system/ads', [
            'title' => 'بنر بد',
            'href' => 'javascript:alert(1)',
            'image' => UploadedFile::fake()->image('b.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('href');
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin)->post('/api/system/ads', [
            'title' => 'بازه‌ی وارونه',
            'href' => 'https://example.com',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->toDateString(),
            'image' => UploadedFile::fake()->image('b.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_updating_the_image_removes_the_previous_file(): void
    {
        Storage::fake('local');

        $ad = $this->makeAd();
        $ad->update(['image_path' => UploadedFile::fake()->image('old.jpg')->store('ads', 'local')]);
        $old = $ad->image_path;

        $this->actingAs($this->superAdmin)->post("/api/system/ads/{$ad->id}", [
            'title' => 'عنوان تازه',
            'href' => 'https://example.com',
            'is_active' => '1',
            'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertOk();

        $ad->refresh();

        $this->assertNotSame($old, $ad->image_path);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($ad->image_path);
        $this->assertSame('عنوان تازه', $ad->title);
    }

    public function test_updating_without_a_new_image_keeps_the_current_one(): void
    {
        Storage::fake('local');

        $ad = $this->makeAd();
        $ad->update(['image_path' => UploadedFile::fake()->image('keep.jpg')->store('ads', 'local')]);
        $kept = $ad->image_path;

        $this->actingAs($this->superAdmin)->post("/api/system/ads/{$ad->id}", [
            'title' => 'فقط عنوان عوض شد',
            'href' => 'https://example.com',
            'is_active' => '1',
        ])->assertOk();

        $this->assertSame($kept, $ad->fresh()->image_path);
        Storage::disk('local')->assertExists($kept);
    }

    /* ----------------------- وضعیت و حذف ----------------------- */

    public function test_toggling_hides_the_ad_from_the_public_feed(): void
    {
        $ad = $this->makeAd();

        $this->actingAs($this->superAdmin)
            ->patchJson("/api/system/ads/{$ad->id}/toggle")
            ->assertOk()
            ->assertJsonPath('ad.isActive', false);

        $this->getJson('/api/ads')->assertOk()->assertJsonCount(0, 'ads');
    }

    public function test_deleting_an_ad_removes_its_uploaded_image(): void
    {
        Storage::fake('local');

        $ad = $this->makeAd();
        $ad->update(['image_path' => UploadedFile::fake()->image('gone.jpg')->store('ads', 'local')]);
        $path = $ad->image_path;

        $this->actingAs($this->superAdmin)->deleteJson("/api/system/ads/{$ad->id}")->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('advertisements', ['id' => $ad->id]);
    }

    public function test_uploaded_ad_images_are_served_publicly(): void
    {
        Storage::fake('local');

        $ad = $this->makeAd();
        $ad->update(['image_path' => UploadedFile::fake()->image('shown.jpg')->store('ads', 'local')]);

        // صفحه‌ی فرود پیش از ورود کاربر دیده می‌شود، پس تصویر باید برای
        // مهمان هم قابل دریافت باشد.
        $cacheControl = $this->get("/ads/{$ad->id}/image")
            ->assertOk()
            ->headers->get('Cache-Control');

        // ترتیب دستورها را نمی‌شود تضمین کرد، پس خودِ دستورها بررسی می‌شوند
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
    }

    public function test_a_missing_image_file_returns_404_instead_of_a_broken_stream(): void
    {
        Storage::fake('local');

        $ad = $this->makeAd();
        $ad->update(['image_path' => 'ads/never-existed.jpg']);

        $this->get("/ads/{$ad->id}/image")->assertStatus(404);
    }
}
