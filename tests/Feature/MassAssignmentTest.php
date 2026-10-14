<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreResidentRequest;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\Expense;
use App\Models\Message;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\Unit;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mass Assignment، ارتقای سطح از راهِ بدنه‌ی درخواست، و مبلغی که از فرانت
 * می‌آید (R54).
 *
 * ─── تفاوتش با R53 ─────────────────────────────────────────────────────────
 * R53 می‌پرسید «آیا اجازه داری این رکورد را لمس کنی؟». اینجا فرض بر این است
 * که **اجازه داری** — و پرسش این است که آیا می‌توانی با چپاندنِ یک کلیدِ
 * اضافه در بدنه‌ی درخواست، چیزی را عوض کنی که هیچ فرمی نمی‌دهدش.
 *
 * ─── چرا `fillable` به‌تنهایی جواب نیست ────────────────────────────────────
 * اندازه‌گیری نشان داد **هر ۳۶ مدل** `$fillable` دارند (هیچ مدلی باز نیست)،
 * ولی ستون‌های حساس در fillableهای زیادی هستند و **باید** باشند: خودِ
 * `BillGenerator` باید `complex_id` بنویسد. پس ادعای «`complex_id` در
 * fillable نباشد» هم غلط است و هم شکستنی.
 *
 * مرزِ واقعی جای دیگری است: **چه چیزی از HTTP به آن ستون‌ها می‌رسد.** پس
 * اینجا دو چیز سنجیده می‌شود:
 *
 *   ① رفتار: کلیدِ حساس در بدنه‌ی یک درخواستِ **مجاز** فرستاده می‌شود و
 *     ادعا می‌شود هیچ اثری ندارد.
 *   ② ساختار: سیاهه‌ای از `FormRequest`هایی که ستونِ حساس را **عمداً**
 *     می‌پذیرند، با دلیل. هر مورد تازه باید ثبت شود.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Complex $other;

    private User $manager;

    private User $resident;

    private Unit $unit;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = $this->complex('alpha');
        $this->other = $this->complex('beta');

        $this->manager = $this->user(UserRole::ComplexAdmin, '09330000001', $this->complex);
        $this->resident = $this->user(UserRole::Owner, '09330000002', $this->complex);

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => '۱',
            'floor' => 1,
            'area' => 100,
            'residents_count' => 2,
            'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied,
            'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id,
            'relation' => 'owner',
            'share_percent' => 100,
            'is_current' => true,
        ]);

        $this->bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => '1405-04',
            'total_amount' => 500000,
            'paid_amount' => 0,
            'status' => BillStatus::Unpaid,
            'issued_at' => now(),
        ]);
    }

    private function complex(string $slug): Complex
    {
        return Complex::create(['type' => 'residential',
            'name' => 'مجتمع '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'fake',
            'messenger_enabled' => true,
        ]);
    }

    private function user(UserRole $role, string $phone, ?Complex $complex): User
    {
        return User::create([
            'complex_id' => $complex?->id,
            'name' => 'کاربر '.$phone,
            'phone' => $phone,
            'role' => $role,
            'password' => Hash::make('secret123'),
            'is_active' => true,
            // بدونِ این، پیام‌رسان ۴۰۳ می‌دهد و تستِ جعلِ نویسنده هرگز به
            // خودِ جعل نمی‌رسد
            'can_message' => true,
        ]);
    }

    /**
     * درخواست با شرایطی همانند تولید.
     *
     * ⚠️ `TenantContext` صفر می‌شود چون در تست کانتینر بین درخواست‌ها زنده
     * می‌ماند و مقدارِ به‌جامانده نتیجه را خوش‌بینانه می‌کند.
     */
    private function as(User $who, string $method, string $uri, array $payload = [])
    {
        app(TenantContext::class)->set(null);

        return $this->actingAs($who)->json($method, $uri, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① ارتقای سطح از راهِ بدنه‌ی درخواست
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ کلاسیک‌ترین حمله‌ی Mass Assignment.
     *
     * `role`، `is_active` و `complex_id` هر سه در `$fillable`ِ مدلِ `User`
     * هستند — و باید باشند، چون کدِ داخلی می‌نویسدشان. تنها چیزی که جلوی
     * کاربر را می‌گیرد این است که `UpdateProfileRequest` آن کلیدها را
     * **اصلاً برنمی‌گرداند**.
     */
    public function test_a_resident_cannot_promote_themselves_through_the_profile_form(): void
    {
        /*
         * رمز از مرحلهٔ ۹ در این فرم `prohibited` است (۴۲۲، نه نادیده‌گرفتن)؛ پس
         * جدا سنجیده می‌شود و درخواستِ اصلی فیلدهای سطحِ دسترسی را بی رمز می‌فرستد.
         */
        $this->as($this->resident, 'PUT', '/api/profile', [
            'first_name' => 'نام', 'last_name' => 'تازه', 'password' => 'hacked-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->as($this->resident, 'PUT', '/api/profile', [
            'first_name' => 'نام',
            'last_name' => 'تازه',
            'role' => UserRole::SuperAdmin->value,
            'is_active' => true,
            'complex_id' => $this->other->id,
        ])->assertOk();

        $fresh = $this->resident->fresh();

        $this->assertSame(UserRole::Owner, $fresh->role, 'نقشِ کاربر از راهِ فرمِ پروفایل عوض شد.');
        $this->assertSame($this->complex->id, $fresh->complex_id, 'مجتمعِ کاربر عوض شد.');
        $this->assertSame('نام تازه', $fresh->name, 'فیلدِ مجاز اصلاً ذخیره نشد؛ تست پوچ است.');

        $this->assertTrue(
            Hash::check('secret123', $fresh->password),
            'رمزِ عبور از راهِ فرمِ پروفایل عوض شد.',
        );
    }

    /**
     * و مدیرِ مجتمع هم نمی‌تواند ساکنی را ادمینِ کل کند.
     *
     * ⚠️ اینجا برخلافِ بالا، `role` **در قواعد هست** — ولی به `owner,tenant`
     * محدود شده. تست همان محدودیت را قفل می‌کند.
     */
    public function test_a_manager_cannot_create_a_super_admin(): void
    {
        $this->as($this->manager, 'POST', '/api/residents', [
            'name' => 'ساکنِ تازه',
            'phone' => '09339999999',
            'role' => UserRole::SuperAdmin->value,
            'password' => 'Str0ngPass',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertNull(
            User::withoutGlobalScopes()->where('phone', '09339999999')->first(),
            'کاربر با نقشِ ممنوع ساخته شد.',
        );
    }

    /** همان فرم با نقشِ مجاز باید کار کند — وگرنه تستِ بالا چیزی ثابت نمی‌کند. */
    public function test_the_same_form_still_accepts_an_allowed_role(): void
    {
        // مرحلهٔ ۹ · فاز ۶: فرم دعوت می‌سازد؛ نقشِ دعوت همان نقشِ مجاز است
        $this->as($this->manager, 'POST', '/api/residents', [
            'phone' => '09339999999',
            'role' => UserRole::Tenant->value,
            'unit_id' => $this->unit->id,
        ])->assertSuccessful();

        $invitation = ComplexInvitation::where('phone', '09339999999')->first();

        $this->assertNotNull($invitation);
        $this->assertSame(UserRole::Tenant, $invitation->role);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② چپاندنِ `complex_id` — بیرون‌بردنِ رکورد از مجتمعِ خودش
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ اگر این کار می‌کرد، `ComplexScope` هم دور زده می‌شد.
     *
     * رکوردی که `complex_id`اش را مهاجم تعیین کند، در مجتمعِ **دیگری**
     * می‌نشیند: هزینه‌ای در دفترِ مجتمعِ همسایه، اطلاعیه‌ای در تابلوی آن‌ها.
     */
    public function test_a_manager_cannot_plant_records_in_another_complex(): void
    {
        $this->as($this->manager, 'POST', '/api/finance/expenses', [
            'title' => 'هزینه‌ی کاشته‌شده',
            'amount' => 50000,
            'category' => 'owner',
            'period' => '1405-04',
            'complex_id' => $this->other->id,
        ])->assertSuccessful();

        $expense = Expense::withoutGlobalScopes()->where('title', 'هزینه‌ی کاشته‌شده')->first();

        $this->assertNotNull($expense, 'هزینه اصلاً ساخته نشد؛ تست پوچ است.');
        $this->assertSame(
            $this->complex->id,
            $expense->complex_id,
            'هزینه در مجتمعِ دیگری نشست.',
        );
    }

    public function test_a_unit_cannot_be_created_into_another_complex(): void
    {
        $this->as($this->manager, 'POST', '/api/units', [
            'unit_number' => '۲۰۲',
            'floor' => 2,
            'area' => 90,
            'residents_count' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied->value,
            'coefficient' => 1,
            'complex_id' => $this->other->id,
        ])->assertSuccessful();

        $unit = Unit::withoutGlobalScopes()->where('unit_number', '۲۰۲')->first();

        $this->assertNotNull($unit, 'واحد اصلاً ساخته نشد؛ تست پوچ است.');
        $this->assertSame($this->complex->id, $unit->complex_id, 'واحد در مجتمعِ دیگری نشست.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ چپاندنِ `user_id` و `status` — جعلِ هویت و پرشِ گردشِ کار
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ `user_id` تعیین می‌کند درخواست به نامِ **چه کسی** ثبت شود.
     *
     * و `status` گردشِ کار را می‌پراند: درخواستی که مستقیم `closed` ثبت
     * شود، هرگز به صندوقِ مدیر نمی‌رسد.
     */
    public function test_a_resident_cannot_forge_the_author_or_the_status(): void
    {
        $this->as($this->resident, 'POST', '/api/service-requests', [
            'title' => 'درخواستِ جعلی',
            'description' => 'متنِ درخواست برای سنجش.',
            'category' => 'facilities',
            'user_id' => $this->manager->id,
            'status' => ServiceRequestStatus::Closed->value,
            'complex_id' => $this->other->id,
            'assigned_to' => $this->manager->id,
        ])->assertSuccessful();

        $request = ServiceRequest::withoutGlobalScopes()->where('title', 'درخواستِ جعلی')->first();

        $this->assertNotNull($request, 'درخواست اصلاً ساخته نشد؛ تست پوچ است.');
        $this->assertSame($this->resident->id, $request->user_id, 'نویسنده جعل شد.');
        $this->assertSame($this->complex->id, $request->complex_id, 'درخواست در مجتمعِ دیگری نشست.');
        $this->assertSame(
            ServiceRequestStatus::New,
            $request->status,
            'وضعیتِ اولیه از بدنه‌ی درخواست تعیین شد و گردشِ کار پریده شد.',
        );
        $this->assertNull($request->assigned_to, 'مسئولِ رسیدگی از بدنه‌ی درخواست تعیین شد.');
    }

    /** پیام هم باید به نامِ فرستنده‌ی واقعی ثبت شود، و مخفی‌نشده. */
    public function test_a_resident_cannot_forge_a_message_author(): void
    {
        $this->as($this->resident, 'POST', '/api/messenger', [
            'body' => 'پیامِ جعلی',
            'user_id' => $this->manager->id,
            'is_hidden' => true,
            'author_role' => UserRole::ComplexAdmin->value,
            'complex_id' => $this->other->id,
        ])->assertSuccessful();

        $message = Message::withoutGlobalScopes()->where('body', 'پیامِ جعلی')->first();

        $this->assertNotNull($message, 'پیام اصلاً ساخته نشد؛ تست پوچ است.');
        $this->assertSame($this->resident->id, $message->user_id, 'فرستنده‌ی پیام جعل شد.');
        $this->assertSame($this->complex->id, $message->complex_id, 'پیام در مجتمعِ دیگری نشست.');
        $this->assertFalse((bool) $message->is_hidden, 'پیام از همان ابتدا مخفی ثبت شد.');
        $this->assertNotSame(
            UserRole::ComplexAdmin->value,
            $message->author_role,
            'نقشِ نمایشیِ نویسنده از بدنه‌ی درخواست تعیین شد.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ مبلغ — که هرگز نباید از فرانت معتبر باشد
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ پرداختِ آنلاین **هیچ مبلغی از درخواست نمی‌خواند**.
     *
     * اگر می‌خواند، کاربر می‌توانست قبضِ ۵۰۰٬۰۰۰ را با ۱ تومان تسویه کند.
     */
    public function test_the_online_payment_amount_comes_from_the_bill_not_the_request(): void
    {
        $this->as($this->resident, 'POST', '/pay/'.$this->bill->id.'/online', [
            'amount' => 1,
            'total_amount' => 1,
        ]);

        $payment = Payment::withoutGlobalScopes()->where('bill_id', $this->bill->id)->latest('id')->first();

        $this->assertNotNull($payment, 'پرداختی ساخته نشد؛ تست پوچ است.');
        $this->assertSame(
            500000.0,
            (float) $payment->amount,
            'مبلغِ پرداختِ آنلاین از بدنه‌ی درخواست خوانده شد.',
        );
    }

    /**
     * ⚠️ پرداخت از کیفِ پول **مبلغ را از درخواست می‌گیرد** — و باید سقف
     * بخورد.
     *
     * این تنها مسیری است که عمداً مبلغِ کاربر را می‌پذیرد (پرداختِ جزئی).
     * پس ادعا این نیست که مبلغ نادیده گرفته می‌شود، بلکه این است که از
     * **بدهی** و **موجودی** فراتر نمی‌رود.
     */
    public function test_the_wallet_payment_is_capped_by_the_balance_and_the_debt(): void
    {
        app(WalletService::class)->credit($this->resident, $this->unit,
            120000,
            'manual',
            null,
            'شارژِ آزمایشیِ کیف',
        );

        $this->as($this->resident, 'POST', '/api/wallet/pay/'.$this->bill->id, [
            'amount' => 999_999_999,
        ])->assertOk();

        $this->assertSame(
            120000.0,
            (float) $this->bill->fresh()->paid_amount,
            'پرداختِ کیف از موجودی فراتر رفت.',
        );

        $this->assertSame(
            0.0,
            (float) app(WalletService::class)->balance($this->resident),
            'موجودیِ کیف پس از پرداخت درست نیست.',
        );
    }

    /** و مبلغِ منفی نباید کیف را شارژ کند. */
    public function test_a_negative_wallet_amount_is_rejected(): void
    {
        app(WalletService::class)->credit($this->resident, $this->unit, 50000, 'manual', null, 'شارژ');

        $this->as($this->resident, 'POST', '/api/wallet/pay/'.$this->bill->id, [
            'amount' => -70000,
        ])->assertStatus(422);

        $this->assertSame(
            50000.0,
            (float) app(WalletService::class)->balance($this->resident),
            'مبلغِ منفی موجودیِ کیف را عوض کرد.',
        );

        $this->assertSame(
            0.0,
            (float) $this->bill->fresh()->paid_amount,
            'قبض با مبلغِ منفی پرداخت‌شده به نظر می‌رسد.',
        );
    }

    /**
     * رسیدِ دستی مبلغ می‌گیرد، ولی نه هر عددی.
     *
     * ⚠️ سقف کمی بالاتر از بدهی است (برای سرراست‌کردن یا کارمزد)؛ ادعای
     * تست همان سقف است، نه یک عددِ دلخواه.
     */
    public function test_a_receipt_cannot_declare_an_arbitrary_amount(): void
    {
        $this->as($this->resident, 'POST', '/api/pay/'.$this->bill->id.'/receipt', [
            'amount' => 999_999_999,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(
            0,
            Payment::withoutGlobalScopes()->where('bill_id', $this->bill->id)->count(),
            'رسید با مبلغِ نجومی ثبت شد.',
        );
    }
    // ─────────────────────────────────────────────────────────────────────
    //  ⑤ مرزِ ساختاری: کدام فرم اجازه دارد ستونِ حساس بگیرد
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ستون‌هایی که رسیدنشان از HTTP به مدل، یا ارتقای سطح است یا
     * دور زدنِ جداسازیِ مستأجری.
     *
     * @var array<int, string>
     */
    private const PRIVILEGED = [
        'role', 'complex_id', 'user_id', 'is_active', 'password',
        'balance', 'paid_amount', 'total_amount', 'reviewed_by',
        'verified', 'permissions', 'is_admin',
    ];

    /**
     * `FormRequest`هایی که **عمداً** ستونِ حساس می‌پذیرند، با دلیل.
     *
     * ⚠️ سیاهه، همان «ممیزیِ ۳۶ مدل»ِ برنامه است — ولی روی مرزِ درست.
     * ادعای «`complex_id` در `fillable` نباشد» غلط بود: کدِ داخلی باید
     * بنویسدش. آنچه باید کنترل شود این است که **کدام فرم** از بیرون
     * می‌پذیردش.
     *
     * @var array<string, array<int, string>>
     */
    private const PRIVILEGED_INPUTS = [
        // ── رمزِ خودِ کاربر، در فرم‌هایی که کارشان همین است ──
        'LoginRequest.php' => ['password'],
        'RegisterRequest.php' => ['password'],
        'ResetPasswordRequest.php' => ['password'],
        'UpdatePasswordRequest.php' => ['password'],
        // تأییدِ دوباره پیش از عملیاتِ حساس؛ فقط سنجیده می‌شود، ذخیره نه (فاز ۱۰)
        'ConfirmPasswordRequest.php' => ['password'],

        // ── مرحلهٔ ۹: تغییرِ شماره فقط برای حسابِ خودِ درخواست‌کننده — شناسه‌ها `prohibited` ──
        'StartPhoneChangeRequest.php' => ['user_id'],
        'ConfirmPhoneChangeRequest.php' => ['user_id'],
        // پروفایل: شماره و رمز `prohibited`؛ مسیرهای امنِ خودشان را دارند
        'UpdateProfileRequest.php' => ['password'],

        // ── مرحلهٔ ۹ · فاز ۶: `prohibited` — نامِ ستون در قواعد هست تا **رد** شود ──
        // مدیر دیگر برای کسی رمز نمی‌گذارد؛ دعوت می‌فرستد (F5)
        'StoreManagerRequest.php' => ['password'],

        // ⚠️ رمزِ **پنلِ پیامک** است، نه رمزِ کاربر
        'UpdateSmsSettingsRequest.php' => ['password'],

        // ── مدیر ساکن دعوت می‌کند: رمز `prohibited`، نقش به owner/tenant محدود ──
        'StoreResidentRequest.php' => ['password', 'role'],
        // ویرایشِ ساکن: رمز `prohibited` (تصاحبِ حساب)، نقش به owner/tenant محدود
        'UpdateResidentRequest.php' => ['password', 'role'],
        // تأییدِ درخواستِ پیوستن: نقش به owner/tenant محدود (F6)
        'ApproveJoinRequestRequest.php' => ['role'],

        // ── فقط ادمینِ کل: انتخابِ مجتمعِ هدف ──
        'GrantPlanRequest.php' => ['complex_id'],
        'RevokePlanRequest.php' => ['complex_id'],

        // ── رفتن به مجتمعِ دیگر (۱۰/۳): شناسه فقط «کدام» است؛ اجازه از عضویتِ فعالِ خودِ حساب ──
        'SwitchContextRequest.php' => ['complex_id'],

        // ── گفت‌وگوی خصوصیِ مدیر با یک عضو (۱۰/۹): شناسه فقط «با کدام عضو» است؛ مالک یا مستأجرِ فعالِ همین مجتمع ──
        'StoreConversationRequest.php' => ['user_id'],

        // ── فقط ادمینِ کل: نقش و فعال‌بودنِ کاربر ──
        'UpdateMemberRequest.php' => ['role', 'is_active'],

        // ── محتوای عمومی؛ `is_active` یعنی «نمایش داده شود یا نه» ──
        'StoreAdvertisementRequest.php' => ['is_active'],
        'StorePlanRequest.php' => ['is_active'],
    ];

    /**
     * ⚠️ هیچ فرمِ تازه‌ای حق ندارد بی‌سروصدا ستونِ حساس بپذیرد.
     *
     * این جایگزینِ «ممیزیِ دستی» است. ممیزی یک بار انجام می‌شود و کهنه
     * می‌شود؛ این هر بار اجرا می‌شود.
     */
    public function test_no_new_form_accepts_a_privileged_column(): void
    {
        $found = [];

        foreach (glob(app_path('Http/Requests/*.php')) as $file) {
            /*
             * ⚠️ کامنت‌ها با دو الگوی جدا پاک می‌شوند؛ یکی‌کردنشان با پرچمِ
             * `s` باعث می‌شود `//.*` تا آخرِ فایل را ببلعد. همین فایل‌ها در
             * توضیحشان از `role` و `complex_id` نام می‌برند.
             */
            $code = (string) file_get_contents($file);
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
            $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

            if (preg_match('/function rules\(\): array\s*\{(.*?)
    \}/s', $code, $match) !== 1) {
                continue;
            }

            preg_match_all("/'([a-z_][a-z0-9_.*]*)'\s*=>/", $match[1], $keys);

            $privileged = array_values(array_unique(array_filter(
                array_map(fn (string $k) => explode('.', $k)[0], $keys[1]),
                fn (string $k) => in_array($k, self::PRIVILEGED, true),
            )));

            sort($privileged);

            if ($privileged !== []) {
                $found[basename($file)] = $privileged;
            }
        }

        $expected = array_map(function (array $columns): array {
            sort($columns);

            return $columns;
        }, self::PRIVILEGED_INPUTS);

        ksort($expected);
        ksort($found);

        $this->assertSame(
            $expected,
            $found,
            'سیاهه‌ی ورودی‌های حساس با قواعدِ واقعی نمی‌خواند.
'
            .'هر ستونِ حساسِ تازه در یک FormRequest باید در `PRIVILEGED_INPUTS` '
            .'با دلیلش ثبت شود — یا اصلاً پذیرفته نشود.',
        );
    }

    /**
     * و `role` در فرمِ ساکن باید **به owner/tenant محدود** بماند.
     *
     * ⚠️ سیاهه‌ی بالا فقط می‌گوید «این فرم `role` می‌گیرد»، نه اینکه چه
     * مقدارهایی. باز کردنِ همان قاعده به `Rule::enum(UserRole::class)` از
     * دیدِ آن سیاهه بی‌تغییر است ولی مدیر را به ساختنِ ادمینِ کل توانا
     * می‌کند. تستِ رفتاریِ بالا این را می‌گیرد؛ این ادعا فقط دلیلش را
     * کنارِ خودِ قاعده نگه می‌دارد.
     */
    public function test_the_resident_form_restricts_the_role_values(): void
    {
        $rules = (new StoreResidentRequest)->rules();

        $this->assertContains(
            'in:owner,tenant',
            $rules['role'],
            'قاعده‌ی نقشِ ساکن دیگر به owner/tenant محدود نیست.',
        );
    }
}
