<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\MessageAudience;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\GeneratedDocument;
use App\Models\Message;
use App\Models\MessagePoll;
use App\Models\Payment;
use App\Models\PollOption;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * حمله به لایه‌ی مجوز: IDOR، BOLA و ارتقای سطحِ دسترسی (R53).
 *
 * ─── چرا این تست جدا از `TenantIsolationTest` است ──────────────────────────
 * آن تست یک محورِ مشخص را می‌سنجد: **مدیرِ مجتمع الف → رکوردِ مجتمع ب**. آنجا
 * `ComplexScope` مدافع است و کار هم می‌کند.
 *
 * ⚠️ ولی خطرناک‌ترین محور آن نیست. `ComplexScope` **درونِ یک مجتمع هیچ کاری
 * نمی‌کند**: ساکنِ واحد ۲ و ساکنِ واحد ۱ هر دو `complex_id` یکسان دارند، پس
 * اسکوپ هر دو را می‌پذیرد. تنها چیزی که جلوی خواندنِ قبضِ همسایه را می‌گیرد،
 * بررسیِ مالکیت در خودِ کنترلر یا Policy است — و آن بررسی‌ها تا امروز
 * **هیچ تستِ حمله‌ای** نداشتند.
 *
 * ─── چهار محورِ حمله ───────────────────────────────────────────────────────
 * ① ساکن ← رکوردِ ساکنِ دیگر، **در همان مجتمع** (IDOR/BOLA)
 * ② ساکن ← مسیرهای مخصوصِ مدیر (ارتقای سطح)
 * ③ مدیرِ مجتمع ← مسیرهای ادمینِ پلتفرم
 * ④ مهمان ← هر مسیرِ شناسه‌دار
 *
 * ─── قاعده‌ی ادعا ──────────────────────────────────────────────────────────
 * پاسخِ درست هم `403` می‌تواند باشد و هم `404`. هر دو پذیرفته‌اند، چون
 * انتخاب بینشان تصمیمِ نشتِ اطلاعات است نه تصمیمِ امنیتی: `404` حتی وجودِ
 * رکورد را هم لو نمی‌دهد. آنچه **پذیرفته نیست** پاسخِ ۲xx است — و ۵۰۰ هم
 * پذیرفته نیست، چون یعنی درخواست تا عمقِ منطق رفته و آنجا ترکیده.
 */
class AuthorizationAttackTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $superAdmin;

    private User $victim;

    private User $attacker;

    private User $applicant;

    /** @var array<string, mixed> رکوردهایی که به قربانی تعلق دارند. */
    private array $owned = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع آزمون',
            'slug' => 'attack-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'fake',
            'messenger_enabled' => true,
        ]);

        $this->superAdmin = $this->user(UserRole::SuperAdmin, '09300000001', null);
        $this->manager = $this->user(UserRole::ComplexAdmin, '09300000002');
        $this->victim = $this->user(UserRole::Owner, '09300000003');
        $this->attacker = $this->user(UserRole::Owner, '09300000004');
        /*
         * ⚠️ متقاضی عمداً **عضوِ هیچ مجتمعی نیست** (`complex_id = null`).
         *
         * درخواستِ پیوستن دقیقاً همین حالت را دارد؛ اگر متقاضی از قبل عضو
         * باشد، `approve` به‌خاطرِ همان عضویت رد می‌شود و تست به‌غلط سبز
         * می‌ماند — یعنی هرگز به بررسیِ مجوز نمی‌رسد.
         */
        $this->applicant = $this->user(UserRole::Owner, '09300000005', null);

        $victimUnit = $this->unit('101', $this->victim);
        $this->unit('102', $this->attacker);

        $this->owned = $this->recordsOf($victimUnit);
    }

    private function user(UserRole $role, string $phone, ?int $complexId = -1): User
    {
        return User::create([
            'complex_id' => $complexId === -1 ? $this->complex->id : $complexId,
            'name' => 'کاربر '.$phone,
            'phone' => $phone,
            'role' => $role,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    private function unit(string $number, User $resident): Unit
    {
        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => $number,
            'floor' => 1,
            'area' => 100,
            'residents_count' => 2,
            'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied,
            'uses_elevator' => true,
        ]);

        $resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id,
            'relation' => 'owner',
            'share_percent' => 100,
            'is_current' => true,
        ]);

        return $unit;
    }

    /**
     * یک نمونه از هر چیزی که ساکنِ قربانی «مالکش» است.
     *
     * @return array<string, mixed>
     */
    private function recordsOf(Unit $unit): array
    {
        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_id' => $unit->id,
            'period' => '1405-04',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'status' => BillStatus::Unpaid,
            'issued_at' => now(),
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->victim->id,
            'body' => 'پیامِ قربانی',
            'audience' => MessageAudience::All,
            'attachment_path' => 'messenger/victim.jpg',
            'attachment_name' => 'victim.jpg',
            'attachment_kind' => 'image',
            'author_name' => 'قربانی',
            'author_role' => 'owner',
            'unit_label' => '۱۰۱',
        ]);

        $poll = MessagePoll::create([
            'message_id' => $message->id,
            'question' => 'نظرسنجی',
            'voter_scope' => 'residents',
            'weight_mode' => 'per_unit',
            'allow_change' => true,
        ]);

        PollOption::create(['message_poll_id' => $poll->id, 'label' => 'بله', 'sort_order' => 0]);

        return [
            'unit' => $unit,
            // صاحبِ کیفِ شخصی (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶)
            'owner' => $this->victim,
            'bill' => $bill,
            'message' => $message,
            'poll' => $poll,
            'payment' => Payment::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'unit_id' => $unit->id,
                'user_id' => $this->victim->id,
                'bill_id' => $bill->id,
                'amount' => 100000,
                'method' => PaymentMethod::Receipt,
                'status' => PaymentStatus::Pending,
                'receipt_path' => 'receipts/victim.jpg',
            ]),
            'request' => ServiceRequest::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'unit_id' => $unit->id,
                'user_id' => $this->victim->id,
                'category' => ServiceRequestCategory::Facilities,
                'priority' => ServiceRequestPriority::Normal,
                'status' => ServiceRequestStatus::New,
                'title' => 'شوفاژ کار نمی‌کند',
                'description' => 'اتاقِ خواب سرد است.',
                'attachment_path' => 'requests/victim.jpg',
                'attachment_name' => 'victim.jpg',
            ]),
            'invitation' => ComplexInvitation::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'user_id' => $this->victim->id,
                'unit_id' => $unit->id,
                'role' => 'owner',
                'direction' => 'invite',
                'invited_by' => $this->manager->id,
                'status' => 'pending',
            ]),
            /*
             * ⚠️ درخواستِ عضویت به کاربرِ **سوم** تعلق دارد، نه به قربانی.
             *
             * جدولِ `complex_invitations` قیدِ یکتای
             * (complex_id, user_id, status) دارد، پس یک کاربر نمی‌تواند
             * هم‌زمان دعوت‌نامه‌ی معلق و درخواستِ معلق داشته باشد. این
             * محدودیتِ خودِ محصول است و تست باید با آن بسازد.
             */
            'join' => ComplexInvitation::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'user_id' => $this->applicant->id,
                'unit_id' => $unit->id,
                'role' => 'owner',
                'direction' => 'request',
                'status' => 'pending',
            ]),
            'subscription' => Subscription::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'user_id' => $this->manager->id,
                'plan' => 'pro',
                'status' => 'active',
                'method' => 'receipt',
                'amount' => 1000000,
                'months' => 12,
                'starts_at' => now(),
                'ends_at' => now()->addYear(),
            ]),
            'document' => GeneratedDocument::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id,
                'user_id' => $this->manager->id,
                'type' => 'bills-bundle',
                'title' => 'دسته‌ی قبض‌ها',
                'params' => [],
                'status' => 'ready',
                'path' => 'documents/bundle.pdf',
                'size_bytes' => 10,
            ]),
        ];
    }

    /**
     * درخواست با شرایطی همانند تولید.
     *
     * ⚠️ `TenantContext` صفر می‌شود: در تست کانتینر بین درخواست‌ها زنده
     * می‌ماند و مقدارِ به‌جامانده از درخواستِ قبلی نتیجه را خوش‌بینانه
     * می‌کند. در تولید هر درخواست کانتینرِ تازه دارد.
     */
    private function attack(User $who, string $method, string $uri, array $payload = []): TestResponse
    {
        app(TenantContext::class)->set(null);

        return $this->actingAs($who)->json($method, $uri, $payload);
    }

    private function assertRejected(TestResponse $response, string $what): void
    {
        $this->assertContains(
            $response->status(),
            [403, 404],
            "{$what} باید رد شود ولی {$response->status()} برگرداند.",
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① ساکن ← رکوردِ ساکنِ دیگر، در همان مجتمع
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function residentTargets(): array
    {
        return [
            'خواندن قبضِ همسایه' => ['GET', '/api/my-bills/%d', 'bill', []],
            'صفحه‌ی پرداختِ قبضِ همسایه' => ['GET', '/api/pay/%d', 'bill', []],
            'آپلود رسید روی قبضِ همسایه' => ['POST', '/api/pay/%d/receipt', 'bill', []],
            'پرداختِ قبضِ همسایه از کیفِ خود' => ['POST', '/api/wallet/pay/%d', 'bill', []],
            // کیف مالِ حساب است؛ صورت‌حسابِ کیفِ دیگری فقط برای مدیرِ همان مجتمع
            'صورت‌حسابِ کیفِ همسایه' => ['GET', '/api/wallet/members/%d', 'owner', []],
            'شروعِ پرداختِ آنلاینِ قبضِ همسایه' => ['POST', '/pay/%d/online', 'bill', []],
            'دانلودِ فاکتورِ قبضِ همسایه' => ['GET', '/bills/%d/invoice.pdf', 'bill', []],
            'دانلودِ رسیدِ پرداختِ همسایه' => ['GET', '/payments/%d/receipt.pdf', 'payment', []],
            'صورت‌حسابِ PDFِ واحدِ همسایه' => ['GET', '/units/%d/statement.pdf', 'unit', []],
            'پرونده‌ی PDFِ واحدِ همسایه' => ['GET', '/units/%d/dossier.pdf', 'unit', []],
            'دیدنِ درخواستِ خدماتِ همسایه' => ['GET', '/api/service-requests/%d', 'request', []],
            'پیوستِ درخواستِ همسایه' => ['GET', '/api/service-requests/%d/attachment', 'request', []],
            'کامنت روی درخواستِ همسایه' => ['POST', '/api/service-requests/%d/comments', 'request', ['body' => 'نفوذ']],
            'تغییرِ وضعیتِ درخواستِ همسایه' => ['PATCH', '/api/service-requests/%d/status', 'request', ['status' => 'closed']],
            'تغییرِ اولویتِ درخواستِ همسایه' => ['PATCH', '/api/service-requests/%d/priority', 'request', ['priority' => 'urgent']],
            'ارجاعِ درخواستِ همسایه' => ['PATCH', '/api/service-requests/%d/assign', 'request', ['assigned_to' => null]],
            'مخفی‌کردنِ پیامِ همسایه' => ['PATCH', '/api/messenger/%d/toggle-hide', 'message', []],
            'بستنِ نظرسنجیِ همسایه' => ['POST', '/api/messenger/polls/%d/close', 'poll', []],
            'پذیرشِ دعوت‌نامه‌ی همسایه' => ['POST', '/api/invitations/%d/accept', 'invitation', []],
            'ردِ دعوت‌نامه‌ی همسایه' => ['POST', '/api/invitations/%d/decline', 'invitation', []],
            'تاییدِ درخواستِ عضویتِ دیگری' => ['POST', '/api/join-requests/%d/approve', 'join', []],
            'ردِ درخواستِ عضویتِ دیگری' => ['POST', '/api/join-requests/%d/reject', 'join', []],
            'لغوِ اشتراکِ مجتمع' => ['POST', '/api/subscription/%d/cancel', 'subscription', []],
            'دانلودِ سندِ تولیدشده‌ی مدیر' => ['GET', '/reports/bills-bundle/%d.pdf', 'document', []],
        ];
    }

    /**
     * ⚠️ محورِ اصلیِ این مرحله.
     *
     * مهاجم و قربانی **هم‌مجتمع** اند، پس `ComplexScope` هیچ دفاعی نمی‌کند.
     * تنها چیزی که اینجا ایستاده، بررسیِ مالکیت در کنترلر یا Policy است.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('residentTargets')]
    public function test_a_resident_cannot_reach_another_residents_record(
        string $method,
        string $template,
        string $key,
        array $payload,
    ): void {
        $uri = sprintf($template, $this->owned[$key]->id);

        $this->assertRejected($this->attack($this->attacker, $method, $uri, $payload), "{$method} {$uri}");
    }

    /**
     * و هیچ‌کدام از آن تلاش‌ها نباید چیزی را عوض کرده باشد.
     *
     * ⚠️ ادعای «۴۰۳ برگشت» کافی نیست: کنترلری می‌تواند کارش را انجام دهد و
     * بعد در مرحله‌ی سریال‌سازی بیفتد. این تست به خودِ داده نگاه می‌کند.
     */
    public function test_none_of_those_attempts_changed_anything(): void
    {
        foreach (self::residentTargets() as [$method, $template, $key, $payload]) {
            $this->attack($this->attacker, $method, sprintf($template, $this->owned[$key]->id), $payload);
        }

        $this->assertSame(
            0.0,
            (float) $this->owned['bill']->fresh()->paid_amount,
            'قبضِ قربانی پس از حمله پرداخت‌شده به نظر می‌رسد.',
        );

        $this->assertSame(
            ServiceRequestStatus::New->value,
            $this->owned['request']->fresh()->status->value,
            'وضعیتِ درخواستِ قربانی عوض شده است.',
        );

        $this->assertFalse(
            (bool) $this->owned['message']->fresh()->is_hidden,
            'پیامِ قربانی مخفی شده است.',
        );

        $this->assertSame(
            'pending',
            $this->owned['invitation']->fresh()->status,
            'دعوت‌نامه‌ی قربانی پاسخ داده شده است.',
        );

        $this->assertSame(
            'active',
            $this->owned['subscription']->fresh()->status,
            'اشتراکِ مجتمع لغو شده است.',
        );

        $this->assertNull(
            $this->owned['poll']->fresh()->closes_at,
            'نظرسنجی بسته شده است.',
        );
    }

    /** و خودِ قربانی باید همچنان به رکوردهای خودش برسد — وگرنه تست پوچ است. */
    public function test_the_owner_still_reaches_their_own_records(): void
    {
        $this->attack($this->victim, 'GET', '/api/my-bills/'.$this->owned['bill']->id)
            ->assertOk();

        $this->attack($this->victim, 'GET', '/api/service-requests/'.$this->owned['request']->id)
            ->assertOk();

        $this->attack($this->victim, 'GET', '/api/wallet/'.$this->complex->id)
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② ساکن ← مسیرهای مخصوصِ مدیر
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function managerOnlyTargets(): array
    {
        return [
            'دیدنِ واحد' => ['GET', '/api/units/%d', 'unit'],
            'حذفِ واحد' => ['DELETE', '/api/units/%d', 'unit'],
            'انتقالِ مالکیتِ واحد' => ['POST', '/api/units/%d/transfer-ownership', 'unit'],
            'تاییدِ پرداخت' => ['POST', '/api/payments/%d/approve', 'payment'],
            'ردِ پرداخت' => ['POST', '/api/payments/%d/reject', 'payment'],
            'دانلودِ رسیدِ پرداخت از پنلِ مدیر' => ['GET', '/api/payments/%d/receipt', 'payment'],
        ];
    }

    /**
     * ⚠️ اینجا میدل‌ورِ نقش مدافع است، نه Policy.
     *
     * پس تست باید مطمئن شود که آن میدل‌ور **واقعاً روی این مسیرها بسته
     * شده**؛ جا افتادنِ یک `role:` روی یک مسیر، همان یک مسیر را باز
     * می‌گذارد بی‌آنکه هیچ‌جا خطایی دیده شود.
     */
    #[DataProvider('managerOnlyTargets')]
    public function test_a_resident_cannot_reach_manager_routes(
        string $method,
        string $template,
        string $key,
    ): void {
        $uri = sprintf($template, $this->owned[$key]->id);

        $this->assertRejected($this->attack($this->attacker, $method, $uri), "{$method} {$uri}");
    }

    /** حتی روی رکوردهای **خودش** هم نباید مسیرِ مدیریتی باز باشد. */
    public function test_a_resident_cannot_manage_even_their_own_unit(): void
    {
        $ownUnit = $this->attacker->units()->first();

        $this->assertRejected(
            $this->attack($this->attacker, 'DELETE', '/api/units/'.$ownUnit->id),
            'حذفِ واحدِ خودی توسطِ ساکن',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ مدیرِ مجتمع ← مسیرهای ادمینِ پلتفرم
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function platformTargets(): array
    {
        return [
            'ویرایشِ عضوِ پلتفرم' => ['PATCH', '/api/system/members/%s'],
            'حذفِ عضوِ پلتفرم' => ['DELETE', '/api/system/members/%s'],
            'فعال‌سازیِ مجتمع' => ['POST', '/api/system/complexes/%s/activate'],
            'تعلیقِ مجتمع' => ['POST', '/api/system/complexes/%s/suspend'],
            'انتخابِ مجتمع' => ['POST', '/api/system/complexes/%s/select'],
            'تاییدِ اشتراک' => ['POST', '/api/system/subscriptions/%s/approve'],
            'ردِ اشتراک' => ['POST', '/api/system/subscriptions/%s/reject'],
            'رسیدِ اشتراک' => ['GET', '/api/system/subscriptions/%s/receipt'],
        ];
    }

    #[DataProvider('platformTargets')]
    public function test_a_complex_admin_cannot_reach_platform_routes(string $method, string $template): void
    {
        $uri = sprintf($template, $this->targetIdFor($template));

        $this->assertRejected($this->attack($this->manager, $method, $uri), "{$method} {$uri}");
    }

    private function targetIdFor(string $template): int
    {
        if (str_contains($template, 'members')) {
            return $this->victim->id;
        }

        if (str_contains($template, 'complexes')) {
            return $this->complex->id;
        }

        return $this->owned['subscription']->id;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ مهمان
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ این تست فهرستِ مسیرها را از خودِ روتر می‌گیرد، نه از یک آرایه‌ی
     * دستی.
     *
     * فهرستِ دستی با اضافه‌شدنِ مسیرِ تازه کهنه می‌شود و کسی خبردار نمی‌شود.
     * با خواندن از روتر، هر مسیرِ شناسه‌دارِ تازه **خودبه‌خود** وارد این
     * دروازه می‌شود.
     */
    public function test_every_parameterised_api_route_rejects_a_guest(): void
    {
        $checked = 0;

        foreach ($this->guestReachableRoutes() as [$method, $uri]) {
            $response = $this->json($method, $uri);

            /*
             * ⚠️ ادعا **دقیقاً ۴۰۱** است، نه «۴۰۱ یا ۴۰۴».
             *
             * پذیرفتنِ ۴۰۴ این تست را پوچ می‌کرد: شناسه‌ی ۹۹۹۹۹۹ وجود ندارد،
             * پس route model binding **هر مسیری** را — حتی مسیرِ کاملاً
             * عمومی را — ۴۰۴ می‌کند. سنجش هم همین را نشان داد: با برداشتنِ
             * عمدیِ `auth` از یک مسیر، تست همچنان سبز ماند.
             *
             * ۴۰۱ فقط از میدل‌ورِ `auth` می‌آید و **پیش از** بایندینگ اجرا
             * می‌شود، پس تنها همین یک عدد ثابت می‌کند که دروازه سرِ جایش
             * است.
             */
            $this->assertSame(
                401,
                $response->status(),
                "{$method} {$uri} برای مهمان {$response->status()} برگرداند، نه ۴۰۱.",
            );

            $checked++;
        }

        $this->assertGreaterThan(
            30,
            $checked,
            'تعدادِ مسیرهای سنجیده‌شده مشکوک است؛ احتمالاً فیلتر بیش‌ازحد بسته است.',
        );
    }

    /**
     * مسیرهای شناسه‌دارِ `api/` که باید احراز هویت بخواهند.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    /** مسیرهای شناسه‌دارِ عمداً عمومی؛ دلیلِ هر کدام کنارِ فیلتر آمده. */
    private const PUBLIC_PARAMETERISED = [
        'api/blog/posts/{slug}',
    ];

    private function guestReachableRoutes(): array
    {
        $out = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/') || ! str_contains($uri, '{')) {
                continue;
            }

            // نسخه‌ی `api/v1/` همان مسیرهاست؛ یک بار کافی است
            if (str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            /*
             * مسیرِ شناسه‌دارِ **عمداً عمومی** — فهرستِ بسته، با دلیل.
             *
             * `blog/posts/{slug}` (مرحلهٔ ۷ · فاز ۷): مقاله‌ی منتشرشده‌ی بلاگِ
             * عمومی است و خودِ صفحه‌اش هم بی‌ورود باز می‌شود. محافظتش سقفِ نرخ
             * و «فقط منتشرشده» است (`BlogApiTest`)؛ مسیرِ عمومیِ تازه باید
             * این‌جا با دلیل بیاید، نه بی‌صدا.
             */
            if (in_array($uri, self::PUBLIC_PARAMETERISED, true)) {
                continue;
            }

            $method = collect($route->methods())->first(fn (string $m) => $m !== 'HEAD');

            if ($method === null) {
                continue;
            }

            // شناسه‌ی بی‌معنا: اگر مجوز درست کار کند، هرگز به بایند نمی‌رسیم
            $out[] = [$method, '/'.preg_replace('/\{[^}]+\}/', '999999', $uri)];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ⑤ نشست
    // ─────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────
    //  ⑥ دور زدنِ اسکوپِ مستأجری — سیاهه‌ی مجاز
    // ─────────────────────────────────────────────────────────────────────

    /**
     * هر جایی که `withoutGlobalScopes()` صدا زده می‌شود، با تعدادِ دقیق و
     * دلیلش.
     *
     * ⚠️ `ComplexScope` تنها وقتی معنا دارد که **هیچ‌جا بی‌جا دور زده
     * نشود**. یک `withoutGlobalScopes()` در مسیری که کاربرِ عادی صدایش
     * می‌زند، کلِ جداسازیِ مستأجری را برای آن مسیر خاموش می‌کند — و در
     * مرورِ کد به‌سختی دیده می‌شود، چون به نظر یک بهینه‌سازیِ بی‌آزار
     * می‌آید.
     *
     * ⚠️ **عدد هم بخشی از ادعاست.** بدونِ عدد، افزودنِ یک فراخوانیِ تازه
     * به فایلی که از قبل در سیاهه هست بی‌صدا رد می‌شد.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    private const SCOPE_BYPASS = [
        // ── دستور و صف: نه کاربرِ HTTPی هست، نه زمینه‌ی مستأجری ──
        'app/Console/Commands/CheckRaceConditions.php' => [19, 'ابزارِ سنجشِ همزمانی؛ عمداً کلِ داده را می‌بیند (+ صاحبِ کیف، + پذیرشِ هم‌زمانِ دعوت)'],
        'app/Console/Commands/RaceCheckout.php' => [7, 'آزمونِ همزمانیِ واقعیِ خرید (مرحلهٔ ۹ · فاز ۵)؛ فقط داده‌ی ساخته‌ی خودش را می‌خواند و پاک می‌کند'],
        'app/Console/Commands/PruneGeneratedDocuments.php' => [1, 'پاک‌سازیِ زمان‌بندی‌شده روی همه‌ی مجتمع‌ها'],
        'app/Console/Commands/PruneOrphanUploads.php' => [5, 'فایل‌های یتیم در همه‌ی مجتمع‌ها — پنج نوع پیوست از R58'],
        'app/Console/Commands/ResetUserPassword.php' => [2, 'بازیابیِ رمز از کنسول، پیش از هر نشستی'],
        'app/Console/Commands/SendBillReminders.php' => [1, 'یادآوریِ زمان‌بندی‌شده برای همه‌ی مجتمع‌ها'],
        'app/Console/Commands/StalePayments.php' => [2, 'گزارشِ اپراتور از پرداخت‌های بلاتکلیفِ همه‌ی مجتمع‌ها (R59)'],
        'app/Jobs/BuildBillsBundleJob.php' => [3, 'صف؛ زمینه‌ی مستأجری در worker وجود ندارد'],

        // ── پلتفرم: عمداً فرامجتمعی، و پشتِ نقشِ super_admin ──
        'app/Services/System/PlatformStats.php' => [14, 'آمارِ کلِ پلتفرم؛ فقط ادمینِ کل'],
        'app/Http/Controllers/Api/System/MemberController.php' => [3, 'فهرستِ کاربرانِ همه‌ی مجتمع‌ها؛ role:super_admin'],

        // ── پیش از آنکه کاربر عضوِ مجتمعی باشد ──
        // یکی کمتر از پیش: بررسیِ «قبلاً ثبت شده» در گامِ اولِ ثبت‌نام برداشته شد (شمارشِ حساب)
        'app/Http/Controllers/Api/AuthController.php' => [1, 'یکتاییِ شماره در کلِ پلتفرم سنجیده می‌شود — فقط پس از تأییدِ کد'],
        'app/Http/Controllers/Api/JoinRequestController.php' => [2, 'یافتنِ مدیر با شماره؛ متقاضی هنوز عضوِ هیچ مجتمعی نیست (+ اعلان به مدیرانِ همان مجتمع)'],
        'app/Http/Controllers/Api/InvitationController.php' => [3, 'گیرنده‌ی دعوت هنوز عضو نیست؛ واحد و دعوت‌کننده‌ی همان دعوتِ خودش (مرحلهٔ ۹ · فاز ۶)'],
        'app/Services/Account/Membership.php' => [5, 'پیوستن و پایانِ عضویت: حسابِ بی‌مجتمع، و واحدِ دعوت زیرِ قفل (مرحلهٔ ۹ · فاز ۶)'],
        'app/Services/Account/ComplexUpgrader.php' => [1, 'کاربر در همین لحظه صاحبِ مجتمع می‌شود'],
        // مرحلهٔ ۹ · فاز ۴: قفلِ خریدار — حسابِ اولیه هنوز مجتمعی ندارد
        'app/Services/Subscription/ComplexPurchase.php' => [2, 'خریدار هنوز عضوِ هیچ مجتمعی نیست؛ قفل و بازخوانیِ همان کاربرِ خودش'],
        'app/Http/Middleware/EnsureComplexActive.php' => [1, 'خودِ مجتمع را می‌خواند تا زمینه ساخته شود'],

        // ── قفل و بازخوانی، **پس از** اینکه مجوز سنجیده شده ──
        'app/Http/Controllers/Api/PaymentController.php' => [1, 'بازخوانیِ قبض داخلِ قفل؛ مجوزش بالاتر سنجیده شده'],
        'app/Http/Controllers/GatewayController.php' => [4, 'بازگشتِ بانک نشست ندارد؛ اصالت با شناسه‌ی مرجع'],
        'app/Services/Payment/PaymentService.php' => [6, 'بازخوانی داخلِ قفل، روی رکوردِ ازپیش‌مجازشده؛ و پرداخت‌کننده‌ی مازاد (صاحبِ کیف)'],
        'app/Services/Payment/TrackingCodes.php' => [2, 'کدِ رهگیریِ بانک در همه‌ی مجتمع‌ها یکتاست؛ فقط exists، هیچ داده‌ای برنمی‌گرداند (فاز ۹)'],
        'app/Services/Wallet/WalletService.php' => [10, 'کیف مالِ حساب است و صاحبش شاید دیگر عضوِ مجتمع نباشد: قفلِ صاحب، جیب‌ها، نامِ مجتمع‌ها و پرداخت‌کنندگانِ قبض (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶)'],
        'app/Http/Controllers/Api/WalletController.php' => [2, 'صورت‌حسابِ کیفِ خودِ صاحب حتی پس از پایانِ عضویت؛ مرزِ دسترسی `user_id` است'],
        // پیام‌رسانِ گفت‌وگومحور (مرحلهٔ ۶ · فاز ۱۲)
        'app/Services/Messenger/ConversationDirectory.php' => [1, 'یافتنِ گفت‌وگو با مجتمعِ صریح و کلیدِ یکتا، پس از خطای یکتاییِ ساختِ هم‌زمان'],
        'app/Services/Messenger/MessageActions.php' => [1, 'برداشتنِ سنجاقِ همان گفت‌وگویی که پیش‌تر دیده‌شدنش سنجیده شده، با شناسه‌ی صریح'],
        'app/Services/Messenger/MessageSender.php' => [2, 'پیامِ پیشینِ همان کاربر با همان client_id، و جلو بردنِ آخرین پیامِ گفت‌وگویی که از قبل مجاز شده'],
        'app/Services/Charge/BillGenerator.php' => [4, 'تولیدِ قبض با مجتمعِ صریح در پارامتر'],

        // ── شمارشِ سهمیه با مجتمعِ صریح ──
        'app/Services/Subscription/PlanGate.php' => [1, 'شمارشِ واحدهای یک مجتمعِ مشخص'],
    ];

    /**
     * ⚠️ هیچ فایلی خارج از این سیاهه حق ندارد اسکوپ را دور بزند.
     *
     * این تست جایگزینِ «مرورِ دستیِ ۶۰ فراخوانی» است. مرورِ دستی یک بار
     * انجام می‌شود و بعد کهنه می‌شود؛ این هر بار اجرا می‌شود.
     */
    public function test_no_new_place_bypasses_the_tenant_scope(): void
    {
        $found = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen(base_path()) + 1));

            $count = substr_count($this->sourceOf($relative), 'withoutGlobalScopes()');

            if ($count > 0) {
                $found[$relative] = $count;
            }
        }

        ksort($found);

        $expected = array_map(fn (array $row) => $row[0], self::SCOPE_BYPASS);
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            'سیاهه‌ی دور زدنِ ComplexScope با کدِ واقعی نمی‌خواند.
'
            .'هر فراخوانیِ تازه باید در `SCOPE_BYPASS` با دلیلش ثبت شود — '
            .'یا اصلاً نوشته نشود.',
        );
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * ⚠️ چرا Session Fixation اینجا **ساختاری** سنجیده می‌شود و نه رفتاری.
     *
     * ─── آنچه ابتدا نوشتم و اندازه‌گیری ردش کرد ─────────────────────────
     * اول تستی نوشتم که شناسه‌ی نشست را پیش و پس از احراز هویت مقایسه
     * می‌کرد. سبز شد. بعد `regenerate()` را عمداً از `completeLogin` حذف
     * کردم و **باز هم سبز ماند**.
     *
     * علتش این بود:
     *
     *     A(get /auth)  = 8yj7yeW8YTlSL9cHamaTWCZaDcg9tVajO2A3Rmmx
     *     B(login)      = mvFDGAGxnisxKoqaMVD5iQOHrvtZrpd1aLY1weYU
     *     C(verify)     = S9PpKsHLLSwppUdm98jUkvb7euLHjTpIqCQn9Zh5
     *     D(profile)    = qKWg5u8tq8SSMozFNjD2QDFo3Br7bGS599Bd5Kcl
     *
     * با `SESSION_DRIVER=array` (تنظیمِ `phpunit.xml`) هر درخواستِ تست
     * نشستِ تازه می‌گیرد. یعنی «شناسه عوض شد» چیزی درباره‌ی محصول
     * نمی‌گفت — فقط رفتارِ بسترِ تست را می‌سنجید. **تستِ سبزی که خرابکاری
     * را نمی‌گیرد، بدتر از نداشتنِ تست است.**
     *
     * ─── پس چه سنجیده می‌شود ────────────────────────────────────────────
     * خودِ فراخوانی. سنجشِ رفتاری نیازمندِ درایورِ نشستِ ماندگار و حملِ
     * کوکی بین درخواست‌هاست؛ عوض‌کردنِ درایورِ سراسریِ تست‌ها فقط برای این
     * یک مورد، شرایطِ اجرای ۹۰۰ تستِ دیگر را هم عوض می‌کند.
     *
     * ⚠️ **وضعیت: رفتار NOT VERIFIED است.** آنچه اثبات می‌شود این است که
     * فراخوانی سرِ جایش هست، نه اینکه در زمانِ اجرا اثرِ دلخواه را دارد.
     *
     * ✅ از مرحلهٔ ۵ · فاز ۱۰ رفتار هم سنجیده می‌شود: `SessionLifecycleTest`
     * درایورِ database را فقط برای خودش روشن می‌کند و کوکی را بینِ دو
     * «مرورگر» جابه‌جا می‌کند — بدونِ دست‌زدن به درایورِ بقیه‌ی تست‌ها.
     */
    public function test_the_authentication_path_regenerates_the_session(): void
    {
        $source = $this->sourceOf('app/Http/Controllers/Api/AuthController.php');

        $this->assertStringContainsString(
            '$request->session()->regenerate();',
            $source,
            'مسیرِ ورود نشست را بازتولید نمی‌کند؛ راهِ Session Fixation باز است.',
        );

        $this->assertStringContainsString(
            '$request->session()->invalidate();',
            $source,
            'مسیرِ خروج نشست را باطل نمی‌کند.',
        );

        $this->assertStringContainsString(
            '$request->session()->regenerateToken();',
            $source,
            'مسیرِ خروج توکنِ CSRF را بازتولید نمی‌کند.',
        );
    }

    /**
     * متنِ یک فایل، **بدونِ کامنت**.
     *
     * ⚠️ پاک‌سازی اجباری است: همین فایل‌ها در توضیحشان از `regenerate()`
     * نام می‌برند، پس بدونِ حذفِ کامنت تست حتی روی کدِ حذف‌شده هم سبز
     * می‌ماند. دو الگوی جدا لازم است — یکی‌کردنشان با پرچمِ `s` باعث
     * می‌شود `//.*` تا آخرِ فایل را ببلعد.
     */
    private function sourceOf(string $path): string
    {
        $code = (string) file_get_contents(base_path($path));
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        return (string) preg_replace('#^\s*//.*$#m', '', $code);
    }

    /**
     * و اینکه خروج **واقعاً** کاربر را بیرون می‌کند — این یکی رفتاری است.
     */
    public function test_logout_actually_ends_the_session(): void
    {
        $code = $this->postJson('/api/login', [
            'phone' => $this->victim->phone,
            'password' => 'secret123',
        ])->assertOk()->json('dev_code');

        $this->assertIsString($code, 'کدِ یک‌بارمصرف در حالتِ تست برنگشت.');

        $this->postJson('/api/login/verify', ['code' => $code])->assertOk();
        $this->assertAuthenticated();

        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest();
    }
}
