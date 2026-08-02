<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Plan;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexUpgrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * حالتِ اولیه‌ی حساب و پیوستن به مجتمع (R21).
 *
 * پیش از این مرحله، هر کسی که خودش ثبت‌نام می‌کرد **برای همیشه گیر می‌کرد**:
 * خودش نمی‌توانست وارد شود (حساب غیرفعال ساخته می‌شد) و مدیرِ مجتمع هم
 * نمی‌توانست اضافه‌اش کند (شماره یکتا بود و ۴۲۲ می‌گرفت). هر دو سنجیده و
 * اثبات شدند.
 */
class InitialAccountTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $fresh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع آفتاب']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        // کاربری که خودش ثبت‌نام کرده: فعال، ولی بدونِ مجتمع
        $this->fresh = User::factory()->create([
            'complex_id' => null,
            'role' => UserRole::Owner,
            'phone' => '09121110000',
            'password' => Hash::make('newuser123'),
            'is_active' => true,
        ]);
    }

    /* ── حالت ────────────────────────────────────────────────────────────── */

    public function test_a_user_without_a_complex_is_in_the_initial_state(): void
    {
        $this->assertSame(AccountState::Initial, AccountState::of($this->fresh));
        $this->assertFalse(AccountState::of($this->fresh)->canWrite());
    }

    public function test_a_member_is_not(): void
    {
        $this->assertSame(AccountState::Member, AccountState::of($this->manager));
        $this->assertTrue(AccountState::of($this->manager)->canWrite());
    }

    public function test_the_state_is_reported_to_the_client(): void
    {
        $this->actingAs($this->fresh)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.accountState', 'initial')
            ->assertJsonPath('user.canWrite', false);
    }

    /* ── جداسازی: مهم‌ترین بخش ──────────────────────────────────────────── */

    /**
     * کاربرِ بدونِ مجتمع نباید داده‌ی هیچ مجتمعی را ببیند.
     *
     * این تله‌ی اصلیِ R21 بود: `complex_id === null` در دامنه‌ی مستأجر یعنی
     * «فیلتر نگذار» — همان مقداری که برای ادمینِ کل معنیِ «همه را ببین» دارد.
     * تا وقتی این کاربر نمی‌توانست وارد شود بی‌خطر بود؛ همین مرحله آن در را
     * باز می‌کند. سنجیده شد: پیش از اصلاح، قبض و اطلاعیه‌ی مجتمع‌های دیگر
     * دیده می‌شد.
     */
    public function test_an_initial_account_sees_no_other_complexes_data(): void
    {
        $unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        Bill::create([
            'complex_id' => $this->complex->id, 'unit_id' => $unit->id, 'period' => '1405-01',
            'total_amount' => 999000, 'status' => 'unpaid', 'due_date' => now()->addDays(5),
        ]);
        Announcement::create([
            'complex_id' => $this->complex->id, 'title' => 'اطلاعیه‌ی محرمانه',
            'body' => 'متن', 'audience' => 'all', 'published_at' => now(),
        ]);

        foreach (['/api/v1/dashboard', '/api/v1/my-bills', '/api/v1/announcements'] as $uri) {
            $body = $this->actingAs($this->fresh)->getJson($uri)->getContent();

            $this->assertStringNotContainsString('محرمانه', $body, $uri);
            $this->assertStringNotContainsString('999000', $body, $uri);
        }
    }

    /* ── قفلِ نوشتن ─────────────────────────────────────────────────────── */

    public function test_an_initial_account_cannot_write(): void
    {
        $this->actingAs($this->fresh)
            ->postJson('/api/v1/announcements', [
                'title' => 'نباید ساخته شود', 'body' => 'متن', 'audience' => 'all',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account.initial_read_only');

        $this->assertSame(0, Announcement::withoutGlobalScopes()->count());
    }

    public function test_the_lock_is_closed_by_default_not_by_a_list(): void
    {
        /*
         * قفل روی **همه‌ی** نوشتن‌هاست مگر مسیرهای صریحاً مستثنا. یعنی مسیرِ
         * نوشتنیِ تازه‌ای که فردا اضافه شود خودبه‌خود بسته است — برعکسِ
         * فهرستِ سیاه که همیشه یک مورد جا می‌افتد.
         */
        foreach ([
            ['POST', '/api/v1/messenger'],
            ['POST', '/api/v1/notifications/read-all'],
            ['POST', '/api/v1/bills/generate'],
        ] as [$method, $uri]) {
            $this->actingAs($this->fresh)
                ->json($method, $uri)
                ->assertStatus(403);
        }
    }

    public function test_reading_is_still_allowed(): void
    {
        // قفل نباید حساب را بی‌مصرف کند؛ باید بتواند ببیند و راهنما بخواند
        $this->actingAs($this->fresh)->getJson('/api/v1/dashboard')->assertOk();
        $this->actingAs($this->fresh)->getJson('/api/v1/me')->assertOk();
    }

    /* ── دعوت ───────────────────────────────────────────────────────────── */

    public function test_adding_an_already_registered_phone_sends_an_invitation(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/residents', [
                'name' => 'ساکن تازه', 'phone' => '09121110000',
                'role' => 'owner', 'password' => 'goodpass99',
            ])
            ->assertStatus(202)
            ->assertJsonPath('invited', true);

        $this->assertDatabaseHas('complex_invitations', [
            'user_id' => $this->fresh->id,
            'complex_id' => $this->complex->id,
            'status' => ComplexInvitation::PENDING,
        ]);

        // و هنوز وصل نشده — رضایت لازم است
        $this->assertNull($this->fresh->fresh()->complex_id);
    }

    public function test_the_invited_user_sees_the_invitation(): void
    {
        $this->invite();

        $this->actingAs($this->fresh)
            ->getJson('/api/v1/invitations')
            ->assertOk()
            ->assertJsonPath('data.0.complexName', 'مجتمع آفتاب');
    }

    public function test_accepting_joins_the_complex(): void
    {
        $invitation = $this->invite();

        $this->actingAs($this->fresh)
            ->postJson("/api/v1/invitations/{$invitation->id}/accept")
            ->assertOk();

        $joined = $this->fresh->fresh();
        $this->assertSame($this->complex->id, $joined->complex_id);
        $this->assertSame(AccountState::Member, AccountState::of($joined));
    }

    public function test_after_joining_the_account_can_write(): void
    {
        // اثباتِ رفتاری: قفل واقعاً برداشته می‌شود، نه فقط یک پرچم عوض می‌شود
        $invitation = $this->invite();
        $this->actingAs($this->fresh)->postJson("/api/v1/invitations/{$invitation->id}/accept")->assertOk();

        $this->actingAs($this->fresh->fresh())
            ->postJson('/api/v1/messenger', ['body' => 'سلام'])
            ->assertSuccessful();
    }

    /**
     * فقط گیرنده می‌تواند پاسخ بدهد.
     *
     * بدونِ این، هرکسی با حدسِ شناسه می‌توانست دعوتِ دیگری را بپذیرد و آن
     * حساب را وارد مجتمعی کند که صاحبش نخواسته.
     */
    public function test_someone_else_cannot_accept_your_invitation(): void
    {
        $invitation = $this->invite();

        $other = User::factory()->create(['complex_id' => null, 'is_active' => true]);

        $this->actingAs($other)
            ->postJson("/api/v1/invitations/{$invitation->id}/accept")
            ->assertNotFound();

        $this->assertNull($other->fresh()->complex_id);
    }

    public function test_declining_leaves_the_user_where_they_were(): void
    {
        $invitation = $this->invite();

        $this->actingAs($this->fresh)
            ->postJson("/api/v1/invitations/{$invitation->id}/decline")
            ->assertOk();

        $this->assertNull($this->fresh->fresh()->complex_id);
    }

    public function test_an_invitation_cannot_be_accepted_twice(): void
    {
        $invitation = $this->invite();

        $this->actingAs($this->fresh)->postJson("/api/v1/invitations/{$invitation->id}/accept")->assertOk();

        // دومی باید رد شود، نه اینکه دوباره وصل کند
        $this->actingAs($this->fresh->fresh())
            ->postJson("/api/v1/invitations/{$invitation->id}/accept")
            ->assertStatus(422);
    }

    /**
     * ساکنِ مجتمعِ دیگر همچنان دعوت نمی‌شود.
     *
     * وگرنه این مسیر تبدیل می‌شد به راهی برای بیرون‌کشیدنِ ساکنِ رقیب.
     */
    public function test_a_member_of_another_complex_still_gets_a_validation_error(): void
    {
        $otherComplex = Complex::factory()->create();
        User::factory()->create([
            'complex_id' => $otherComplex->id,
            'phone' => '09125554444',
            'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->postJson('/api/v1/residents', [
                'name' => 'ساکن رقیب', 'phone' => '09125554444',
                'role' => 'owner', 'password' => 'goodpass99',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_brand_new_phone_is_created_directly_as_before(): void
    {
        // مسیرِ عادی نباید عوض شده باشد
        $this->actingAs($this->manager)
            ->postJson('/api/v1/residents', [
                'name' => 'کاملاً تازه', 'phone' => '09127778888',
                'role' => 'owner', 'password' => 'goodpass99',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'phone' => '09127778888',
            'complex_id' => $this->complex->id,
        ]);
    }

    /* ── ارتقا با خرید ──────────────────────────────────────────────────── */

    /**
     * راهِ دومِ بیرون آمدن از حالتِ اولیه: خرید.
     *
     * کسی که مدیری ندارد تا دعوتش کند، باید بتواند خودش مجتمع بسازد — وگرنه
     * همان بن‌بستِ قبلی با ظاهرِ تازه است.
     */
    public function test_buying_creates_a_complex_and_promotes_the_buyer(): void
    {
        Storage::fake('local');
        $plan = $this->makePlan();

        $this->actingAs($this->fresh)
            ->postJson('/api/v1/subscription/receipt', [
                'plan' => $plan->slug,
                'complex_name' => 'مجتمع تازه‌ساز',
                'receipt' => UploadedFile::fake()->image('r.jpg', 30, 30),
            ])
            ->assertStatus(201);

        $upgraded = $this->fresh->fresh();

        $this->assertNotNull($upgraded->complex_id);
        $this->assertSame(UserRole::ComplexAdmin, $upgraded->role);
        $this->assertSame('مجتمع تازه‌ساز', $upgraded->complex->name);
        $this->assertSame(AccountState::Member, AccountState::of($upgraded));
    }

    public function test_the_upgrade_removes_the_write_lock(): void
    {
        Storage::fake('local');
        $plan = $this->makePlan();

        $this->actingAs($this->fresh)->postJson('/api/v1/subscription/receipt', [
            'plan' => $plan->slug,
            'complex_name' => 'مجتمع تازه‌ساز',
            'receipt' => UploadedFile::fake()->image('r.jpg', 30, 30),
        ])->assertStatus(201);

        $this->actingAs($this->fresh->fresh())
            ->postJson('/api/v1/announcements', [
                'title' => 'اولین اطلاعیه', 'body' => 'متن', 'audience' => 'all',
            ])
            ->assertSuccessful();
    }

    public function test_buying_without_a_complex_name_is_refused(): void
    {
        Storage::fake('local');
        $plan = $this->makePlan();

        $this->actingAs($this->fresh)
            ->postJson('/api/v1/subscription/receipt', [
                'plan' => $plan->slug,
                'receipt' => UploadedFile::fake()->image('r.jpg', 30, 30),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('complex_name');

        // و هیچ مجتمعِ نیمه‌کاره‌ای ساخته نشده
        $this->assertNull($this->fresh->fresh()->complex_id);
    }

    /**
     * ارتقا اتمیک است.
     *
     * اگر مجتمع ساخته شود ولی نقش عوض نشود، کاربر صاحبِ مجتمعی است که به آن
     * دسترسی ندارد و هیچ‌کس مدیرش نیست — مجتمعی یتیم که فقط ادمینِ کل
     * می‌تواند نجاتش بدهد.
     */
    public function test_a_second_upgrade_is_refused(): void
    {
        $upgrader = app(ComplexUpgrader::class);

        $upgrader->upgrade($this->fresh, 'اولی');

        $this->expectExceptionMessage('از قبل به یک مجتمع وصل است');
        $upgrader->upgrade($this->fresh->fresh(), 'دومی');
    }

    public function test_upgrading_cancels_any_pending_invitation(): void
    {
        /*
         * وگرنه کاربر پس از ساختِ مجتمعِ خودش می‌توانست دعوتِ قدیمی را بپذیرد
         * و بی‌سروصدا از مدیریتِ مجتمعِ خودش به ساکنِ جای دیگری تبدیل شود.
         */
        $invitation = $this->invite();

        app(ComplexUpgrader::class)->upgrade($this->fresh, 'مجتمع خودم');

        $this->assertSame(ComplexInvitation::DECLINED, $invitation->fresh()->status);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** slug یکتا، چون پکیج‌های پیش‌فرض از قبل seed شده‌اند. */
    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'پایه‌ی آزمایشی', 'slug' => 'r21-test-plan', 'price' => 500000,
            'months' => 12, 'unit_limit' => 50, 'is_active' => true,
        ]);
    }

    private function invite(): ComplexInvitation
    {
        return ComplexInvitation::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->fresh->id,
            'role' => UserRole::Owner,
            'invited_by' => $this->manager->id,
            'status' => ComplexInvitation::PENDING,
        ]);
    }
}
