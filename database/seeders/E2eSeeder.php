<?php

namespace Database\Seeders;

use App\Enums\ComplexType;
use App\Enums\OccupancyStatus;
use App\Enums\PlanTier;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\AssistantMessage;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Expense;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Blog\BlogImporter;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * دادهٔ تکمیلیِ آزمون‌های سرتاسری، پس از `DemoSeeder` (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۴).
 *
 * سفرِ «پرداخت از کیفِ پول» در مرورگرِ واقعی موجودیِ کیف لازم دارد، و کیف در
 * این سامانه فقط از اضافه‌پرداخت یا اصلاحِ مدیر پر می‌شود — نه از فرمی که
 * آزمون بتواند بزند. پس اینجا، مثلِ `otp_disabled` در CI، پیش‌شرط ساخته
 * می‌شود.
 *
 * ⚠️ `DemoSeeder` عمداً دست نخورد: دادهٔ نمایشی است و کیفِ پُر در آن معنای
 * نمایشیِ غلطی می‌ساخت.
 *
 * 🔴 از **خودِ `WalletService`** می‌گذرد، نه درجِ مستقیم در جدول: همان مسیری
 * که دفترِ کیف و موجودی را هم‌خوان نگه می‌دارد.
 */
class E2eSeeder extends Seeder
{
    /** همان مالک‌های سه موتور در `tests/e2e/financial-journey.spec.ts`. */
    public const OWNERS = ['09121111102', '09121111103', '09121111104'];

    /**
     * ثبت‌نام‌کننده‌ی اولیه‌ی هر موتور در `tests/e2e/registrant-journey.spec.ts`
     * (مرحلهٔ ۸ · فاز ۱).
     *
     * ⚠️ با API ِ ثبت‌نام ساخته نمی‌شوند: سقفِ `register` پنج در ساعت برای هر
     * IP است و `management-journey` خودش سه تا از آن را می‌خورد — در CI ششمی
     * ۴۲۹ گرفت. فرمِ ثبت‌نام همان‌جا پوشش دارد؛ این‌جا فقط حسابِ حاصلش لازم است.
     *
     * سفر آن‌ها را عضو می‌کند؛ اجرای دوباره‌ی همین seeder دوباره بی‌مجتمعشان
     * می‌کند تا سفر روی همان پایگاه‌داده تکرارپذیر باشد.
     */
    public const REGISTRANTS = ['09120000021', '09120000022', '09120000023'];

    /** حسابِ اولیه برای خریدِ آنلاینِ اشتراک و ساختِ مجتمع (مرحلهٔ ۹ · فاز ۴) — یکی برای هر موتور */
    public const BUYERS = ['09120000041', '09120000042', '09120000043'];

    /**
     * حسابِ عضوِ دو مجتمع برای تعویضِ مجتمع (مرحلهٔ ۱۰ · فاز ۴) — یکی برای هر موتور:
     * مستأجرِ «آفتاب» و مدیرِ «مجتمعِ دومِ سرتاسری». زمینه‌ی شروع: آفتاب.
     */
    public const MULTI = ['09120000061', '09120000062', '09120000063'];

    /**
     * دو دستگاهِ یک حساب (مرحلهٔ ۱۲ · فاز ۱) — مثلِ `MULTI`، ولی جدا: سفرِ `context-switch` پیش‌فرضِ همان حساب‌ها را
     * جابه‌جا می‌کند و آزمونِ «تعویض روی یک دستگاه دیگری را جابه‌جا نمی‌کند» با آن قاطی می‌شد.
     */
    public const TWO_DEVICES = ['09120000065', '09120000066', '09120000067'];

    /**
     * صفحه‌ی انتخابِ مجتمع و «ایجادِ حسابِ جدید» (مرحلهٔ ۱۳ · فاز ۳) — مثلِ `MULTI`، ولی جدا: سفرِ `multi-community`
     * سنجاقِ مرورگر را به زمینه‌ی بی‌مجتمع می‌برد و با سفرهای تعویض قاطی نمی‌شود.
     */
    public const CHOOSERS = ['09120000111', '09120000112', '09120000113'];

