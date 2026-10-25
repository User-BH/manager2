<?php

namespace Database\Seeders;

use App\Enums\AnnouncementAudience;
use App\Enums\ChargeRuleType;
use App\Enums\ComplexType;
use App\Enums\ExpenseCategory;
use App\Enums\OccupancyStatus;
use App\Enums\ResidentRelation;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Building;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Charge\BillGenerator;
use App\Support\Jalali;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * دادهٔ نمونه‌ی Preview طبقِ «داشبورد های آزمایشی» (مرحلهٔ ۱۳ · فاز ۱؛ پیامِ کارفرما ۲۰۲۶-۱۰-۱۹، بخش‌های ۲ تا ۴).
 *
 * چهار حسابِ آزمایشی، همه با نام کاربری و بی شماره‌ی موبایل (شماره‌ای رزرو نمی‌شود و پیامکی نمی‌رود):
 *
 *   demo.initial  ثبت‌نامِ اولیه، بی عضویت (بندِ ۲.۱)
 *   demo.unit     واحد: دقیقاً یک مجتمعِ مسکونی با پلنِ پیشرفته (بندِ ۲.۲)
 *   demo.manager  یک Account با پنج عضویتِ مستقل (بندِ ۲.۳):
 *                   الف مسکونی · مدیر · پایه      ب مسکونی · مدیر · حرفه‌ای     پ تجاری · مدیر · پیشرفته
 *                   ت تجاری · عضوِ واحد · پایه     ث مسکونی · عضوِ واحد · حرفه‌ای
 *   demo.admin    سوپر ادمینِ آزمایشی برای بررسیِ قابلیت‌های مدیریتی در Preview (پیامِ ۲۰۲۶-۱۰-۱۹، بخش ۲)
 *
 * پنج زمینه‌ی مدیر به‌علاوه‌ی واحدِ تک‌مجتمعی هر شش ترکیبِ نوع × پلن را پوشش می‌دهند (بندِ ۳).
 *
 * 🔴 قاعده‌ها:
 * - همه‌چیز `is_demo` می‌گیرد — نشانگرِ سمتِ سرور (بندِ ۴.۳)؛ هیچ مسیرِ API آن را نمی‌نویسد.
 * - **idempotent** (بندِ ۶.۳): اجرای دوباره چیزِ تکراری نمی‌سازد و رمزِ حسابی را که هست هرگز عوض نمی‌کند. هر چه
 *   نیست ساخته می‌شود؛ رمزِ حسابِ تازه تصادفی است و فقط یک بار به خروجیِ فرمان می‌رود (`issued()`) — نه در مخزن،
 *   نه در لاگ، نه در پایگاه‌داده جز به شکلِ hash.
 * - روی Production اجرا نمی‌شود.
 * - اشتراک‌ها «اهدای نمونه» با روشِ `manual` و مبلغِ صفرند: هیچ پرداخت، درگاه یا پولِ واقعی‌ای در کار نیست.
 */
class PreviewSeeder extends Seeder
{
    /** نام‌های کاربریِ چهار حساب — برای فرمان، آزمون و مستندات */
    public const INITIAL = 'demo.initial';

    public const UNIT = 'demo.unit';

    public const MANAGER = 'demo.manager';

    public const ADMIN = 'demo.admin';

