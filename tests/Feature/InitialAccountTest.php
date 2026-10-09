<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\ComplexUpgrader;
use App\Services\Account\Membership;
use App\Services\Subscription\ComplexPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع آفتاب']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);

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
            // ⚠️ تا مرحلهٔ ۸ · فاز ۶ این‌جا notifications/read-all بود؛ حالا عمداً باز است
            // (پاسخ به بازخورد به همین کاربر هم می‌رسد) — تستِ پایین همان را می‌سنجد
            ['POST', '/api/v1/announcements'],
            ['POST', '/api/v1/bills/generate'],
        ] as [$method, $uri]) {
            $this->actingAs($this->fresh)
                ->json($method, $uri)
                ->assertStatus(403);
        }
    }

    /** خواندنِ اعلان‌های **خودش** نوشتن است ولی داده‌ی مجتمع نیست (مرحلهٔ ۸ · فاز ۶) */
    public function test_an_initial_account_can_mark_its_own_notifications_read(): void
    {
        $this->actingAs($this->fresh)->postJson('/api/v1/notifications/read-all')->assertOk();
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
                'phone' => '09121110000', 'role' => 'owner', 'unit_id' => $this->unit->id,
            ])
            ->assertCreated()
            ->assertJsonPath('invited', true);

        // دعوت به شماره بسته است و تا پذیرش به هیچ حسابی وصل نیست (مرحلهٔ ۹ · فاز ۶)
        $this->assertDatabaseHas('complex_invitations', [
            'phone' => '09121110000',
            'user_id' => null,
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
            ->assertJsonPath('data.0.complexName', 'مجتمع آفتاب')
            ->assertJsonPath('data.0.unitLabel', 'واحد '.$this->unit->unit_number);
    }

    public function test_accepting_joins_the_complex(): void
    {
        $invitation = $this->invite();

        $this->accept($this->fresh, $invitation)->assertOk();

        $joined = $this->fresh->fresh();
        $this->assertSame($this->complex->id, $joined->complex_id);
        $this->assertSame(AccountState::Member, AccountState::of($joined));
    }

    public function test_after_joining_the_account_can_write(): void
    {
        // اثباتِ رفتاری: قفل واقعاً برداشته می‌شود، نه فقط یک پرچم عوض می‌شود
        $invitation = $this->invite();
        $this->accept($this->fresh, $invitation)->assertOk();

        $this->actingAs($this->fresh->fresh())
            ->postJson('/api/v1/messenger', ['body' => 'سلام'])
            ->assertSuccessful();
    }

    /**
     * فقط صاحبِ شماره می‌تواند پاسخ بدهد.
     *
     * بدونِ این، هرکسی با حدسِ شناسه می‌توانست دعوتِ دیگری را بپذیرد و آن
     * حساب را وارد مجتمعی کند که صاحبش نخواسته.
     */
    public function test_someone_else_cannot_accept_your_invitation(): void
    {
        $invitation = $this->invite();

        $other = User::factory()->create(['complex_id' => null, 'is_active' => true]);

        $this->actingAs($other)
            ->postJson("/api/v1/invitations/{$invitation->id}/code")
            ->assertNotFound();
        $this->actingAs($other)
            ->postJson("/api/v1/invitations/{$invitation->id}/accept", ['code' => '123456'])
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

        $this->accept($this->fresh, $invitation)->assertOk();

        // دعوتِ مصرف‌شده دیگر «زنده» نیست: همان ۴۰۴ ِ نامعتبر، نه وصلِ دوباره
        $this->actingAs($this->fresh->fresh())
            ->postJson("/api/v1/invitations/{$invitation->id}/accept", ['code' => '123456'])
            ->assertNotFound();
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
                'phone' => '09125554444', 'role' => 'owner', 'unit_id' => $this->unit->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('complex_invitations', ['phone' => '09125554444']);
    }

    /**
     * 🔴 F5 (مرحلهٔ ۹ · فاز ۶): شماره‌ی بی‌حساب دیگر حساب نمی‌گیرد.
     *
     * پیش از این همین درخواست حسابی با رمزِ انتخابیِ مدیر و بی OTP می‌ساخت.
     */
    public function test_a_brand_new_phone_gets_an_invitation_not_an_account(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/residents', [
                'phone' => '09127778888', 'role' => 'owner', 'unit_id' => $this->unit->id,
            ])
            ->assertCreated()
            ->assertJsonPath('invited', true);

        $this->assertDatabaseMissing('users', ['phone' => '09127778888']);
        $this->assertDatabaseHas('complex_invitations', [
            'phone' => '09127778888',
            'user_id' => null,
            'status' => ComplexInvitation::PENDING,
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
        // مرحلهٔ ۹ · فاز ۵: خرید فقط آنلاین و پس از تأییدِ درگاه (`ComplexPurchase`)
        $purchase = app(ComplexPurchase::class);
        $intent = $purchase->begin($this->fresh, $this->makePlan(), 'مجتمع تازه‌ساز', ComplexType::Residential, null);
        $purchase->complete($intent, 'TRK-R21');

        $upgraded = $this->fresh->fresh();

        $this->assertNotNull($upgraded->complex_id);
        $this->assertSame(UserRole::ComplexAdmin, $upgraded->role);
        $this->assertSame('مجتمع تازه‌ساز', $upgraded->complex->name);
        $this->assertSame(AccountState::Member, AccountState::of($upgraded));
    }

    public function test_the_upgrade_removes_the_write_lock(): void
    {
        $purchase = app(ComplexPurchase::class);
        $purchase->complete(
            $purchase->begin($this->fresh, $this->makePlan(), 'مجتمع تازه‌ساز', ComplexType::Residential, null),
            'TRK-R21-LOCK',
        );

        $this->actingAs($this->fresh->fresh())
            ->postJson('/api/v1/announcements', [
                'title' => 'اولین اطلاعیه', 'body' => 'متن', 'audience' => 'all',
            ])
            ->assertSuccessful();
    }

    /**
     * 🔴 F1 و F2 (مرحلهٔ ۹ · فاز ۵): رسید هرگز حسابِ اولیه را مدیر نمی‌کند.
     *
     * ⚠️ این تست عمداً برعکسِ نسخه‌ی قبلی‌اش است: پیش از این «خرید با رسید
     * مجتمع می‌سازد» سنجیده می‌شد — و Audit ِ ۹/۲ نشان داد همان مسیر پیش از هر
     * تأییدِ پرداخت مدیر می‌ساخت، و با پلنِ نامعتبر حتی بی‌اشتراک. تصمیمِ قطعیِ
     * کارفرما: فقط پرداختِ آنلاینِ تأییدشده.
     */
    public function test_a_receipt_never_makes_an_initial_account_a_manager(): void
    {
        Storage::fake('local');
        $plan = $this->makePlan();

        foreach ([$plan->slug, 'no-such-plan'] as $slug) {
            $this->actingAs($this->fresh)
                ->postJson('/api/v1/subscription/receipt', [
                    'plan' => $slug,
                    'complex_name' => 'مجتمع تازه‌ساز',
                    'complex_type' => 'residential',
                    'receipt' => UploadedFile::fake()->image('r.jpg', 30, 30),
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'ساختنِ مجتمع فقط با پرداختِ آنلاین است؛ رسید برای اشتراکِ مجتمعی است که از قبل دارید.');
        }

        $fresh = $this->fresh->fresh();
        $this->assertNull($fresh->complex_id);
        $this->assertSame(AccountState::Initial, AccountState::of($fresh));
        $this->assertSame(1, Complex::count(), 'مجتمعی ساخته شد (فقط مجتمعِ setUp باید باشد).');
        $this->assertSame(0, Subscription::count());
        Storage::disk('local')->assertDirectoryEmpty('/');
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

        $upgrader->upgrade($this->fresh, 'اولی', null, ComplexType::Residential);

        $this->expectExceptionMessage('از قبل به یک مجتمع وصل است');
        $upgrader->upgrade($this->fresh->fresh(), 'دومی', null, ComplexType::Residential);
    }

    public function test_upgrading_cancels_any_pending_invitation(): void
    {
        /*
         * وگرنه کاربر پس از ساختِ مجتمعِ خودش می‌توانست دعوتِ قدیمی را بپذیرد
         * و بی‌سروصدا از مدیریتِ مجتمعِ خودش به ساکنِ جای دیگری تبدیل شود.
         */
        $invitation = $this->invite();

        app(ComplexUpgrader::class)->upgrade($this->fresh, 'مجتمع خودم', null, ComplexType::Residential);

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

    /** دعوتِ واقعی از همان سرویسِ `POST /residents` (مرحلهٔ ۹ · فاز ۶). */
    private function invite(): ComplexInvitation
    {
        [$invitation] = app(Membership::class)->invite(
            $this->complex,
            $this->fresh->phone,
            UserRole::Owner,
            $this->unit,
            $this->manager,
        );

        return $invitation;
    }

    /** پذیرش با کدِ OTP ِ تازه — همان دو گامِ رابط. */
    private function accept(User $user, ComplexInvitation $invitation): TestResponse
    {
        $code = $this->actingAs($user)
            ->postJson("/api/v1/invitations/{$invitation->id}/code")
            ->assertOk()
            ->json('devCode');

        return $this->actingAs($user)
            ->postJson("/api/v1/invitations/{$invitation->id}/accept", ['code' => $code]);
    }
}
