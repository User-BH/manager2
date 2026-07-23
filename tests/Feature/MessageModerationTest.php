<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * مخفی‌کردن پیام در پیام‌رسان، و پاسخ سرور به حساب غیرفعال‌شده.
 *
 * پیش از این «مخفی کردن» فقط یک پرچم بود: متن کامل پیام برای همه‌ی ساکنین
 * فرستاده می‌شد و رابط کاربری صرفاً کم‌رنگش می‌کرد.
 */
class MessageModerationTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $resident;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع پیام', 'slug' => 'msg-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09201110001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن', 'phone' => '09201110002',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);

        $this->message = Message::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'user_id' => $this->resident->id,
            'body' => 'متن حساسی که باید پنهان شود', 'author_name' => 'ساکن',
            'author_role' => 'owner', 'unit_label' => '۱',
        ]);
    }

    private function hideTheMessage(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/messenger/{$this->message->id}/toggle-hide")
            ->assertOk()
            ->assertJsonPath('message.isHidden', true);
    }

    /* --------------------- مخفی‌کردن پیام --------------------- */

    public function test_a_hidden_messages_text_never_reaches_a_resident(): void
    {
        $this->hideTheMessage();

        $response = $this->actingAs($this->resident)->getJson('/api/messenger')->assertOk();

        // پیام هست (تا جای خالی در گفت‌وگو نماند) ولی متنش نیست
        $response->assertJsonPath('messages.0.isHidden', true)
            ->assertJsonPath('messages.0.body', null);

        // و متن هیچ‌جای پاسخ پیدا نمی‌شود، حتی در فیلدی دیگر
        $response->assertDontSee('متن حساسی که باید پنهان شود');
    }

    public function test_an_admin_still_sees_the_text_so_they_can_undo(): void
    {
        $this->hideTheMessage();

        $this->actingAs($this->admin)->getJson('/api/messenger')
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'متن حساسی که باید پنهان شود');
    }

    public function test_a_client_that_already_loaded_the_message_is_told_it_is_hidden(): void
    {
        /*
         * واکشی افزایشی فقط پیام‌های جدیدتر از `since` را می‌آورد، پس کلاینتی
         * که پیام را پیش از مخفی‌شدن گرفته هرگز خبردار نمی‌شد و متن روی
         * صفحه‌اش می‌ماند. `hiddenIds` همین شکاف را می‌بندد.
         */
        $this->hideTheMessage();

        $this->actingAs($this->resident)
            ->getJson('/api/messenger?since='.$this->message->id)
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('hiddenIds', [$this->message->id]);
    }

    public function test_unhiding_brings_the_text_back(): void
    {
        $this->hideTheMessage();

        $this->actingAs($this->admin)
            ->patchJson("/api/messenger/{$this->message->id}/toggle-hide")
            ->assertOk()
            ->assertJsonPath('message.isHidden', false);

        $this->actingAs($this->resident)->getJson('/api/messenger')
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'متن حساسی که باید پنهان شود')
            ->assertJsonPath('hiddenIds', []);
    }

    public function test_a_resident_cannot_hide_a_message(): void
    {
        $this->actingAs($this->resident)
            ->patchJson("/api/messenger/{$this->message->id}/toggle-hide")
            ->assertStatus(403);
    }

    public function test_a_hidden_message_is_not_findable_through_search(): void
    {
        $this->hideTheMessage();

        $groups = $this->actingAs($this->resident)->getJson('/api/search?q=حساسی')
            ->assertOk()->json('groups');

        $this->assertNotContains('messages', array_column($groups, 'id'));
    }

    /* --------------------- حساب غیرفعال‌شده --------------------- */

    public function test_a_deactivated_user_gets_json_not_an_html_redirect(): void
    {
        $this->actingAs($this->resident);
        $this->resident->update(['is_active' => false]);

        $this->getJson('/api/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('accountDisabled', true)
            ->assertJsonPath('message', 'حساب کاربری شما غیرفعال شده است. با مدیر ساختمان تماس بگیرید.');
    }

    public function test_a_deactivated_users_session_is_destroyed(): void
    {
        $this->actingAs($this->resident);
        $this->resident->update(['is_active' => false]);

        $this->getJson('/api/dashboard')->assertStatus(403);

        // نشست باید بسته شده باشد، نه فقط این یک درخواست رد شود
        $this->assertGuest();
    }

    public function test_a_browser_request_still_gets_a_redirect(): void
    {
        // مسیرهای غیر-API (مثل دانلود PDF) همچنان باید به صفحه‌ی ورود بروند
        $this->actingAs($this->resident);
        $this->resident->update(['is_active' => false]);

        $this->get('/bills/1/invoice.pdf')->assertRedirect(route('login'));
    }
}