    /**
     * تقویم و یادآور (مرحلهٔ ۱۳ · فاز ۷) — مثلِ `MULTI`، ولی جدا: سفرِ `calendar` یادآور می‌سازد و زمینه را عوض می‌کند. هر
     * اجرای seeder یادآورهای همین حساب‌ها را پاک می‌کند تا سفر از تقویمِ خالی شروع شود.
     */
    public const CALENDAR = ['09120000131', '09120000132', '09120000133'];

    /** مجتمعِ دومِ آزمون — فقط برای حساب‌های چندمجتمعی */
    public const SECOND_COMPLEX = 'مجتمع دوم سرتاسری';

    /**
     * دستیارِ داخلی (مرحلهٔ ۱۳ · فاز ۸) — مستأجرِ «آفتاب» (بی پلنِ پیشرفته: بی دستیار) و مدیرِ «مجتمع دستیار سرتاسری» با
     * اشتراکِ فعالِ پیشرفته و یک هزینه‌ی این ماه، تا سفر هر دو سوی دروازه و یک پاسخِ واقعی را ببیند. گفت‌وگوی قبلی پاک.
     */
    public const ASSISTANT = ['09120000141', '09120000142', '09120000143'];

    public const ASSISTANT_COMPLEX = 'مجتمع دستیار سرتاسری';

    /**
     * QR ِ ورودِ عمومی (مرحلهٔ ۱۰ · فاز ۵) — برای هر موتور یک مجتمعِ جدا با مدیرِ خودش، و یک حسابِ اولیه که
     * با QR درخواست می‌دهد. مجتمعِ جدا چون «QR ِ تازه» ِ یک موتور QR ِ موتورِ دیگر را باطل می‌کرد.
     */
    public const QR_MANAGERS = ['09120000081', '09120000082', '09120000083'];

    public const QR_JOINERS = ['09120000071', '09120000072', '09120000073'];

    public const QR_COMPLEX = 'مجتمع QR سرتاسری';

    /**
     * حضورِ آنلاین («چند نکته»، بندِ ۳) — برای هر موتور یک «دیده‌شونده» و یک «بیننده»، هر دو مستأجرِ آفتاب.
     * حضور روی حساب است؛ حسابِ مشترک با سفرِ دیگری که هم‌زمان درخواست می‌زند «آفلاین» را می‌شکست.
     */
    public const PRESENCE_SUBJECTS = ['09120000091', '09120000092', '09120000093'];

    public const PRESENCE_VIEWERS = ['09120000094', '09120000095', '09120000096'];

    /**
     * لایه‌ی باکسِ اعلان روی ماشین‌حساب («چند نکته»، بندِ ۵) — یکی برای هر موتور. پیش‌تر مدیرِ نمایشی بود و سه ورودِ
     * دیگر روی همان شماره، کنارِ پنج سفرِ دیگر، از سقفِ پنج ورود در دقیقه گذشت (۴۲۹ در CI ِ `edd7c9a`).
     */
    public const LAYER_MEMBERS = ['09120000097', '09120000098', '09120000099'];

    /**
     * نوارِ بالا و پنلِ جست‌وجو (مرحلهٔ ۱۳ · فاز ۴) — برای هر موتور یک حسابِ اولیه و یک مستأجرِ آفتاب، جدا از سفرهای
     * دیگر که همین حساب‌ها را عضو یا خریدار می‌کنند. تاریخچه‌ی جست‌وجو مالِ حساب است و با حسابِ مشترک قاطی می‌شد.
     */
    public const TOP_BAR_INITIAL = ['09120000121', '09120000122', '09120000123'];

    public const TOP_BAR_UNITS = ['09120000124', '09120000125', '09120000126'];

    /**
     * اعلانِ دعوت (مرحلهٔ ۱۳ · فاز ۵) — برای هر موتور مستأجرِ آفتاب که مدیرِ «مجتمع دوم سرتاسری» دعوتش کرده، با همان
     * `Membership::invite` ِ واقعی (و همان اعلانِ حسابی). هر اجرا دعوت و اعلان را از نو می‌سازد.
     */
    public const INVITEES = ['09120000127', '09120000128', '09120000129'];

    /** بیش از هر قبضِ نمایشی، تا کیف قبض را کامل بپردازد نه بخشی از آن. */
    public const CREDIT = 5_000_000;

