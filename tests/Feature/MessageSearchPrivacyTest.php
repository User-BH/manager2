<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Features\FeatureFlags;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessageSender;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 🔴 جست‌وجوی سراسری پیامِ خصوصیِ دیگران را نشان نمی‌دهد (مرحلهٔ ۶ · فاز ۱۴).
 *
 * `SearchController::messages` از نخستین نسخه فقط `is_hidden` را می‌سنجید و
 * هرگز `visibleTo` را. از وقتی پیام مخاطب گرفت (R23) و بعد گفت‌وگوی خصوصی
 * شد (فاز ۱۲)، هر ساکن با جست‌وجوی یک کلمه تکه‌ای از پیامِ خصوصیِ واحدِ
 * دیگر با مدیریت را — با نامِ نویسنده و شماره‌ی واحد — می‌دید. همه‌ی
 * سناریوهای امنیتیِ فاز ۱۲ از مسیرِ پیام‌رسان می‌گذشتند، نه از جست‌وجو.
 */
class MessageSearchPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $alice;

    private User $bob;

    private Unit $unitA;

    private Unit $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع جست‌وجو', 'slug' => 'search-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = $this->person('مدیر', '09120000091', UserRole::ComplexAdmin);
        $this->alice = $this->person('آلیس', '09120000092', UserRole::Owner);
        $this->bob = $this->person('باب', '09120000093', UserRole::Tenant);
        $this->unitA = $this->unit('101', $this->alice);
        $this->unitB = $this->unit('102', $this->bob);
    }

    private function person(string $name, string $phone, UserRole $role): User
    {
        return User::create([
            'complex_id' => $this->complex->id, 'name' => $name, 'phone' => $phone,
            'role' => $role, 'password' => Hash::make('secret123'), 'is_active' => true, 'can_message' => true,
        ]);
    }

    private function unit(string $number, User $resident, bool $current = true): Unit
    {
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => $number, 'floor' => 1, 'area' => 80,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::OwnerOccupied,
        ]);

        $resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner', 'share_percent' => 100, 'is_current' => $current,
        ]);

        return $unit;
    }

    private function write(User $author, Conversation $conversation, string $body): void
    {
        app(TenantContext::class)->set(null);
        app(MessageSender::class)->send($this->complex, $conversation, $author, ['body' => $body]);
    }

    /** @return list<string> تکه‌متن‌های پیامی که جست‌وجو به این کاربر نشان داد */
    private function found(User $user, string $term): array
    {
        app(TenantContext::class)->set(null);

        $groups = $this->actingAs($user)->getJson('/api/v1/search?q='.urlencode($term))->assertOk()->json('groups');
        $messages = collect($groups)->firstWhere('id', 'messages');

        return collect($messages['items'] ?? [])->pluck('subtitle')->all();
    }

    public function test_a_private_unit_thread_is_not_searchable_by_other_residents(): void
    {
        // ۱۰/۹: گفت‌وگوی خصوصیِ خودِ آلیس با مدیریت
        $this->write($this->alice, app(ConversationDirectory::class)->userThread($this->complex, $this->alice), 'لوله‌کشی حمامِ ما نشت دارد');

        $this->assertSame([], $this->found($this->bob, 'لوله‌کشی'), '🔴 ساکنِ واحدِ دیگر پیامِ خصوصی را در جست‌وجو دید.');
        $this->assertCount(1, $this->found($this->alice, 'لوله‌کشی'));
        $this->assertCount(1, $this->found($this->admin, 'لوله‌کشی'));
    }

    public function test_a_former_tenant_cannot_search_the_old_units_thread(): void
    {
        $former = $this->person('ساکنِ پیشین', '09120000094', UserRole::Tenant);
        $former->units()->attach($this->unitA->id, [
            'complex_id' => $this->complex->id, 'relation' => 'tenant', 'share_percent' => 100, 'is_current' => false,
        ]);

        // بایگانیِ رشته‌ی مشترکِ واحد (دادهٔ پیش از ۱۰/۹) — دیگر کسی در آن نمی‌نویسد، پس مستقیم مثلِ دادهٔ قدیم
        app(TenantContext::class)->set(null);
        $archive = app(ConversationDirectory::class)->unitThread($this->complex, $this->unitA);
        DB::table('messages')->insert([
            'complex_id' => $this->complex->id, 'conversation_id' => $archive->id, 'user_id' => $this->alice->id,
            'body' => 'رمزِ درِ پارکینگ عوض شد', 'audience' => 'management', 'unit_id' => $this->unitA->id,
            'author_name' => 'آلیس', 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->found($former, 'پارکینگ'));
        // ضدِ پوچی: ساکنِ فعلیِ واحد بایگانی را هنوز می‌یابد
        $this->assertCount(1, $this->found($this->alice, 'پارکینگ'));
    }

    public function test_a_group_is_searchable_only_by_its_members(): void
    {
        $group = app(ConversationDirectory::class)->groupForUnits($this->complex, [$this->unitA->id], $this->admin);
        $this->write($this->admin, $group, 'جلسه‌ی مالکانِ طبقه‌ی اول');

        $this->assertCount(1, $this->found($this->alice, 'مالکانِ'));
        $this->assertSame([], $this->found($this->bob, 'مالکانِ'));
    }

    public function test_the_channel_stays_searchable_by_everyone(): void
    {
        $this->write($this->admin, app(ConversationDirectory::class)->channel($this->complex), 'قطعیِ آب فردا صبح');

        $this->assertCount(1, $this->found($this->bob, 'قطعیِ'));
        $this->assertCount(1, $this->found($this->alice, 'قطعیِ'));
    }

    /**
     * پیامِ حذف‌شده پیدا نمی‌شود — حتی با نامِ نویسنده (فاز ۱۴).
     *
     * ⚠️ متنِ حذف‌شده تهی است، پس جست‌وجوی متن به‌خودیِ خود چیزی نمی‌یافت؛
     * خرابکاری نشان داد جست‌وجوی **نام** هنوز ردیفِ حذف‌شده را برمی‌گرداند.
     */
    public function test_a_deleted_message_is_not_found_even_by_its_authors_name(): void
    {
        $channel = app(ConversationDirectory::class)->channel($this->complex);
        $this->write($this->admin, $channel, 'اعلامِ قطعی');
        Message::query()->latest('id')->first()->update(['body' => '', 'deleted_at' => now()]);

        $this->assertSame([], $this->found($this->alice, 'مدیر'));
        $this->assertSame([], $this->found($this->admin, 'مدیر'));
    }

    /** پیام‌رسانِ خاموش (پرچمِ سکو) از راهِ جست‌وجو هم باز نمی‌شود. */
    public function test_search_skips_messages_when_the_messenger_is_switched_off(): void
    {
        $this->write($this->admin, app(ConversationDirectory::class)->channel($this->complex), 'قطعیِ برق');
        app(FeatureFlags::class)->set('messenger', false);

        $this->assertSame([], $this->found($this->alice, 'قطعیِ'));
    }
}