    /**
     * پنج زمینه‌ی حسابِ مدیر (بندِ ۲.۳) و مجتمعِ تکِ حسابِ واحد (بندِ ۲.۲). `plan` همان slug ِ پلن‌های پیش‌فرض است:
     * basic = پایه، plus = حرفه‌ای، business = سطحِ سوم («پیشرفته» ِ اسناد).
     *
     * @var array<string, array{name: string, type: ComplexType, plan: string, manager: UserRole|null}>
     */
    public const CONTEXTS = [
        'demo-a' => ['name' => 'مجتمع مسکونی آزمایشی A', 'type' => ComplexType::Residential, 'plan' => 'basic', 'manager' => UserRole::ComplexAdmin],
        'demo-b' => ['name' => 'مجتمع مسکونی آزمایشی B', 'type' => ComplexType::Residential, 'plan' => 'plus', 'manager' => UserRole::ComplexAdmin],
        'demo-c' => ['name' => 'مجتمع تجاری آزمایشی C', 'type' => ComplexType::Commercial, 'plan' => 'business', 'manager' => UserRole::ComplexAdmin],
        'demo-d' => ['name' => 'مجتمع تجاری آزمایشی D', 'type' => ComplexType::Commercial, 'plan' => 'basic', 'manager' => UserRole::Owner],
        'demo-e' => ['name' => 'مجتمع مسکونی آزمایشی E', 'type' => ComplexType::Residential, 'plan' => 'plus', 'manager' => UserRole::Tenant],
        'demo-unit' => ['name' => 'مجتمع مسکونی آزمایشی (واحد)', 'type' => ComplexType::Residential, 'plan' => 'business', 'manager' => null],
        /*
         * 🔴 تعارضِ درونِ سند (بندِ ۲ در برابرِ بندِ ۳): پنج زمینه‌ی بندِ ۲.۳ و واحدِ بندِ ۲.۲ «مسکونی + حرفه‌ای» را دو
         * بار دارند و «تجاری + حرفه‌ای» را هیچ‌کدام؛ بندِ ۳ و DoD ولی هر شش ترکیب را قابلِ آزمایش می‌خواهند. شمارِ حساب و
         * زمینه دقیقاً همان سند می‌ماند و این ترکیب مجتمعِ ششمی با مدیرِ نمونه می‌گیرد که سوپر ادمینِ آزمایشی از «مدیریتِ
         * مجتمع‌ها» بازش می‌کند (docs/STAGE13_ROADMAP.md، پ-۵).
         */
        'demo-f' => ['name' => 'مجتمع تجاری آزمایشی F', 'type' => ComplexType::Commercial, 'plan' => 'plus', 'manager' => null],
    ];

