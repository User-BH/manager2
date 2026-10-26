<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\LoginEvent;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TrustedDevice;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\Membership;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\TestPlans;
use Tests\TestCase;

/**
 * Data Integrity ِ چرخه‌ی حساب (مرحلهٔ ۹ · فاز ۹؛ بندهای ۸، ۹، ۱۰، ۲۹، ۳۳ و ۳۴ ِ فایلِ ۲).
 *
 * «تستی واقعی‌تر با داده‌های موجود، تا مشخص شود Transition فقط روی User ِ خالی کار
 * نمی‌کند»: ساکنِ قبلیِ مجتمعِ الف — با پروفایلِ کامل، کیف، پرداخت، پیام، اعلان، ردِ
 * حسابرسی، سابقه‌ی ورود و دستگاهِ مورداعتماد — که مدیر او را برداشته و حسابِ اولیه شده،
 * از هر سه مسیرِ واقعی می‌گذرد:
 *
 *   ① دعوتِ مدیرِ مجتمعِ ب ← پذیرش ← داشبوردِ واحد
 *   ② درخواستِ پیوستن به مدیرِ ب ← تأییدِ مدیر ← داشبوردِ واحد
 *   ③ نوعِ مجتمع ← پلن ← پرداختِ آنلاین ← تأییدِ درگاه در سرور ← داشبوردِ مدیر
 *
 * و پس از هر کدام: **همان** شناسه، هیچ کاربرِ تازه، پروفایل دست‌نخورده، مانده‌ی کیف
 * همان، و **هر** ردیفی در **هر** جدول که پیش از گذار به این حساب اشاره می‌کرد هنوز
 * هست — و در جدول‌های مالی و تاریخی بایت‌به‌بایت همان. فهرستِ جدول‌ها از خودِ schema
 * خوانده می‌شود، نه از فهرستی دستی که با جدولِ تازه کهنه شود.
 */
class TransitionDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** ستون‌هایی که به حساب اشاره می‌کنند (نامِ ستون؛ `notifiable_id` با نوعش). */
    private const USER_COLUMNS = '/^(user_id|created_by|reviewed_by|invited_by|author_id|notifiable_id)$/';

    /** محتوای این جدول‌ها پس از گذار باید عیناً همان بماند (مالی و تاریخی). */
    private const IMMUTABLE = [
        'wallet_transactions', 'payments', 'audit_logs', 'login_events', 'messages',
        'notifications', 'unit_user', 'trusted_devices',
    ];

    /** تنها ستون‌های حساب که گذار حق دارد عوض کند. */
    private const TRANSITION_COLUMNS = ['role', 'complex_id', 'updated_at'];

    private Complex $complexA;

    private User $managerA;

    private Unit $unitA;

    private Complex $complexB;

    private User $managerB;

    private Unit $unitB;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complexA = Complex::factory()->create(['name' => 'مجتمع قبلی', 'messenger_enabled' => true]);
        $this->managerA = User::factory()->create([
            'complex_id' => $this->complexA->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09121230001',
        ]);
        $this->unitA = Unit::factory()->create(['complex_id' => $this->complexA->id, 'unit_number' => '7']);

        $this->complexB = Complex::factory()->create(['name' => 'مجتمع تازه']);
        $this->managerB = User::factory()->create([
            'complex_id' => $this->complexB->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09121230002',
        ]);
        $this->unitB = Unit::factory()->create(['complex_id' => $this->complexB->id, 'unit_number' => '3']);

        $this->person = $this->formerResidentWithHistory();
    }

    /* ── سه مسیر ─────────────────────────────────────────────────────────── */

    public function test_the_invitation_path_keeps_every_record_of_an_account_with_history(): void
    {
        $before = $this->snapshot();

        [$invitation] = app(Membership::class)->invite($this->complexB, $this->person->phone, UserRole::Tenant, $this->unitB, $this->managerB);
        app(Membership::class)->accept($invitation, $this->person->fresh());

        $this->assertTransitioned($before, UserRole::Tenant, $this->complexB);
        $this->assertSame([$this->unitB->id], $this->person->fresh()->currentUnits()->pluck('units.id')->all());
    }

    public function test_the_join_request_path_keeps_every_record_of_an_account_with_history(): void
    {
        $before = $this->snapshot();

        $this->as($this->person->fresh())->postJson('/api/v1/join-requests', ['phone' => $this->managerB->phone])->assertStatus(202);
        $request = ComplexInvitation::where('user_id', $this->person->id)->where('direction', ComplexInvitation::REQUEST)->sole();
        $this->as($this->managerB)->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $this->unitB->id])
            ->assertOk();

        $this->assertTransitioned($before, UserRole::Owner, $this->complexB);
        $this->assertSame([$this->unitB->id], $this->person->fresh()->currentUnits()->pluck('units.id')->all());
    }

    public function test_the_payment_path_keeps_every_record_of_an_account_with_history(): void
    {
        config([
            'subscription.gateway' => 'zarinpal',
            'subscription.config.merchant_id' => 'test-merchant-id',
            'subscription.config.sandbox' => false,
        ]);
        TestPlans::create([
            'name' => 'پلنِ آزمون', 'slug' => 'res-test', 'complex_type' => ComplexType::Residential,
            'price' => 250000, 'months' => 3, 'is_active' => true,
        ]);
        $authority = 'A00000000000000000000000000000054321';
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => $authority], 'errors' => []]),
            'https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 123456789]]),
        ]);
        $before = $this->snapshot();

        $this->as($this->person->fresh())->post('/subscription/checkout', [
            'plan' => 'res-test', 'complex_name' => 'مجتمع خودم', 'complex_type' => 'residential',
        ]);
        $intent = CheckoutIntent::sole();
        $this->get("/checkout/callback/{$intent->public_id}?Authority={$authority}&Status=OK")
            // از ۱۰/۵ مقصد مجتمعِ تازه را هم دارد تا کلاینت به آن برود
            ->assertRedirect('/dashboard?checkout=success&tracking=123456789&complex='.(int) $intent->fresh()->complex_id);

        $owned = Complex::where('name', 'مجتمع خودم')->sole();
        $this->assertTransitioned($before, UserRole::ComplexAdmin, $owned);
        $this->assertSame('active', Subscription::where('complex_id', $owned->id)->sole()->status);
    }

    /* ── بندِ ۲۶: شکست در میانه‌ی گذار، هیچ وضعیتِ نیمه ─────────────────────── */

    /**
     * «Payment = successful، Manager state = failed، Subscription = missing» نباید پیش آید:
     * نوشتنِ اشتراک در میانه‌ی گذار عمداً شکست می‌خورد. پس از آن هیچ مجتمعی نیست، حساب
     * همان حسابِ اولیه است، سفارش `pending` می‌ماند — و بازگشتِ بعدیِ بانک گذار را یک
     * بار، کامل، انجام می‌دهد.
     */
    public function test_a_failure_halfway_to_manager_leaves_no_half_state_and_the_retry_completes_once(): void
    {
        config([
            'subscription.gateway' => 'zarinpal',
            'subscription.config.merchant_id' => 'test-merchant-id',
            'subscription.config.sandbox' => false,
        ]);
        TestPlans::create([
            'name' => 'پلنِ آزمون', 'slug' => 'res-test', 'complex_type' => ComplexType::Residential,
            'price' => 250000, 'months' => 3, 'is_active' => true,
        ]);
        $authority = 'A00000000000000000000000000000054321';
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => $authority], 'errors' => []]),
            'https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 123456789]]),
        ]);
        $before = $this->snapshot();

        $this->as($this->person->fresh())->post('/subscription/checkout', [
            'plan' => 'res-test', 'complex_name' => 'مجتمع خودم', 'complex_type' => 'residential',
        ]);
        $intent = CheckoutIntent::sole();

        $broken = true;
        Subscription::creating(function () use (&$broken): void {
            if ($broken) {
                throw new \RuntimeException('شکستِ عمدی در میانه‌ی گذار');
            }
        });

        $this->get("/checkout/callback/{$intent->public_id}?Authority={$authority}&Status=OK")->assertServerError();

        $person = User::withoutGlobalScopes()->findOrFail($this->person->id);
        $this->assertSame(AccountState::Initial, AccountState::of($person), 'حساب در وضعیتِ نیمه ماند.');
        $this->assertSame($before['user_row']['role'], $person->role->value);
        $this->assertNull($person->complex_id);
        $this->assertSame(0, Complex::where('name', 'مجتمع خودم')->count(), 'مجتمعِ بی‌اشتراک ماند.');
        $this->assertSame(0, Subscription::count());
        $this->assertSame(CheckoutIntent::PENDING, $intent->fresh()->status, 'سفارشِ پرداخت‌شده گم شد.');

        $broken = false;
        $this->get("/checkout/callback/{$intent->public_id}?Authority={$authority}&Status=OK")
            // از ۱۰/۵ مقصد مجتمعِ تازه را هم دارد تا کلاینت به آن برود
            ->assertRedirect('/dashboard?checkout=success&tracking=123456789&complex='.(int) $intent->fresh()->complex_id);

        $owned = Complex::where('name', 'مجتمع خودم')->sole();
        $this->assertTransitioned($before, UserRole::ComplexAdmin, $owned);
        $this->assertSame(1, Subscription::count());
    }

    /** همان برای واحد: باز شدنِ دوره‌ی واحد شکست می‌خورد؛ نه نقش عوض می‌شود، نه دعوت مصرف. */
    public function test_a_failure_halfway_to_a_unit_leaves_no_half_state_and_the_invitation_stays_usable(): void
    {
        $before = $this->snapshot();
        [$invitation] = app(Membership::class)->invite($this->complexB, $this->person->phone, UserRole::Tenant, $this->unitB, $this->managerB);

        $broken = true;
        UnitTenure::creating(function () use (&$broken): void {
            if ($broken) {
                throw new \RuntimeException('شکستِ عمدی در میانه‌ی گذار');
            }
        });

        try {
            app(Membership::class)->accept($invitation, $this->person->fresh());
            $this->fail('شکستِ عمدی رخ نداد.');
        } catch (\RuntimeException $e) {
            $this->assertSame('شکستِ عمدی در میانه‌ی گذار', $e->getMessage());
        }

        $person = User::withoutGlobalScopes()->findOrFail($this->person->id);
        $this->assertSame(AccountState::Initial, AccountState::of($person), 'حساب بی واحد عضو شد.');
        $this->assertSame([$before['user_row']['role'], null], [$person->role->value, $person->complex_id]);
        $this->assertTrue($invitation->fresh()->isLive(), 'دعوت در گذارِ ناموفق مصرف شد.');

        $broken = false;
        app(Membership::class)->accept($invitation, $person);

        $this->assertTransitioned($before, UserRole::Tenant, $this->complexB);
        $this->assertSame([$this->unitB->id], $this->person->fresh()->currentUnits()->pluck('units.id')->all());
    }

    /* ── سنجش ────────────────────────────────────────────────────────────── */

    private function assertTransitioned(array $before, UserRole $role, Complex $complex): void
    {
        $person = User::withoutGlobalScopes()->findOrFail($this->person->id);

        // همان حساب، در وضعیتِ تازه؛ هیچ کاربرِ دومی ساخته نشد
        $this->assertSame([$role, $complex->id, AccountState::Member], [$person->role, $person->complex_id, AccountState::of($person)]);
        $this->assertSame($before['users_count'], User::withoutGlobalScopes()->count(), 'گذار حسابِ تازه ساخت.');
        $this->assertSame(1, User::withoutGlobalScopes()->where('phone', $person->phone)->count());

        // پروفایل: فقط نقش و مجتمع عوض می‌شوند
        $row = (array) DB::table('users')->find($person->id);
        foreach ($before['user_row'] as $column => $value) {
            if (! in_array($column, self::TRANSITION_COLUMNS, true)) {
                $this->assertSame($value, $row[$column], "ستونِ «{$column}» ِ حساب در گذار عوض شد.");
            }
        }

        // کیف: همان مانده، از همان دفتر
        $this->assertSame($before['balance'], app(WalletService::class)->balance($person));
        // (JSON عددِ صحیح برمی‌گرداند؛ مقایسه‌ی عددی)
        $this->assertSame($before['balance'], (float) $this->as($person)->getJson('/api/v1/wallet')->assertOk()->json('wallets.0.balance'));
        $statement = $this->as($person)->getJson('/api/v1/wallet/statement')->assertOk();
        $this->assertSame($before['balance'], (float) $statement->json('balance'));
        $this->assertCount(count($before['rows']['wallet_transactions']), $statement->json('transactions'), 'صورت‌حسابِ خودِ حساب پس از گذار ردیف کم دارد.');

        // هر ردیفی که به این حساب اشاره می‌کرد هنوز هست؛ مالی و تاریخی عیناً همان
        foreach ($before['rows'] as $table => $rows) {
            foreach ($rows as $key => $data) {
                $now = $this->find($table, $key);
                $this->assertNotNull($now, "ردیفِ {$table}#{$key} ِ حساب در گذار حذف شد.");
                if (in_array($table, self::IMMUTABLE, true)) {
                    $this->assertSame($data, $now, "ردیفِ {$table}#{$key} در گذار عوض شد.");
                }
            }
        }
        foreach (self::IMMUTABLE as $table) {
            $this->assertNotEmpty($before['rows'][$table] ?? [], "دادهٔ پیش از گذار در «{$table}» ساخته نشده بود — سنجشِ پوچ.");
        }

        // هیچ رابطه‌ی یتیم
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'), 'گذار رابطه‌ی یتیم ساخت.');
        $this->assertNotNull(Complex::withoutGlobalScopes()->find($person->complex_id));
        $this->assertSame(0, DB::table('unit_user')->where('user_id', $person->id)
            ->whereNotIn('unit_id', DB::table('units')->select('id'))->count());
    }

    /**
     * عکسِ هر ردیفی که به حساب اشاره می‌کند، در همه‌ی جدول‌های schema.
     *
     * @return array{users_count: int, user_row: array<string, mixed>, balance: float, rows: array<string, array<string, array<string, mixed>>>}
     */
    private function snapshot(): array
    {
        $id = $this->person->id;
        $rows = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, ['users', 'sessions', 'migrations'], true)) {
                continue;
            }
            $columns = array_column(Schema::getColumns($name), 'name');
            foreach (preg_grep(self::USER_COLUMNS, $columns) as $column) {
                $query = DB::table($name)->where($column, $id);
                if ($column === 'notifiable_id') {
                    $query->where('notifiable_type', $this->person->getMorphClass());
                }
                foreach ($query->get() as $row) {
                    $data = (array) $row;
                    $rows[$name][$this->key($name, $data)] = $data;
                }
            }
        }

        return [
            'users_count' => User::withoutGlobalScopes()->count(),
            'user_row' => (array) DB::table('users')->find($id),
            'balance' => app(WalletService::class)->balance($this->person),
            'rows' => $rows,
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function key(string $table, array $row): string
    {
        return array_key_exists('id', $row) ? (string) $row['id'] : md5(json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function find(string $table, string $key): ?array
    {
        $columns = array_column(Schema::getColumns($table), 'name');
        if (in_array('id', $columns, true)) {
            $row = DB::table($table)->where('id', $key)->first();

            return $row === null ? null : (array) $row;
        }

        foreach (DB::table($table)->get() as $row) {
            if (md5(json_encode((array) $row, JSON_THROW_ON_ERROR)) === $key) {
                return (array) $row;
            }
        }

        return null;
    }

    /* ── ساختنِ حسابِ دارای سابقه ─────────────────────────────────────────── */

    /**
     * ساکنِ یک‌ساله‌ی مجتمعِ الف که مدیر او را برداشته و حالا حسابِ اولیه است —
     * با پروفایلِ کامل و سابقه در هر جدولی که گذار نباید دست بزند.
     */
    private function formerResidentWithHistory(): User
    {
        $person = User::factory()->create([
            'complex_id' => $this->complexA->id, 'role' => UserRole::Tenant, 'is_active' => true,
            'first_name' => 'سارا', 'last_name' => 'رضایی', 'name' => 'سارا رضایی', 'phone' => '09127770001',
            'national_id' => '0012345679', 'birth_date' => '1990-05-04', 'emergency_phone' => '09127770002',
            'address' => 'تهران، خیابان آزادی، پلاک ۱۲', 'bio' => 'ساکنِ قدیمی', 'can_message' => true,
            'password' => Hash::make('secret123'), 'terms_accepted_at' => now()->subYear(),
        ]);
        app(TenureService::class)->open($this->unitA, $person, ResidentRelation::Tenant, 100, now()->subYear());

        // مالی: قبض و پرداختِ موفق، شارژِ کیف و پرداخت از کیف
        $bill = Bill::factory()->create([
            'complex_id' => $this->complexA->id, 'unit_id' => $this->unitA->id,
            'period' => Jalali::shiftPeriod(Jalali::currentPeriod(), -2), 'base_amount' => 40000, 'total_amount' => 40000, 'paid_amount' => 0,
        ]);
        Payment::factory()->successful()->create([
            'complex_id' => $this->complexA->id, 'unit_id' => $this->unitA->id, 'bill_id' => null, 'user_id' => $person->id,
        ]);
        $wallet = app(WalletService::class);
        $wallet->credit($person, $this->unitA, 150000, WalletTransaction::SOURCE_ADJUSTMENT);
        $wallet->payBill($person, $bill, 40000, (string) Str::uuid());

        // پیام، اعلان، حسابرسی، ورود، دستگاه
        $thread = app(ConversationDirectory::class)->unitThread($this->complexA, $this->unitA);
        Message::withoutGlobalScopes()->create([
            'complex_id' => $this->complexA->id, 'conversation_id' => $thread->id, 'user_id' => $person->id,
            'body' => 'پیامِ دورانِ سکونت', 'client_id' => (string) Str::uuid(), 'author_name' => $person->name,
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\BillIssued',
            'notifiable_type' => $person->getMorphClass(), 'notifiable_id' => $person->id,
            'data' => json_encode(['title' => 'قبضِ تازه'], JSON_THROW_ON_ERROR), 'read_at' => null,
            'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
        ]);
        AuditLog::create([
            'complex_id' => $this->complexA->id, 'user_id' => $person->id, 'action' => 'profile.updated',
            'description' => 'ویرایشِ پروفایل', 'created_at' => now()->subMonths(2),
        ]);
        LoginEvent::create(['user_id' => $person->id, 'event' => 'login', 'ip_address' => '10.0.0.1', 'user_agent' => 'phpunit', 'device_hash' => 'device-a']);
        TrustedDevice::create([
            'user_id' => $person->id, 'token_hash' => hash('sha256', 'device-a'), 'label' => 'گوشی',
            'last_used_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);

        // مدیرِ الف او را برمی‌دارد: حسابِ اولیه، با همه‌ی سابقه
        $this->as($this->managerA)->deleteJson("/api/v1/residents/{$person->id}")->assertOk();
        $person = User::withoutGlobalScopes()->findOrFail($person->id);
        $this->assertSame(AccountState::Initial, AccountState::of($person));
        $this->assertSame(110000.0, $wallet->balance($person));

        return $person;
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
