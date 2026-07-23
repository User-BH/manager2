<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\Complex;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * موارد ۱۷ تا ۲۳ گزارش: کوئری‌های داشبورد، جستجو، دانلود بکاپ و رسید اشتراک.
 */
class PolishTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع پولیش', 'slug' => 'polish-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none',
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر', 'phone' => '09241110001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->superAdmin = User::create([
            'name' => 'ادمین کل', 'phone' => '09241110002',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /* ------------------ ۱۷) کوئری‌های داشبورد ------------------ */

    public function test_the_trend_chart_uses_a_fixed_number_of_queries(): void
    {
        /*
         * پیش از این برای هر ماه سه کوئری جدا زده می‌شد؛ نمودار شش‌ماهه یعنی
         * ۲۱ رفت‌وبرگشت. حالا باید مستقل از تعداد ماه‌ها ثابت بماند.
         */
        $service = new ReportService($this->complex);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->trend('1405-06', months: 6);
        $sixMonths = count(DB::getQueryLog());

        DB::flushQueryLog();
        $service->trend('1405-06', months: 12);
        $twelveMonths = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(3, $sixMonths, 'نمودار روند باید سه کوئری بزند (پرداخت، درآمد، هزینه).');
        $this->assertSame($sixMonths, $twelveMonths, 'تعداد کوئری نباید با تعداد ماه‌ها رشد کند.');
    }

    public function test_the_trend_chart_still_returns_the_right_shape(): void
    {
        $service = new ReportService($this->complex);

        $trend = $service->trend('1405-06', months: 6);

        $this->assertCount(6, $trend['labels']);
        $this->assertCount(6, $trend['income']);
        $this->assertCount(6, $trend['expense']);
    }

    public function test_the_trend_chart_sums_income_from_both_sources(): void
    {
        // درآمد ماه = پرداخت موفق شارژ + درآمد متفرقه‌ی همان دوره
        DB::table('incomes')->insert([
            'complex_id' => $this->complex->id, 'title' => 'اجاره پارکینگ',
            'amount' => 250000, 'period' => '1405-06', 'received_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('expenses')->insert([
            'complex_id' => $this->complex->id, 'title' => 'نظافت', 'amount' => 100000,
            'category' => 'owner', 'period' => '1405-06', 'spend_date' => now(),
            'is_distributed' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $trend = (new ReportService($this->complex))->trend('1405-06', months: 3);

        // آخرین ماه همان دوره‌ی جاری است
        $this->assertSame(250000.0, end($trend['income']));
        $this->assertSame(100000.0, end($trend['expense']));
    }

    /* ------------------ ۲۰) نویسه‌های ویژه‌ی جستجو ------------------ */

    public function test_a_percent_sign_does_not_match_everything(): void
    {
        foreach (['الف', 'ب', 'ج'] as $i => $name) {
            Unit::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id, 'unit_number' => (string) ($i + 1),
                'floor' => 1, 'area' => 100, 'residents_count' => 2, 'coefficient' => 1,
                'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
                'notes' => $name,
            ]);
        }

        // «%%» پیش از این کل جدول را برمی‌گرداند
        $groups = $this->actingAs($this->admin)->getJson('/api/search?q=%25%25')
            ->assertOk()->json('groups');

        $this->assertSame([], $groups, 'نویسه‌ی wildcard نباید همه‌ی رکوردها را برگرداند.');
    }

    public function test_an_underscore_does_not_match_any_single_character(): void
    {
        Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '12',
            'floor' => 1, 'area' => 100, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->actingAs($this->admin)->getJson('/api/search?q=_2')
            ->assertOk()
            ->assertJsonPath('groups', []);
    }

    public function test_an_ordinary_search_still_works(): void
    {
        Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '101',
            'floor' => 1, 'area' => 100, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->actingAs($this->admin)->getJson('/api/search?q=101')
            ->assertOk()
            ->assertJsonPath('groups.0.id', 'units');
    }

    /* ------------------ ۲۱) دانلود بکاپ ------------------ */

    public function test_the_system_route_refuses_to_serve_a_complex_backup(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/c.json', '{}');

        $complexBackup = Backup::create([
            'complex_id' => $this->complex->id, 'type' => 'complex', 'status' => 'completed',
            'disk' => 'local', 'path' => 'backups/c.json', 'size' => 2,
        ]);

        // دو مسیر، دو سطح دسترسی؛ قاتی کردنشان بررسی مالکیت را دور می‌زد
        $this->actingAs($this->superAdmin)
            ->get("/api/system/backups/{$complexBackup->id}/download")
            ->assertNotFound();
    }

    public function test_the_system_route_serves_a_full_backup(): void
    {
        Storage::fake('local');

        $this->actingAs($this->superAdmin)->postJson('/api/system/backups')->assertStatus(201);
        $backup = Backup::where('type', 'full')->latest('id')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->get("/api/system/backups/{$backup->id}/download")
            ->assertOk();
    }

    /* ------------------ ۲۳) رسید روی اشتراک فعال ------------------ */

    public function test_a_complex_with_an_active_subscription_cannot_send_a_new_receipt(): void
    {
        Storage::fake('local');

        Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => 100, 'months' => 1,
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);

        /*
         * محافظ قبلی فقط جلوی درخواست دومِ «در انتظار» را می‌گرفت، پس مشتری
         * می‌توانست روی اشتراک فعال دوباره پول بفرستد و تاییدش دوره را
         * بازنویسی می‌کرد نه تمدید.
         */
        $this->actingAs($this->admin)->post('/api/subscription/receipt', [
            'plan' => 'pro',
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, Subscription::count());
    }

    public function test_a_complex_without_a_subscription_can_still_send_a_receipt(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->post('/api/subscription/receipt', [
            'plan' => 'pro',
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);
    }

    public function test_an_expired_subscription_does_not_block_renewal(): void
    {
        Storage::fake('local');

        Subscription::create([
            'complex_id' => $this->complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => 100, 'months' => 1,
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay(),
        ]);

        // دوره تمام شده، پس تمدید باید ممکن باشد
        $this->actingAs($this->admin)->post('/api/subscription/receipt', [
            'plan' => 'pro',
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);
    }
}