    public function run(WalletService $wallet, BlogImporter $importer): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('E2eSeeder روی محصول اجرا نمی‌شود.');
        }

        foreach (self::OWNERS as $phone) {
            $owner = User::withoutGlobalScopes()->where('phone', $phone)->first();

            if (! $owner) {
                throw new RuntimeException("مالکِ {$phone} نیست؛ اول DemoSeeder را اجرا کنید.");
            }

            $unit = $owner->units()->withoutGlobalScopes()->first();

            // دوباره‌اجرا اعتبار را دوبرابر نمی‌کند — کیف مالِ خودِ مالک است، نه واحد
            if ($unit === null || $wallet->balance($owner) >= self::CREDIT) {
                continue;
            }

            $wallet->credit($owner, $unit, self::CREDIT, WalletTransaction::SOURCE_ADJUSTMENT, null, 'اعتبارِ آزمونِ سرتاسری');
        }

        foreach ([...self::REGISTRANTS, ...self::TOP_BAR_INITIAL] as $phone) {
            $this->freshRegistrant($phone);
        }

        foreach (self::BUYERS as $phone) {
            $this->freshRegistrant($phone);
            // خریدار با نام و نام خانوادگیِ جدا، مثلِ هر ثبت‌نامِ تازه
            User::withoutGlobalScopes()->where('phone', $phone)->first()?->forceFill([
                'first_name' => 'خریدار',
                'last_name' => 'سرتاسری',
            ])->save();
        }

        /*
         * مقاله‌های واقعیِ بلاگ (مرحلهٔ ۷ · فاز ۷) — همان که استقرار وارد می‌کند؛
         * سفرهای صفحه‌بندی، جست‌وجو و بازکردنِ مقاله بی‌آن‌ها چیزی برای سنجیدن
         * نداشتند.
         */
        $this->multiMembers();
        $this->qrComplexes();
        $this->perEngineMembers();
        $this->invitees();

        $importer->import();
    }

    /**
     * حساب‌های چندمجتمعی از نو: هر عضویتِ قبلی پایان می‌یابد (تاریخچه می‌ماند)، سپس مدیرِ
     * مجتمعِ دوم و مستأجرِ آفتاب — به همین ترتیب، تا زمینه‌ی شروع آفتاب باشد.
     */
    private function multiMembers(): void
    {
        $aftab = Complex::where('slug', 'aftab')->first()
            ?? throw new RuntimeException('مجتمعِ «آفتاب» نیست؛ اول DemoSeeder را اجرا کنید.');

        $second = Complex::firstOrCreate(['slug' => 'e2e-second'], [
            'name' => self::SECOND_COMPLEX,
            'type' => ComplexType::Residential,
            'address' => 'نشانیِ آزمونِ سرتاسری',
            'payment_gateway' => 'none',
        ]);
        Unit::withoutGlobalScopes()->firstOrCreate(
            ['complex_id' => $second->id, 'unit_number' => 'دوم-۱'],
            ['floor' => 1, 'area' => 90, 'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::Vacant],
        );

        foreach (['چندمجتمعی' => self::MULTI, 'دودستگاهی' => self::TWO_DEVICES, 'انتخاب‌کننده' => self::CHOOSERS, 'تقویمی' => self::CALENDAR] as $firstName => $phones) {
            foreach ($phones as $i => $phone) {
                $this->inTwoCommunities($phone, $firstName, 'سرتاسری '.($i + 1), $second, $aftab);
            }
        }
        Reminder::whereIn('user_id', User::withoutGlobalScopes()->whereIn('phone', self::CALENDAR)->select('id'))->delete();

        $assistant = $this->assistantComplex();
        foreach (self::ASSISTANT as $i => $phone) {
            $this->inTwoCommunities($phone, 'دستیاری', 'سرتاسری '.($i + 1), $assistant, $aftab);
        }
        AssistantMessage::whereIn('user_id', User::withoutGlobalScopes()->whereIn('phone', self::ASSISTANT)->select('id'))->delete();
    }

    /** مجتمعی با اشتراکِ فعالِ پیشرفته (همان پلنِ پیش‌فرضِ مهاجرتِ ۱۳/۶) و یک هزینه‌ی ماهِ جاری */
    private function assistantComplex(): Complex
    {
        $complex = Complex::firstOrCreate(['slug' => 'e2e-assistant'], [
            'name' => self::ASSISTANT_COMPLEX,
            'type' => ComplexType::Residential,
            'address' => 'نشانیِ آزمونِ سرتاسری',
            'payment_gateway' => 'none',
        ]);
        $plan = Plan::where('tier', PlanTier::Advanced->value)->where('is_active', true)->orderBy('id')->first()
            ?? throw new RuntimeException('پلنِ پیشرفته نیست؛ مهاجرت‌های ۱۳/۶ را اجرا کنید.');

        Subscription::withoutGlobalScopes()->updateOrCreate(
            ['complex_id' => $complex->id, 'review_note' => 'اشتراکِ آزمونِ سرتاسری'],
            ['plan' => SubscriptionPlan::Pro->value, 'plan_id' => $plan->id, 'status' => 'active', 'method' => 'manual', 'amount' => 0,
                'months' => 12, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'paid_at' => now()],
        );
        Expense::withoutGlobalScopes()->updateOrCreate(
            ['complex_id' => $complex->id, 'title' => 'هزینه‌ی آزمونِ دستیار'],
            ['amount' => 420_000, 'category' => 'owner', 'period' => Jalali::currentPeriod(), 'spend_date' => now(), 'is_distributed' => false],
        );

        return $complex;
    }

    /** یک حساب از نو در دو مجتمع: عضویت‌های قبلی پایان (تاریخچه می‌ماند)، سپس مدیرِ مجتمعِ دوم و مستأجرِ آفتاب */
    private function inTwoCommunities(string $phone, string $firstName, string $lastName, Complex $second, Complex $aftab): void
    {
        $ledger = app(MembershipLedger::class);
        $user = User::withoutGlobalScopes()->firstOrNew(['phone' => $phone]);
        while ($user->exists && $user->complex_id !== null) {
            $ledger->end($user, ComplexMembership::END_REMOVED);
        }
        $user->fill([
            'complex_id' => null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => Hash::make('password'),
            'role' => UserRole::Owner,
            'is_active' => true,
            'terms_accepted_at' => now(),
        ])->save();

        $ledger->start($user, $second, UserRole::ComplexAdmin, ComplexMembership::SOURCE_DIRECT);
        $ledger->start($user, $aftab, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
    }

    /**
     * مجتمعِ QR ِ هر موتور از نو: کدِ QR خاموش (سفر خودش «ساختِ QR» را می‌زند)، یک واحدِ خالی، مدیری که فقط
     * همین مجتمع را دارد، و درخواست‌کننده‌ای که دوباره حسابِ اولیه است.
     */
    private function qrComplexes(): void
    {
        $ledger = app(MembershipLedger::class);

        foreach (self::QR_MANAGERS as $i => $phone) {
            $complex = Complex::firstOrCreate(['slug' => 'e2e-qr-'.($i + 1)], [
                'name' => self::QR_COMPLEX.' '.($i + 1),
                'type' => ComplexType::Residential,
                'address' => 'نشانیِ آزمونِ سرتاسری',
                'payment_gateway' => 'none',
            ]);
            $complex->forceFill(['join_code' => null, 'join_code_created_at' => null])->save();
            Unit::withoutGlobalScopes()->firstOrCreate(
                ['complex_id' => $complex->id, 'unit_number' => 'QR-1'],
                ['floor' => 1, 'area' => 80, 'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => OccupancyStatus::Vacant],
            );

            $manager = User::withoutGlobalScopes()->firstOrNew(['phone' => $phone]);
            while ($manager->exists && $manager->complex_id !== null) {
                $ledger->end($manager, ComplexMembership::END_REMOVED);
            }
            $manager->fill([
                'complex_id' => null,
                'first_name' => 'مدیرِ QR',
                'last_name' => 'سرتاسری '.($i + 1),
                'password' => Hash::make('password'),
                'role' => UserRole::Owner,
                'is_active' => true,
                'terms_accepted_at' => now(),
            ])->save();
            $ledger->start($manager, $complex, UserRole::ComplexAdmin, ComplexMembership::SOURCE_DIRECT);

            // سفرِ قبلی درخواست‌کننده را مستأجرِ واحدِ QR کرده بود: دوره‌اش بسته و دوباره حسابِ اولیه
            $joiner = User::withoutGlobalScopes()->where('phone', self::QR_JOINERS[$i])->first();
            if ($joiner !== null) {
                app(TenureService::class)->endAllFor($joiner, null, $complex->id);
            }
            $this->freshRegistrant(self::QR_JOINERS[$i]);
        }
    }

    /** حساب‌های تک‌موتوری (حضور، لایه و نوارِ بالا): مستأجرِ آفتاب بی واحد (عضوِ گروهِ مجتمع)، آفلاین در آغاز */
    private function perEngineMembers(): void
    {
        $aftab = Complex::where('slug', 'aftab')->firstOrFail();
        $ledger = app(MembershipLedger::class);
        $names = ['حاضر', 'بیننده', 'لایه', 'نوار', 'دعوت‌شده'];

        foreach ([...self::PRESENCE_SUBJECTS, ...self::PRESENCE_VIEWERS, ...self::LAYER_MEMBERS, ...self::TOP_BAR_UNITS, ...self::INVITEES] as $i => $phone) {
            $user = User::withoutGlobalScopes()->firstOrNew(['phone' => $phone]);
            while ($user->exists && $user->complex_id !== null) {
                $ledger->end($user, ComplexMembership::END_REMOVED);
            }
            $user->fill([
                'complex_id' => null,
                'first_name' => $names[intdiv($i, 3)],
                'last_name' => 'سرتاسری '.($i % 3 + 1),
                'password' => Hash::make('password'),
                'role' => UserRole::Owner,
                'is_active' => true,
                'terms_accepted_at' => now(),
            ])->save();
            $user->forceFill(['last_seen_at' => null])->save();

            $ledger->start($user, $aftab, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);
        }
    }

    /** دعوتِ تازه‌ی مدیرِ مجتمعِ دوم برای حساب‌های `INVITEES` — بی اعلان و دعوتِ اجرای قبلی */
    private function invitees(): void
    {
        $second = Complex::where('slug', 'e2e-second')->firstOrFail();
        $inviter = User::withoutGlobalScopes()->where('phone', self::MULTI[0])->firstOrFail();

        foreach (self::INVITEES as $phone) {
            $user = User::withoutGlobalScopes()->where('phone', $phone)->firstOrFail();
            $user->notifications()->delete();
            ComplexInvitation::withoutGlobalScopes()->where('phone', $phone)->delete();

            app(Membership::class)->invite($second, $phone, UserRole::ComplexAdmin, null, $inviter);
        }
    }

    /** همان حسابی که `AuthController::register` می‌سازد: مالکِ بی‌مجتمع، بی‌واحد، بی‌دعوت */
    private function freshRegistrant(string $phone): void
    {
        $user = User::withoutGlobalScopes()->firstOrNew(['phone' => $phone]);

        // سفرِ پیشین عضوش کرده بود: همه‌ی عضویت‌ها از دفترِ عضویت پایان می‌یابند و تاریخچه می‌ماند (۱۰/۲–۱۰/۴)
        while ($user->exists && $user->complex_id !== null) {
            app(MembershipLedger::class)->end($user, ComplexMembership::END_REMOVED);
        }
        $user->fill([
            'complex_id' => null,
            'name' => 'ثبت‌نام‌کننده‌ی سرتاسری',
            // حسابِ پیش از مرحلهٔ ۹: نامِ تک‌رشته‌ای، تا مسیرِ «تکمیلِ نام» هم سنجیده شود
            'first_name' => null,
            'last_name' => null,
            'password' => Hash::make('password'),
            'role' => UserRole::Owner,
            'is_active' => true,
            'terms_accepted_at' => now(),
        ])->save();

        $user->units()->withoutGlobalScopes()->detach();
        // از مرحلهٔ ۹ · فاز ۶ دعوت به شماره بسته است، نه فقط به حساب
        ComplexInvitation::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('phone', $phone))
            ->delete();
    }
}
