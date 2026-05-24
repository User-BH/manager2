<?php

namespace Database\Seeders;

use App\Enums\AnnouncementAudience;
use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Charge\BillGenerator;
use App\Support\Jalali;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $period = Jalali::currentPeriod();
        $prevPeriod = Jalali::shiftPeriod($period, -1);

        // --- System super-admin (not bound to any complex) ---
        User::create([
            'name' => 'مدیر سیستم',
            'email' => 'admin@system.test',
            'phone' => '09120000001',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        // --- Complex ---
        $complex = Complex::create([
            'name' => 'مجتمع مسکونی آفتاب',
            'slug' => 'aftab',
            'address' => 'تهران، خیابان نمونه، پلاک ۱۲',
            'phone' => '02112345678',
            'currency' => 'toman',
            'payment_gateway' => 'fake',
            'charge_due_day' => 10,
            'penalty_enabled' => true,
            'penalty_type' => 'percent_per_day',
            'penalty_value' => 0.5,
            'penalty_grace_days' => 5,
            'good_payer_enabled' => true,
        ]);

        $admin = User::create([
            'complex_id' => $complex->id,
            'name' => 'مدیر ساختمان آفتاب',
            'email' => 'manager@aftab.test',
            'phone' => '09120000000',
            'password' => Hash::make('password'),
            'role' => UserRole::ComplexAdmin,
        ]);

        $building = Building::create([
            'complex_id' => $complex->id,
            'name' => 'بلوک A',
            'floors_count' => 4,
        ]);

        // --- Units with residents ---
        $unitSpecs = [
            ['no' => '1', 'floor' => 0, 'area' => 75, 'persons' => 2, 'coef' => 1.0, 'status' => OccupancyStatus::OwnerOccupied],
            ['no' => '2', 'floor' => 1, 'area' => 90, 'persons' => 3, 'coef' => 1.2, 'status' => OccupancyStatus::Rented],
            ['no' => '3', 'floor' => 1, 'area' => 90, 'persons' => 1, 'coef' => 1.2, 'status' => OccupancyStatus::OwnerOccupied],
            ['no' => '4', 'floor' => 2, 'area' => 110, 'persons' => 4, 'coef' => 1.4, 'status' => OccupancyStatus::Rented],
            ['no' => '5', 'floor' => 3, 'area' => 130, 'persons' => 2, 'coef' => 1.6, 'status' => OccupancyStatus::OwnerOccupied],
            ['no' => '6', 'floor' => 3, 'area' => 130, 'persons' => 5, 'coef' => 1.6, 'status' => OccupancyStatus::Rented],
        ];

        foreach ($unitSpecs as $i => $spec) {
            $unit = Unit::create([
                'complex_id' => $complex->id,
                'building_id' => $building->id,
                'unit_number' => $spec['no'],
                'floor' => $spec['floor'],
                'area' => $spec['area'],
                'residents_count' => $spec['persons'],
                'parking_count' => 1,
                'occupancy_status' => $spec['status'],
                'coefficient' => $spec['coef'],
                'uses_elevator' => $spec['floor'] > 0,
            ]);

            $n = $i + 1;
            $owner = User::create([
                'complex_id' => $complex->id,
                'name' => "مالک واحد {$spec['no']}",
                'email' => "owner{$n}@aftab.test",
                'phone' => '0912111110'.$n,
                'password' => Hash::make('password'),
                'role' => UserRole::Owner,
            ]);
            $unit->residents()->attach($owner->id, [
                'complex_id' => $complex->id,
                'relation' => ResidentRelation::Owner->value,
                'share_percent' => 100,
                'is_current' => true,
                'start_date' => now()->subYear(),
            ]);

            if ($spec['status'] === OccupancyStatus::Rented) {
                $tenant = User::create([
                    'complex_id' => $complex->id,
                    'name' => "مستاجر واحد {$spec['no']}",
                    'email' => "tenant{$n}@aftab.test",
                    'phone' => '0912222220'.$n,
                    'password' => Hash::make('password'),
                    'role' => UserRole::Tenant,
                ]);
                $unit->residents()->attach($tenant->id, [
                    'complex_id' => $complex->id,
                    'relation' => ResidentRelation::Tenant->value,
                    'share_percent' => 100,
                    'is_current' => true,
                    'start_date' => now()->subMonths(6),
                ]);
            }
        }

        // --- Standing charge rules ---
        ChargeRule::create([
            'complex_id' => $complex->id, 'name' => 'شارژ ثابت پایه', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 300000], 'sort_order' => 1,
        ]);
        ChargeRule::create([
            'complex_id' => $complex->id, 'name' => 'نظافت بر اساس متراژ', 'type' => ChargeRuleType::PerArea,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 2000], 'sort_order' => 2,
        ]);
        ChargeRule::create([
            'complex_id' => $complex->id, 'name' => 'نگهبانی بر اساس نفرات', 'type' => ChargeRuleType::PerPerson,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 80000], 'sort_order' => 3,
        ]);
        ChargeRule::create([
            'complex_id' => $complex->id, 'name' => 'اندوخته تعمیرات (مالکانه)', 'type' => ChargeRuleType::ByCoefficient,
            'category' => ExpenseCategory::Owner, 'pool_amount' => 3000000, 'sort_order' => 4,
        ]);

        // --- Period expenses (some distributed to units) ---
        Expense::create([
            'complex_id' => $complex->id, 'title' => 'قبض آب مشترک', 'amount' => 1800000,
            'category' => ExpenseCategory::Tenant, 'period' => $period, 'spend_date' => now(),
            'split_method' => ChargeRuleType::UtilityByPerson, 'is_distributed' => true,
            'created_by' => $admin->id,
        ]);
        Expense::create([
            'complex_id' => $complex->id, 'title' => 'سرویس و نگهداری آسانسور', 'amount' => 1200000,
            'category' => ExpenseCategory::Tenant, 'period' => $period, 'spend_date' => now(),
            'split_method' => ChargeRuleType::ElevatorByFloor, 'split_config' => ['exempt_ground_floor' => true],
            'is_distributed' => true, 'created_by' => $admin->id,
        ]);
        Expense::create([
            'complex_id' => $complex->id, 'title' => 'حقوق نظافتچی', 'amount' => 2500000,
            'category' => ExpenseCategory::Tenant, 'period' => $period, 'spend_date' => now(),
            'is_distributed' => false, 'created_by' => $admin->id,
        ]);

        Income::create([
            'complex_id' => $complex->id, 'title' => 'اجاره بهای پشت‌بام (آنتن)', 'amount' => 2000000,
            'source' => 'اپراتور مخابراتی', 'period' => $period, 'received_date' => now(),
        ]);

        // --- Generate bills for current + previous period ---
        $generator = app(BillGenerator::class);
        $generator->generate($complex, $prevPeriod);
        $generator->generate($complex, $period);

        // --- Sample payments: one paid online, one receipt pending ---
        $firstUnit = $complex->units()->orderBy('unit_number')->first();
        $prevBill = $firstUnit->bills()->where('period', $prevPeriod)->first();
        if ($prevBill) {
            $payment = Payment::create([
                'complex_id' => $complex->id, 'unit_id' => $firstUnit->id, 'bill_id' => $prevBill->id,
                'user_id' => $firstUnit->owners()->first()?->id,
                'amount' => $prevBill->total_amount, 'method' => PaymentMethod::Online,
                'status' => PaymentStatus::Success, 'period' => $prevPeriod,
                'gateway' => 'fake', 'ref_id' => 'FAKE-DEMO-0001', 'tracking_code' => 'TRK-DEMO0001',
                'paid_at' => now()->subDays(20),
            ]);
            $prevBill->paid_amount = $prevBill->total_amount;
            $prevBill->syncStatus();
        }

        $secondUnit = $complex->units()->orderBy('unit_number')->skip(1)->first();
        $curBill = $secondUnit?->bills()->where('period', $period)->first();
        if ($curBill) {
            Payment::create([
                'complex_id' => $complex->id, 'unit_id' => $secondUnit->id, 'bill_id' => $curBill->id,
                'user_id' => $secondUnit->tenants()->first()?->id ?? $secondUnit->owners()->first()?->id,
                'amount' => $curBill->total_amount, 'method' => PaymentMethod::Receipt,
                'status' => PaymentStatus::Pending, 'period' => $period,
                'receipt_paid_on' => now(), 'description' => 'واریز به حساب مدیر',
            ]);
        }

        // --- Announcements ---
        Announcement::create([
            'complex_id' => $complex->id, 'title' => 'قطعی آب در روز پنجشنبه',
            'body' => 'به اطلاع می‌رساند به دلیل تعمیرات، آب ساختمان روز پنجشنبه از ساعت ۹ تا ۱۲ قطع خواهد بود.',
            'audience' => AnnouncementAudience::All, 'is_active' => true, 'is_pinned' => true,
            'published_at' => now(), 'created_by' => $admin->id,
        ]);
        Announcement::create([
            'complex_id' => $complex->id, 'title' => 'جلسه هیئت مدیره (ویژه مالکین)',
            'body' => 'جلسه ماهانه هیئت مدیره روز جمعه ساعت ۱۸ در لابی برگزار می‌شود.',
            'audience' => AnnouncementAudience::Owners, 'is_active' => true,
            'published_at' => now()->subDays(2), 'created_by' => $admin->id,
        ]);

        // --- Messenger sample ---
        Message::create([
            'complex_id' => $complex->id, 'user_id' => $admin->id, 'body' => 'سلام به همه ساکنین عزیز. لطفا پیشنهادات خود را در همین بخش مطرح کنید.',
            'author_name' => $admin->name, 'author_role' => UserRole::ComplexAdmin->value, 'unit_label' => 'مدیریت',
        ]);
    }
}
