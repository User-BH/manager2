<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Middleware\SetCurrentComplex;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * جداسازی مجتمع‌ها روی مسیرهایی که مدل را از پارامتر URL می‌خوانند.
 *
 * این تست‌ها بازگشتِ یک نشتِ واقعی را می‌گیرند: چون SubstituteBindings پیش از
 * میدل‌ور مجتمع اجرا می‌شد، اسکوپ سراسری هنگام خواندن مدل خالی بود و مدیر یک
 * مجتمع می‌توانست با دست‌کاری شناسه در URL، واحد و اطلاعیه و هزینه‌ی مجتمع
 * دیگری را ویرایش یا حذف کند.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> */
    private array $a;

    /** @var array<string,mixed> */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->buildComplex('alpha', '09171110');
        $this->b = $this->buildComplex('beta', '09172220');
    }

    /**
     * یک مجتمع کامل با نمونه‌ای از هر موجودیتِ قابل بایند.
     *
     * @return array<string,mixed>
     */
    private function buildComplex(string $slug, string $phonePrefix): array
    {
        $complex = Complex::create([
            'name' => 'مجتمع '.$slug, 'slug' => $slug.'-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake', 'messenger_enabled' => true,
        ]);

        $admin = User::create([
            'complex_id' => $complex->id, 'name' => 'مدیر '.$slug, 'phone' => $phonePrefix.'01',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $resident = User::create([
            'complex_id' => $complex->id, 'name' => 'ساکن '.$slug, 'phone' => $phonePrefix.'02',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $resident->units()->attach($unit->id, [
            'complex_id' => $complex->id, 'relation' => 'owner', 'share_percent' => 100, 'is_current' => true,
        ]);

        return [
            'complex' => $complex,
            'admin' => $admin,
            'resident' => $resident,
            'unit' => $unit,
            'bill' => Bill::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'unit_id' => $unit->id, 'period' => '1405-04',
                'total_amount' => 500000, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'issued_at' => now(),
            ]),
            'rule' => ChargeRule::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'name' => 'شارژ ثابت', 'type' => 'fixed',
                'category' => 'owner', 'is_active' => true, 'sort_order' => 0,
            ]),
            'expense' => Expense::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'title' => 'هزینه', 'amount' => 1000, 'category' => 'owner',
                'period' => '1405-04', 'spend_date' => now(), 'is_distributed' => false,
            ]),
            'income' => Income::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'title' => 'درآمد', 'amount' => 1000,
                'period' => '1405-04', 'received_date' => now(),
            ]),
            'discount' => Discount::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'unit_id' => $unit->id, 'amount' => 100,
                'period' => '1405-04', 'reason' => 'تخفیف',
            ]),
            'payment' => Payment::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'unit_id' => $unit->id, 'user_id' => $resident->id,
                'amount' => 1000, 'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Pending,
                'receipt_path' => 'receipts/x.jpg',
            ]),
            'announcement' => Announcement::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'title' => 'اطلاعیه '.$slug, 'body' => 'متن',
                'audience' => 'all', 'is_active' => true, 'published_at' => now(), 'created_by' => $admin->id,
            ]),
            'message' => Message::withoutGlobalScopes()->create([
                'complex_id' => $complex->id, 'user_id' => $resident->id, 'body' => 'پیام',
                'author_name' => 'ساکن', 'author_role' => 'owner', 'unit_label' => '۱',
            ]),
        ];
    }

    /**
     * درخواست از سوی مدیر مجتمع A، با شرایطی همانند تولید.
     *
     * TenantContext صفر می‌شود چون در تست، کانتینر بین درخواست‌ها زنده می‌ماند
     * و مقدارِ به‌جامانده از درخواست قبلی نتیجه را خوش‌بینانه می‌کند؛ در تولید
     * هر درخواست کانتینر تازه دارد.
     */
    private function asAdminOfA(string $method, string $uri, array $payload = [])
    {
        app(TenantContext::class)->set(null);

        return $this->actingAs($this->a['admin'])->json($method, $uri, $payload);
    }

    public static function crossTenantRoutes(): array
    {
        return [
            'ویرایش واحد' => ['PUT', 'units/%d', 'unit', [
                'unit_number' => '99', 'floor' => 1, 'area' => 100,
                'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
            ]],
            'حذف واحد' => ['DELETE', 'units/%d', 'unit', []],
            'تغییر وضعیت قانون شارژ' => ['PATCH', 'charge-rules/%d/toggle', 'rule', []],
            'حذف قانون شارژ' => ['DELETE', 'charge-rules/%d', 'rule', []],
            'حذف هزینه' => ['DELETE', 'finance/expenses/%d', 'expense', []],
            'حذف درآمد' => ['DELETE', 'finance/incomes/%d', 'income', []],
            'حذف تخفیف' => ['DELETE', 'discounts/%d', 'discount', []],
            'حذف اطلاعیه' => ['DELETE', 'announcements/%d', 'announcement', []],
            'ویرایش اطلاعیه' => ['PUT', 'announcements/%d', 'announcement', [
                'title' => 'ربوده شد', 'body' => 'متن', 'audience' => 'all',
            ]],
            'مخفی کردن پیام' => ['PATCH', 'messenger/%d/toggle-hide', 'message', []],
            'خواندن قبض' => ['GET', 'my-bills/%d', 'bill', []],
            'صفحه‌ی پرداخت قبض' => ['GET', 'pay/%d', 'bill', []],
            'دانلود رسید پرداخت' => ['GET', 'payments/%d/receipt', 'payment', []],
            'تایید پرداخت' => ['POST', 'payments/%d/approve', 'payment', []],
            'رد پرداخت' => ['POST', 'payments/%d/reject', 'payment', ['note' => 'x']],
        ];
    }

    #[DataProvider('crossTenantRoutes')]
    public function test_an_admin_cannot_touch_another_complexes_records(
        string $method,
        string $template,
        string $key,
        array $payload,
    ): void {
        $uri = '/api/'.sprintf($template, $this->b[$key]->id);

        $response = $this->asAdminOfA($method, $uri, $payload);

        $this->assertContains(
            $response->status(),
            [403, 404],
            "{$method} {$uri} باید رد شود ولی {$response->status()} برگرداند.",
        );
    }

    public function test_the_other_complexes_records_survive_intact(): void
    {
        // همه‌ی تلاش‌های بالا یک‌جا، بعد بررسی می‌کنیم چیزی از مجتمع B کم نشده
        foreach (self::crossTenantRoutes() as [$method, $template, $key, $payload]) {
            $this->asAdminOfA($method, '/api/'.sprintf($template, $this->b[$key]->id), $payload);
        }

        foreach (['unit', 'rule', 'expense', 'income', 'discount', 'announcement', 'message'] as $key) {
            $model = $this->b[$key];
            $this->assertNotNull(
                $model::withoutGlobalScopes()->find($model->id),
                "رکورد «{$key}» مجتمع B حذف شده است.",
            );
        }

        // و واحد B هنوز شماره‌ی خودش را دارد (ویرایش نشده)
        $this->assertSame('1', $this->b['unit']->fresh()->unit_number);
    }

    public function test_an_admin_can_still_manage_their_own_complex(): void
    {
        // جداسازی نباید کار عادی را هم ببندد
        $this->asAdminOfA('PUT', '/api/units/'.$this->a['unit']->id, [
            'unit_number' => '77', 'floor' => 2, 'area' => 120,
            'residents_count' => 3, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ])->assertOk();

        $this->assertSame('77', $this->a['unit']->fresh()->unit_number);

        $this->asAdminOfA('DELETE', '/api/announcements/'.$this->a['announcement']->id)->assertOk();
        $this->asAdminOfA('PATCH', '/api/charge-rules/'.$this->a['rule']->id.'/toggle')->assertOk();
    }

    public function test_a_super_admin_scoped_to_one_complex_cannot_reach_another(): void
    {
        $superAdmin = User::create([
            'name' => 'ادمین کل', 'phone' => '09173330001',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        app(TenantContext::class)->set(null);

        // ادمین کل مجتمع A را انتخاب می‌کند
        $this->actingAs($superAdmin)
            ->postJson('/api/system/complexes/'.$this->a['complex']->id.'/select')
            ->assertOk();

        app(TenantContext::class)->set(null);

        // پس تا وقتی A انتخاب است، واحد B را نمی‌بیند
        $this->actingAs($superAdmin)
            ->deleteJson('/api/units/'.$this->b['unit']->id)
            ->assertNotFound();

        $this->assertNotNull(Unit::withoutGlobalScopes()->find($this->b['unit']->id));
    }

    public function test_the_complex_middleware_runs_before_route_model_binding(): void
    {
        /*
         * نگهبانِ خودِ اصلاح: اگر روزی ترتیب اولویت میدل‌ورها به‌هم بخورد،
         * بایندینگ دوباره پیش از تعیین مجتمع اجرا می‌شود و همه‌ی تست‌های بالا
         * می‌شکنند بی‌آنکه علتش روشن باشد. این تست علت را مستقیم می‌گوید.
         */
        $kernel = app(Kernel::class);
        $property = (new \ReflectionClass($kernel))->getProperty('middlewarePriority');
        $property->setAccessible(true);
        $priority = $property->getValue($kernel);

        $complexAt = array_search(SetCurrentComplex::class, $priority, true);
        $bindingsAt = array_search(SubstituteBindings::class, $priority, true);

        $this->assertNotFalse($complexAt, 'SetCurrentComplex در فهرست اولویت نیست.');
        $this->assertNotFalse($bindingsAt, 'SubstituteBindings در فهرست اولویت نیست.');
        $this->assertLessThan(
            $bindingsAt,
            $complexAt,
            'SetCurrentComplex باید پیش از SubstituteBindings اجرا شود، وگرنه بایندینگ بدون فیلتر مجتمع انجام می‌شود.',
        );
    }
}
