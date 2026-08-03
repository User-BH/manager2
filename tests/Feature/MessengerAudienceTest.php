<?php

namespace Tests\Feature;

use App\Enums\MessageAudience;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مخاطبِ پیام (R23).
 *
 * پیش از این، پیام‌رسان یک کانالِ واحد بود: **هر پیام به همه می‌رسید**. یعنی
 * ساکن نمی‌توانست چیزی خصوصی به مدیر بگوید و مدیر نمی‌توانست فقط به یک واحد
 * پیام بدهد.
 *
 * قاعده: ساکن فقط به مدیریت؛ مدیر به همه یا به واحدهای انتخابی.
 */
class MessengerAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unitA;

    private Unit $unitB;

    private User $manager;

    private User $residentA;

    private User $residentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);

        $this->unitA = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '1']);
        $this->unitB = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '2']);

        $this->manager = $this->makeUser(UserRole::ComplexAdmin);
        $this->residentA = $this->makeUser(UserRole::Owner, $this->unitA);
        $this->residentB = $this->makeUser(UserRole::Owner, $this->unitB);
    }

    /* ── ساکن فقط به مدیریت ─────────────────────────────────────────────── */

    public function test_a_residents_message_always_goes_to_management(): void
    {
        $this->actingAs($this->residentA)
            ->postJson('/api/v1/messenger', ['body' => 'لطفاً آسانسور را تعمیر کنید'])
            ->assertStatus(201);

        $message = Message::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame(MessageAudience::Management, $message->audience);
        $this->assertSame($this->unitA->id, $message->unit_id);
    }

    /**
     * ساکن نمی‌تواند با دست‌کاریِ درخواست به همه پیام بدهد.
     *
     * پنهان‌کردنِ گزینه در رابط کافی نیست؛ قاعده باید سمتِ سرور اعمال شود.
     */
    public function test_a_resident_cannot_broadcast_by_forging_the_request(): void
    {
        $this->actingAs($this->residentA)
            ->postJson('/api/v1/messenger', [
                'body' => 'سلام به همه',
                'audience' => 'all',
            ])
            ->assertStatus(201);

        $this->assertSame(
            MessageAudience::Management,
            Message::withoutGlobalScopes()->latest('id')->first()->audience,
        );
    }

    public function test_a_resident_cannot_target_specific_units(): void
    {
        $this->actingAs($this->residentA)
            ->postJson('/api/v1/messenger', [
                'body' => 'فقط برای واحد ۲',
                'audience' => 'units',
                'unit_ids' => [$this->unitB->id],
            ])
            ->assertStatus(201);

        $message = Message::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame(MessageAudience::Management, $message->audience);
        $this->assertSame(0, $message->recipientUnits()->count());
    }

    /* ── خصوصی‌بودنِ رشته‌ی واحد ─────────────────────────────────────────── */

    /**
     * مهم‌ترین تستِ این مرحله.
     *
     * پیامِ ساکنِ واحد ۱ به مدیریت نباید به چشمِ ساکنِ واحد ۲ برسد. پیش از
     * R23 دقیقاً همین اتفاق می‌افتاد، چون کانال یکی بود.
     */
    public function test_a_units_message_to_management_is_invisible_to_other_units(): void
    {
        $this->actingAs($this->residentA)
            ->postJson('/api/v1/messenger', ['body' => 'شکایت خصوصی از همسایه'])
            ->assertStatus(201);

        $this->assertNotContains('شکایت خصوصی از همسایه', $this->bodiesSeenBy($this->residentB));
    }

    public function test_the_manager_sees_every_units_thread(): void
    {
        $this->actingAs($this->residentA)
            ->postJson('/api/v1/messenger', ['body' => 'پیام واحد یک'])->assertStatus(201);
        $this->actingAs($this->residentB)
            ->postJson('/api/v1/messenger', ['body' => 'پیام واحد دو'])->assertStatus(201);

        $seen = $this->bodiesSeenBy($this->manager);

        $this->assertContains('پیام واحد یک', $seen);
        $this->assertContains('پیام واحد دو', $seen);
    }

    /**
     * مالک و مستاجرِ یک واحد یک گفت‌وگوی مشترک دارند.
     *
     * عمدی است: بقیه‌ی سامانه هم واحد-محور است، و دو رشته‌ی جدا یعنی مدیر
     * باید حدس بزند کدام را جواب بدهد.
     */
    public function test_co_residents_of_a_unit_share_the_thread(): void
    {
        $tenant = $this->makeUser(UserRole::Tenant, $this->unitA);

        $this->actingAs($this->residentA)
            ->postJson('/api/v1/messenger', ['body' => 'پیام مالک'])->assertStatus(201);

        $this->assertContains('پیام مالک', $this->bodiesSeenBy($tenant));
    }

    /**
     * ساکنی که هنوز واحدی نگرفته هم باید بتواند با مدیریت حرف بزند.
     *
     * اول جلویش گرفته شده بود؛ تستِ موجودِ R21 نشان داد کسی که با پذیرشِ
     * دعوت عضو شده و هنوز واحد نگرفته اصلاً نمی‌تواند پیام بدهد — یعنی
     * دقیقاً همان کسی که بیشتر از همه به تماس با مدیر نیاز دارد.
     */
    public function test_a_resident_without_a_unit_can_still_reach_management(): void
    {
        $unassigned = $this->makeUser(UserRole::Owner);

        $this->actingAs($unassigned)
            ->postJson('/api/v1/messenger', ['body' => 'هنوز واحدی به من ندادید'])
            ->assertStatus(201);

        $this->assertContains('هنوز واحدی به من ندادید', $this->bodiesSeenBy($this->manager));
    }

    public function test_everyone_sees_their_own_message(): void
    {
        // بدونِ این، پیامِ ساکنِ بدونِ واحد بلافاصله از دیدِ خودش ناپدید می‌شد
        $unassigned = $this->makeUser(UserRole::Owner);

        $this->actingAs($unassigned)
            ->postJson('/api/v1/messenger', ['body' => 'پیام خودم'])->assertStatus(201);

        $this->assertContains('پیام خودم', $this->bodiesSeenBy($unassigned));
    }

    public function test_a_unit_less_residents_message_stays_private(): void
    {
        // رشته‌ی واحد ندارد، پس هیچ ساکنِ دیگری نباید ببیندش
        $unassigned = $this->makeUser(UserRole::Owner);

        $this->actingAs($unassigned)
            ->postJson('/api/v1/messenger', ['body' => 'خصوصی بدون واحد'])->assertStatus(201);

        $this->assertNotContains('خصوصی بدون واحد', $this->bodiesSeenBy($this->residentA));
    }

    /* ── مدیر: همه / یک واحد / چند واحد ─────────────────────────────────── */

    public function test_the_manager_can_broadcast_to_everyone(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/messenger', ['body' => 'قطع آب فردا', 'audience' => 'all'])
            ->assertStatus(201);

        foreach ([$this->residentA, $this->residentB] as $resident) {
            $this->assertContains('قطع آب فردا', $this->bodiesSeenBy($resident));
        }
    }

    public function test_the_manager_can_message_a_single_unit(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'فقط برای واحد یک',
            'audience' => 'units',
            'unit_ids' => [$this->unitA->id],
        ])->assertStatus(201);

        $this->assertContains('فقط برای واحد یک', $this->bodiesSeenBy($this->residentA));
        $this->assertNotContains('فقط برای واحد یک', $this->bodiesSeenBy($this->residentB));
    }

    public function test_the_manager_can_message_several_units_at_once(): void
    {
        $unitC = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '3']);
        $residentC = $this->makeUser(UserRole::Owner, $unitC);

        $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'برای واحدهای یک و دو',
            'audience' => 'units',
            'unit_ids' => [$this->unitA->id, $this->unitB->id],
        ])->assertStatus(201);

        foreach ([$this->residentA, $this->residentB] as $included) {
            $this->assertContains('برای واحدهای یک و دو', $this->bodiesSeenBy($included));
        }

        $this->assertNotContains('برای واحدهای یک و دو', $this->bodiesSeenBy($residentC));
    }

    public function test_one_message_is_stored_even_for_several_recipients(): void
    {
        /*
         * یک پیام و چند گیرنده — نه چند نسخه. با چند نسخه، مخفی‌کردن یا
         * ویرایشش باید چند جا انجام می‌شد و یکی جا می‌ماند.
         */
        $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'یک پیام',
            'audience' => 'units',
            'unit_ids' => [$this->unitA->id, $this->unitB->id],
        ])->assertStatus(201);

        $this->assertSame(1, Message::withoutGlobalScopes()->count());
        $this->assertSame(2, Message::withoutGlobalScopes()->first()->recipientUnits()->count());
    }

    /**
     * انتخابِ «واحدهای مشخص» بدونِ انتخاب نباید بی‌صدا به «همه» تبدیل شود…
     * ولی هم نباید پیامِ بی‌گیرنده بسازد که هیچ‌کس نمی‌بیند.
     */
    public function test_targeting_no_units_falls_back_to_everyone(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'بدون انتخاب',
            'audience' => 'units',
            'unit_ids' => [],
        ])->assertStatus(201);

        $this->assertSame(
            MessageAudience::All,
            Message::withoutGlobalScopes()->latest('id')->first()->audience,
        );
    }

    /* ── جداسازیِ مجتمع ─────────────────────────────────────────────────── */

    public function test_a_unit_of_another_complex_cannot_be_targeted(): void
    {
        $otherUnit = Unit::factory()->create(['complex_id' => Complex::factory()->create()->id]);

        $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'به مجتمع دیگر',
            'audience' => 'units',
            'unit_ids' => [$otherUnit->id],
        ])->assertStatus(422)->assertJsonValidationErrors('unit_ids.0');
    }

    /* ── فهرستِ واحدها برای انتخابگر ────────────────────────────────────── */

    public function test_the_manager_receives_the_unit_list_for_the_picker(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/v1/messenger')
            ->assertOk()
            ->assertJsonCount(2, 'units');
    }

    public function test_a_resident_does_not_receive_the_unit_list(): void
    {
        // ساکن نه لازمش دارد و نه باید فهرستِ واحدهای مجتمع را ببیند
        $this->actingAs($this->residentA)
            ->getJson('/api/v1/messenger')
            ->assertOk()
            ->assertJsonCount(0, 'units');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /**
     * متنِ پیام‌هایی که این کاربر می‌بیند.
     *
     * ⚠️ روی **JSONِ رمزگشایی‌شده** کار می‌کند نه رشته‌ی خام. پاسخ فارسی را
     * به‌صورت `\uXXXX` فرار می‌دهد، پس `assertStringContainsString` روی متنِ
     * خام همیشه شکست می‌خورد — و بدتر، ادعای **منفی** همیشه بی‌دلیل موفق
     * می‌شد. یعنی تستِ «همسایه نباید ببیند» سبزِ توخالی بود.
     *
     * @return array<int, string>
     */
    private function bodiesSeenBy(User $user): array
    {
        return $this->actingAs($user)
            ->getJson('/api/v1/messenger')
            ->json('messages.*.body') ?? [];
    }

    private function makeUser(UserRole $role, ?Unit $unit = null): User
    {
        /*
         * `can_message` صریح داده می‌شود.
         *
         * پیش‌فرضِ `true` در دیتابیس است، ولی `actingAs` همان نمونه‌ی
         * در-حافظه‌ی factory را می‌نشاند و آن نمونه اصلاً این ویژگی را ندارد
         * — یعنی `null` می‌خواند و کنترلر ۴۰۳ می‌دهد.
         */
        $user = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
            'can_message' => true,
        ]);

        if ($unit) {
            $unit->residents()->attach($user->id, [
                'relation' => $role === UserRole::Tenant ? 'tenant' : 'owner',
                'complex_id' => $this->complex->id,
            ]);
        }

        return $user;
    }
}