    /** @var list<array{label: string, username: string, password: string}> */
    private array $issued = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('PreviewSeeder روی محصول اجرا نمی‌شود: دادهٔ آزمایشی فقط روی Preview است.');
        }

        $complexes = [];
        foreach (self::CONTEXTS as $slug => $spec) {
            $complexes[$slug] = Complex::where('slug', $slug)->first() ?? $this->buildComplex($slug, $spec);
        }

        $this->persona(self::ADMIN, 'سوپر ادمینِ آزمایشی', UserRole::SuperAdmin, null);
        $this->persona(self::INITIAL, 'حسابِ آزمایشیِ ثبت‌نامِ اولیه', UserRole::Owner, null);

        $unitAccount = $this->persona(self::UNIT, 'حسابِ آزمایشیِ واحد', UserRole::Owner, $complexes['demo-unit']);
        $this->ensureResident($unitAccount, $complexes['demo-unit'], UserRole::Owner, '1');

        $manager = $this->persona(self::MANAGER, 'حسابِ آزمایشیِ مدیر', UserRole::ComplexAdmin, $complexes['demo-a']);
        foreach (['demo-b', 'demo-c', 'demo-d', 'demo-e'] as $slug) {
            $role = self::CONTEXTS[$slug]['manager'];
            $this->ensureMembership($manager, $complexes[$slug], $role);
            if ($role !== UserRole::ComplexAdmin) {
                $this->ensureResident($manager, $complexes[$slug], $role, '1');
            }
        }

        // پیامِ گروه در سه زمینه‌ای که مدیرشان خودِ حسابِ آزمایشی است — یک بار
        foreach (['demo-a', 'demo-b', 'demo-c'] as $slug) {
            $complex = $complexes[$slug];
            if (! Message::withoutGlobalScopes()->where('complex_id', $complex->id)->exists()) {
                Message::create([
                    'complex_id' => $complex->id, 'user_id' => $manager->id, 'body' => 'پیامِ نمونه‌ی گروهِ مجتمع.',
                    'author_name' => $manager->name, 'author_role' => UserRole::ComplexAdmin->value, 'unit_label' => 'مدیریت',
                ]);
            }
        }
    }

    /**
     * رمزهای حساب‌هایی که همین اجرا ساخت — فقط برای چاپِ یک‌باره در خروجیِ فرمان.
     *
     * @return list<array{label: string, username: string, password: string}>
     */
    public function issued(): array
    {
        return $this->issued;
    }

    /** حسابِ آزمایشی؛ اگر هست دست نمی‌خورد (نه رمز، نه نام) */
    private function persona(string $username, string $name, UserRole $role, ?Complex $complex): User
    {
        $existing = User::withoutGlobalScopes()->where('username', $username)->first();
        if ($existing) {
            return $existing;
        }

        $password = Str::password(14, symbols: false);
        $user = (new User)->forceFill([
            'complex_id' => $complex?->id,
            'name' => $name,
            'username' => $username,
            'phone' => null,
            'password' => Hash::make($password),
            'role' => $role,
            'is_active' => true,
            'is_demo' => true,
            'terms_accepted_at' => now(),
        ]);
        $user->save();

        $this->issued[] = ['label' => $name, 'username' => $username, 'password' => $password];

        return $user;
    }

    /** عضویتِ فعال در مجتمعِ دیگر، بی جابه‌جاکردنِ زمینه‌ی فعلی */
    private function ensureMembership(User $user, Complex $complex, UserRole $role): void
    {
        $live = ComplexMembership::where('user_id', $user->id)
            ->where('complex_id', $complex->id)
            ->where('status', ComplexMembership::ACTIVE)
            ->exists();

        if (! $live) {
            app(MembershipLedger::class)->start($user, $complex, $role, ComplexMembership::SOURCE_DIRECT, activate: false);
        }
    }

    /** رابطه‌ی مالک یا مستأجر با واحد؛ یک بار */
    private function ensureResident(User $user, Complex $complex, UserRole $role, string $unitNumber): void
    {
        $unit = Unit::withoutGlobalScopes()->where('complex_id', $complex->id)->where('unit_number', $unitNumber)->firstOrFail();
        $relation = $role === UserRole::Tenant ? ResidentRelation::Tenant : ResidentRelation::Owner;

        if ($unit->residents()->where('users.id', $user->id)->exists()) {
            return;
        }

        $unit->residents()->attach($user->id, [
            'complex_id' => $complex->id,
            'relation' => $relation->value,
            'share_percent' => 100,
            'is_current' => true,
            'start_date' => now()->subMonths(3),
        ]);
    }

    /**
     * مجتمعِ نمونه با دادهٔ کافی برای بررسیِ قابلیت‌های واقعی (بندِ ۶.۱): مدیرِ نمونه (برای زمینه‌هایی که حسابِ
     * مدیر در آن‌ها عضوِ واحد است)، ساختمان، چهار واحد با مالک و مستأجر، قوانینِ شارژ، صورتحساب، هزینه، درآمد،
     * اطلاعیه و پیامِ گروه، و اشتراکِ نمونه.
     *
     * @param  array{name: string, type: ComplexType, plan: string, manager: UserRole|null}  $spec
     */
    private function buildComplex(string $slug, array $spec): Complex
    {
        $complex = (new Complex)->forceFill([
            'name' => $spec['name'],
            'slug' => $slug,
            'type' => $spec['type'],
            'address' => 'نشانیِ نمونه — دادهٔ آزمایشی',
            'currency' => 'toman',
            'payment_gateway' => 'fake',
            'charge_due_day' => 10,
            'is_demo' => true,
        ]);
        $complex->save();

        // حسابِ مدیر در «الف» تا «پ» مدیر است؛ در بقیه یک مدیرِ نمونه لازم است تا مجتمع بی‌مدیر نماند
        $admin = $spec['manager'] === UserRole::ComplexAdmin
            ? null
            : $this->sample($complex, 'مدیرِ نمونه‌ی '.$spec['name'], UserRole::ComplexAdmin);

        $building = Building::create(['complex_id' => $complex->id, 'name' => 'بلوک A', 'floors_count' => 3]);

        $units = [
            ['no' => '1', 'floor' => 0, 'area' => 80, 'persons' => 2, 'status' => OccupancyStatus::OwnerOccupied],
            ['no' => '2', 'floor' => 1, 'area' => 95, 'persons' => 3, 'status' => OccupancyStatus::Rented],
            ['no' => '3', 'floor' => 2, 'area' => 110, 'persons' => 2, 'status' => OccupancyStatus::OwnerOccupied],
            ['no' => '4', 'floor' => 3, 'area' => 125, 'persons' => 4, 'status' => OccupancyStatus::Rented],
        ];

        foreach ($units as $spec2) {
            $unit = Unit::create([
                'complex_id' => $complex->id,
                'building_id' => $building->id,
                'unit_number' => $spec2['no'],
                'floor' => $spec2['floor'],
                'area' => $spec2['area'],
                'residents_count' => $spec2['persons'],
                'parking_count' => 1,
                'occupancy_status' => $spec2['status'],
                'coefficient' => 1.0,
                'uses_elevator' => $spec2['floor'] > 0,
            ]);

            // واحدِ ۱ مالِ حساب‌های آزمایشی است (واحدِ تک‌مجتمعی، و «ت» و «ث» ِ حسابِ مدیر)؛ بقیه ساکنِ نمونه دارند
            if ($spec2['no'] === '1' && in_array($slug, ['demo-d', 'demo-e', 'demo-unit'], true)) {
                continue;
            }

            $owner = $this->sample($complex, "مالکِ نمونه‌ی واحد {$spec2['no']}", UserRole::Owner);
            $unit->residents()->attach($owner->id, [
                'complex_id' => $complex->id, 'relation' => ResidentRelation::Owner->value,
                'share_percent' => 100, 'is_current' => true, 'start_date' => now()->subYear(),
            ]);

            if ($spec2['status'] === OccupancyStatus::Rented) {
                $tenant = $this->sample($complex, "مستأجرِ نمونه‌ی واحد {$spec2['no']}", UserRole::Tenant);
                $unit->residents()->attach($tenant->id, [
                    'complex_id' => $complex->id, 'relation' => ResidentRelation::Tenant->value,
                    'share_percent' => 100, 'is_current' => true, 'start_date' => now()->subMonths(6),
                ]);
            }
        }

        ChargeRule::create([
            'complex_id' => $complex->id, 'name' => 'شارژ ثابت پایه', 'type' => ChargeRuleType::Fixed,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 250000], 'sort_order' => 1,
        ]);
        ChargeRule::create([
            'complex_id' => $complex->id, 'name' => 'نظافت بر اساس متراژ', 'type' => ChargeRuleType::PerArea,
            'category' => ExpenseCategory::Tenant, 'config' => ['amount' => 1500], 'sort_order' => 2,
        ]);

        $period = Jalali::currentPeriod();
        Expense::create([
            'complex_id' => $complex->id, 'title' => 'سرویسِ نمونه‌ی آسانسور', 'amount' => 900000,
            'category' => ExpenseCategory::Tenant, 'period' => $period, 'spend_date' => now(),
            'is_distributed' => false, 'created_by' => $admin?->id,
        ]);
        Income::create([
            'complex_id' => $complex->id, 'title' => 'درآمدِ نمونه', 'amount' => 1200000,
            'source' => 'دادهٔ آزمایشی', 'period' => $period, 'received_date' => now(),
        ]);

        app(BillGenerator::class)->generate($complex, $period);

        Announcement::create([
            'complex_id' => $complex->id, 'title' => 'اطلاعیه‌ی نمونه',
            'body' => 'این اطلاعیه دادهٔ آزمایشیِ Preview است.',
            'audience' => AnnouncementAudience::All, 'is_active' => true,
            'published_at' => now(), 'created_by' => $admin?->id,
        ]);

        if ($admin) {
            Message::create([
                'complex_id' => $complex->id, 'user_id' => $admin->id, 'body' => 'پیامِ نمونه‌ی گروهِ مجتمع.',
                'author_name' => $admin->name, 'author_role' => UserRole::ComplexAdmin->value, 'unit_label' => 'مدیریت',
            ]);
        }

        $plan = Plan::where('slug', $spec['plan'])->first();
        if ($plan) {
            Subscription::create([
                'complex_id' => $complex->id,
                'user_id' => $admin?->id,
                'plan' => SubscriptionPlan::Pro->value,
                'plan_id' => $plan->id,
                'status' => 'active',
                'method' => 'manual',
                'amount' => 0,
                'months' => 12,
                'starts_at' => now(),
                'ends_at' => now()->addYear(),
                'paid_at' => now(),
                'review_note' => 'اشتراکِ نمونه‌ی Preview — بی پرداخت',
            ]);
        }

        return $complex;
    }

    /** شخصِ ساختگی: بی نام کاربری، بی شماره، با رمزِ تصادفیِ دورریختنی — وارد نمی‌شود */
    private function sample(Complex $complex, string $name, UserRole $role): User
    {
        $user = (new User)->forceFill([
            'complex_id' => $complex->id,
            'name' => $name,
            'phone' => null,
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->save();

        return $user;
    }
}
