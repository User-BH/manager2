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
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Auth\SessionRegistry;
use App\Services\Charge\BillGenerator;
use App\Support\Audit;
use App\Support\Jalali;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * دادهٔ نمونه‌ی Preview (مرحلهٔ ۱۳ · فاز ۱؛ ساختارِ نهاییِ «دستورِ اجراییِ جامع» = «حفره ها۲»، ۲۰۲۶-۱۰-۲۶، بخشِ چهارم).
 *
 * چهار حسابِ آزمایشی، همه با نام کاربری و بی شماره‌ی موبایل (شماره‌ای رزرو نمی‌شود و پیامکی نمی‌رود):
 *
 *   demo.initial «داشبوردِ آزمایشیِ ثبت‌نامِ اولیه»: Account ِ مستقل **بی هیچ عضویت** — با هر ورود به داشبوردِ اولیه
 *                می‌رسد و مسیرهای واقعیِ بی‌عضویت (درخواستِ پیوستن، خرید) را می‌آزماید (بندِ ۴.۱)
 *   demo.single  «داشبوردِ آزمایشیِ تک‌مجتمعی» (Single-community test dashboard): دقیقاً یک مجتمعِ مسکونی با پلنِ
 *                پیشرفته، نقشِ واحد (بندِ ۴.۲)
 *   demo.multi   «داشبوردِ آزمایشیِ چندمجتمعی» (Multi-community test dashboard): یک Account با پنج عضویتِ مستقل (بندِ ۴.۳):
 *                  ۱ مسکونی · پایه · مدیر        ۲ مسکونی · حرفه‌ای · مدیر
 *                  ۳ تجاری · پایه · واحد          ۴ تجاری · حرفه‌ای · واحد        ۵ تجاری · پیشرفته · مدیر
 *   demo.admin   «سوپر ادمینِ آزمایشی» برای بررسیِ قابلیت‌های مدیریتی، فقط در Preview (بندِ ۴.۵)
 *
 * ⚠️ تا ۲۰۲۶-۱۰-۲۵ داشبوردِ اولیه «وضعیت، نه حساب» بود و `demo.initial` غیرفعال می‌شد. «حفره ها۲» (بندِ ۳.۱) صریحاً
 * همین را رد کرد: سناریوی اولیه باید حسابِ قابلِ ورودِ خودش را داشته باشد.
 *
 * 🔴 قاعده‌ها:
 * - همه‌چیز `is_demo` می‌گیرد — نشانگرِ سمتِ سرور؛ هیچ مسیرِ API آن را نمی‌نویسد.
 * - **idempotent**: اجرای دوباره چیزِ تکراری نمی‌سازد و رمزِ حسابی را که هست هرگز عوض نمی‌کند. هر چه نیست ساخته
 *   می‌شود؛ رمزِ حسابِ تازه تصادفی است و فقط یک بار به خروجیِ فرمان می‌رود (`issued()`) — نه در مخزن، نه در لاگ، نه در
 *   پایگاه‌داده جز به شکلِ hash.
 * - حسابی که هست ولی غیرفعال است خودبه‌خود فعال نمی‌شود (شاید سوپر ادمینِ آزمایشی عمداً بسته باشدش)؛ `dormant()` گزارشش
 *   می‌دهد و بازگرداندن با `restore()` (فرمانِ `preview:reset-account`) است — صریح و برای همان یک حساب.
 * - حساب‌های ساختارِ پیشینِ ۱۳/۱ (`LEGACY`) اگر روی پایگاه‌داده‌ای مانده باشند **غیرفعال** می‌شوند، نه حذف؛ فرمان
 *   گزارششان می‌دهد و `preview:reset` پاکشان می‌کند.
 * - روی Production اجرا نمی‌شود.
 * - اشتراک‌ها «اهدای نمونه» با روشِ `manual` و مبلغِ صفرند: هیچ پرداخت، درگاه یا پولِ واقعی‌ای در کار نیست.
 */
class PreviewSeeder extends Seeder
{
    /** نام‌های کاربریِ چهار حساب — برای فرمان، آزمون و مستندات */
    public const INITIAL = 'demo.initial';

    public const SINGLE = 'demo.single';

    public const MULTI = 'demo.multi';

    public const ADMIN = 'demo.admin';

    public const ACCOUNTS = [self::INITIAL, self::SINGLE, self::MULTI, self::ADMIN];

    /** حساب‌های ساختارِ پیشینِ ۱۳/۱ که دیگر در سند نیستند */
    public const LEGACY = ['demo.unit', 'demo.manager'];

    /** مجتمعِ تکِ حسابِ تک‌مجتمعی */
    public const SINGLE_CONTEXT = 'demo-single';

    /**
     * مجتمع‌های آزمایشی. `plan` همان slug ِ پلن‌های پیش‌فرض است: basic = پایه، plus = حرفه‌ای، business = سطحِ سوم
     * («پیشرفته» ِ اسناد). `role` نقشِ **حسابِ آزمایشی** در همان مجتمع است: مدیر (`ComplexAdmin`) یا واحد (`Owner`).
     * پنج سطرِ اول دقیقاً جدولِ بندِ ۱.۲ و به همان ترتیب‌اند؛ سطرِ آخر مجتمعِ حسابِ تک‌مجتمعی (بندِ ۱.۱).
     *
     * @var array<string, array{name: string, type: ComplexType, plan: string, role: UserRole}>
     */
    public const CONTEXTS = [
        'demo-res-basic' => ['name' => 'مجتمع مسکونی آزمایشی ۱ (پایه)', 'type' => ComplexType::Residential, 'plan' => 'basic', 'role' => UserRole::ComplexAdmin],
        'demo-res-pro' => ['name' => 'مجتمع مسکونی آزمایشی ۲ (حرفه‌ای)', 'type' => ComplexType::Residential, 'plan' => 'plus', 'role' => UserRole::ComplexAdmin],
        'demo-com-basic' => ['name' => 'مجتمع تجاری آزمایشی ۳ (پایه)', 'type' => ComplexType::Commercial, 'plan' => 'basic', 'role' => UserRole::Owner],
        'demo-com-pro' => ['name' => 'مجتمع تجاری آزمایشی ۴ (حرفه‌ای)', 'type' => ComplexType::Commercial, 'plan' => 'plus', 'role' => UserRole::Owner],
        'demo-com-advanced' => ['name' => 'مجتمع تجاری آزمایشی ۵ (پیشرفته)', 'type' => ComplexType::Commercial, 'plan' => 'business', 'role' => UserRole::ComplexAdmin],
        self::SINGLE_CONTEXT => ['name' => 'مجتمع مسکونی آزمایشیِ تک‌مجتمعی (پیشرفته)', 'type' => ComplexType::Residential, 'plan' => 'business', 'role' => UserRole::Owner],
    ];

    /** @var list<array{label: string, username: string, password: string}> */
    private array $issued = [];

    /** @var list<string> */
    private array $retired = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('PreviewSeeder روی محصول اجرا نمی‌شود: دادهٔ آزمایشی فقط روی Preview است.');
        }

        $this->retireLegacy();

        $complexes = [];
        foreach (self::CONTEXTS as $slug => $spec) {
            $complexes[$slug] = Complex::where('slug', $slug)->first() ?? $this->buildComplex($slug, $spec);
        }

        $this->persona(self::ADMIN, 'سوپر ادمینِ آزمایشی', UserRole::SuperAdmin, null);

        // بی مجتمع و بی عضویت: همان نقش و حالتی که ثبت‌نامِ واقعی می‌دهد (`AuthController::registerVerify`)
        $this->persona(self::INITIAL, 'داشبوردِ آزمایشیِ ثبت‌نامِ اولیه', UserRole::Owner, null);

        $single = $this->persona(self::SINGLE, 'داشبوردِ آزمایشیِ تک‌مجتمعی', UserRole::Owner, $complexes[self::SINGLE_CONTEXT]);
        $this->ensureResident($single, $complexes[self::SINGLE_CONTEXT], UserRole::Owner, '1');

        // زمینه‌ی اولِ جدول زمینه‌ی پیش‌فرضِ حساب است؛ چهار تای دیگر عضویتِ مستقلِ همان Account
        $multiContexts = array_keys(array_diff_key(self::CONTEXTS, [self::SINGLE_CONTEXT => true]));
        $first = array_shift($multiContexts);
        $multi = $this->persona(self::MULTI, 'داشبوردِ آزمایشیِ چندمجتمعی', self::CONTEXTS[$first]['role'], $complexes[$first]);
        foreach ($multiContexts as $slug) {
            $this->ensureMembership($multi, $complexes[$slug], self::CONTEXTS[$slug]['role']);
        }
        foreach (array_keys(array_diff_key(self::CONTEXTS, [self::SINGLE_CONTEXT => true])) as $slug) {
            if (self::CONTEXTS[$slug]['role'] !== UserRole::ComplexAdmin) {
                $this->ensureResident($multi, $complexes[$slug], self::CONTEXTS[$slug]['role'], '1');
            }
        }

        // پیامِ گروه در زمینه‌هایی که مدیرشان خودِ حسابِ چندمجتمعی است — یک بار
        foreach (self::CONTEXTS as $slug => $spec) {
            $complex = $complexes[$slug];
            if ($spec['role'] === UserRole::ComplexAdmin && ! Message::withoutGlobalScopes()->where('complex_id', $complex->id)->exists()) {
                Message::create([
                    'complex_id' => $complex->id, 'user_id' => $multi->id, 'body' => 'پیامِ نمونه‌ی گروهِ مجتمع.',
                    'author_name' => $multi->name, 'author_role' => UserRole::ComplexAdmin->value, 'unit_label' => 'مدیریت',
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

    /**
     * حساب‌های ساختارِ پیشین که همین اجرا غیرفعال کرد — فرمان گزارششان می‌دهد.
     *
     * @return list<string>
     */
    public function retired(): array
    {
        return $this->retired;
    }

    /**
     * حساب‌های آزمایشیِ ساختارِ فعلی که هستند ولی غیرفعال‌اند — مثلاً `demo.initial` ِ غیرفعال‌شده‌ی ساختارِ ۲۰۲۶-۱۰-۲۵.
     * این اجرا فعالشان نکرد؛ فرمان راهِ صریحِ بازگرداندن را می‌گوید.
     *
     * @return list<string>
     */
    public function dormant(): array
    {
        return User::withoutGlobalScopes()->where('is_demo', true)->whereIn('username', self::ACCOUNTS)
            ->where('is_active', false)->orderBy('username')->pluck('username')->all();
    }

    /**
     * بازگرداندنِ کنترل‌شده‌ی **یک** حسابِ آزمایشی به سناریوی تعریف‌شده‌اش («حفره ها۲»، بندهای ۴.۱ و ۴.۶).
     *
     * فقط همان حساب و فقط از مسیرهای واقعیِ دامنه:
     * - درخواست‌های پیوستنِ در انتظارِ خودش پس گرفته می‌شوند (`REVOKED`)؛
     * - عضویتِ زنده‌ای که در جدولِ همین حساب نیست با `Membership::end` پایان می‌یابد — ردیف و دوره‌ی واحد به‌عنوانِ سابقه
     *   می‌مانند، هیچ مجتمع، پیام، قبض یا پرداختی حذف نمی‌شود (مجتمعی که با خریدِ آزمایشی ساخته شده سرِ جایش می‌ماند)؛
     * - عضویتِ معلقِ درون جدول از سر گرفته و عضویتِ جاافتاده‌ی جدول دوباره ساخته می‌شود؛ زمینه‌ی پیش‌فرض اولین سطرِ جدول؛
     * - حساب فعال می‌شود، همه‌ی نشست‌ها و دستگاه‌های مورداعتمادش پایان می‌یابند و رویداد در audit log ثبت می‌شود.
     *
     * 🔴 رمز فقط با `$newPassword` عوض می‌شود — رمزی را که بازبین عوض کرده بی‌اجازه برنمی‌گرداند. رمزِ تازه تصادفی است و
     * فقط در مقدارِ بازگشتی (برای چاپِ یک‌باره) می‌آید. حسابِ بی‌نشانگرِ `is_demo` یا بیرون از چهار نام کاربری رد می‌شود.
     *
     * @return array{actions: list<string>, password: string|null}
     */
    public function restore(string $username, bool $newPassword = false): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('حسابِ آزمایشی روی محصول بازگردانده نمی‌شود.');
        }

        if (! in_array($username, self::ACCOUNTS, true)) {
            throw new RuntimeException("«{$username}» از حساب‌های آزمایشیِ Preview نیست: ".implode('، ', self::ACCOUNTS));
        }

        $user = User::withoutGlobalScopes()->where('username', $username)->where('is_demo', true)->first()
            ?? throw new RuntimeException("حسابِ آزمایشیِ «{$username}» نیست؛ نخست `preview:seed` را اجرا کنید.");

        $actions = [];

        if (! $user->is_active) {
            $user->forceFill(['is_active' => true])->save();
            $actions[] = 'حساب فعال شد';
        }

        if ($username !== self::ADMIN) {
            if ($user->isSuperAdmin()) {
                throw new RuntimeException("«{$username}» به ادمینِ کل ارتقا یافته؛ این را فقط `preview:reset` برمی‌گرداند.");
            }

            $actions = [...$actions, ...$this->restoreMemberships($user)];
        }

        $sessions = app(SessionRegistry::class)->endAll($user);
        $user->trustedDevices()->delete();
        if ($sessions > 0) {
            $actions[] = "{$sessions} نشست پایان یافت";
        }

        $password = null;
        if ($newPassword) {
            $password = Str::password(14, symbols: false);
            $user->forceFill(['password' => Hash::make($password)])->save();
            $actions[] = 'رمزِ تازه صادر شد';
        }

        Audit::log('preview.account_restored', 'بازگرداندنِ حسابِ آزمایشیِ Preview به سناریوی خودش', $user, [
            'username' => $username,
            'actions' => $actions,
            'new_password' => $newPassword,
        ]);

        return ['actions' => $actions, 'password' => $password];
    }

    /**
     * جدولِ هر حساب: مجتمع (slug) ← نقش، به ترتیب؛ اولی زمینه‌ی پیش‌فرض. حسابِ اولیه هیچ؛ سوپر ادمین عضوِ مجتمع نیست.
     *
     * @return array<string, UserRole>
     */
    private static function targets(string $username): array
    {
        $contexts = match ($username) {
            self::SINGLE => [self::SINGLE_CONTEXT => self::CONTEXTS[self::SINGLE_CONTEXT]],
            self::MULTI => array_diff_key(self::CONTEXTS, [self::SINGLE_CONTEXT => true]),
            default => [],
        };

        return array_map(fn (array $spec): UserRole => $spec['role'], $contexts);
    }

    /** @return list<string> */
    private function restoreMemberships(User $user): array
    {
        $actions = [];
        $targets = self::targets((string) $user->username);

        $requests = ComplexInvitation::where('direction', ComplexInvitation::REQUEST)->where('user_id', $user->id)
            ->where('status', ComplexInvitation::PENDING)->get();
        foreach ($requests as $request) {
            $request->close(ComplexInvitation::REVOKED);
        }
        if ($requests->isNotEmpty()) {
            $actions[] = $requests->count().' درخواستِ پیوستنِ در انتظار پس گرفته شد';
        }

        $live = ComplexMembership::where('user_id', $user->id)->whereIn('status', ComplexMembership::LIVE)->get();
        foreach ($live as $membership) {
            $complex = Complex::withoutGlobalScopes()->findOrFail($membership->complex_id);
            $role = $targets[$complex->slug] ?? null;

            if ($role !== $membership->role) {
                // بیرون از جدول، یا با نقشِ دیگر: پایان با همان مسیرِ مدیر — سابقه می‌ماند
                app(Membership::class)->end(User::withoutGlobalScopes()->findOrFail($user->id), null, $complex->id);
                $actions[] = "عضویت در «{$complex->name}» پایان یافت";
            } elseif ($membership->status === ComplexMembership::SUSPENDED) {
                app(MembershipLedger::class)->resume(User::withoutGlobalScopes()->findOrFail($user->id), $complex->id);
                $actions[] = "عضویت در «{$complex->name}» از تعلیق درآمد";
            }
        }

        $account = User::withoutGlobalScopes()->findOrFail($user->id);
        foreach ($targets as $slug => $role) {
            $complex = Complex::where('slug', $slug)->first()
                ?? throw new RuntimeException("مجتمعِ آزمایشیِ «{$slug}» نیست؛ نخست `preview:seed` را اجرا کنید.");

            if ($this->ensureMembership($account, $complex, $role)) {
                $actions[] = "عضویت در «{$complex->name}» دوباره برقرار شد";
            }
            if ($role !== UserRole::ComplexAdmin && $this->ensureResident($account, $complex, $role, '1')) {
                $actions[] = "دوره‌ی واحدِ ۱ ِ «{$complex->name}» دوباره برقرار شد";
            }
        }

        $default = array_key_first($targets);
        $account = User::withoutGlobalScopes()->findOrFail($user->id);
        if ($default !== null) {
            $id = Complex::where('slug', $default)->value('id');
            if ($account->complex_id !== $id) {
                app(MembershipLedger::class)->switchTo($account, (int) $id);
                $actions[] = 'زمینه‌ی پیش‌فرض به اولین سطرِ جدول برگشت';
            }
        }

        return $actions;
    }

    /**
     * حسابِ آزمایشیِ ساختارِ پیشین دیگر واردشدنی نیست؛ حذف نمی‌شود تا هیچ داده‌ای بی‌بررسی از دست نرود
     * (`preview:reset` همه را از نو می‌سازد).
     */
    private function retireLegacy(): void
    {
        $legacy = User::withoutGlobalScopes()->where('is_demo', true)->whereIn('username', self::LEGACY)->where('is_active', true)->get();

        foreach ($legacy as $user) {
            $user->forceFill(['is_active' => false])->save();
            $this->retired[] = (string) $user->username;
        }
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

    /** عضویتِ فعال در مجتمعِ دیگر، بی جابه‌جاکردنِ زمینه‌ی فعلی؛ `true` اگر همین حالا ساخته شد */
    private function ensureMembership(User $user, Complex $complex, UserRole $role): bool
    {
        $live = ComplexMembership::where('user_id', $user->id)
            ->where('complex_id', $complex->id)
            ->where('status', ComplexMembership::ACTIVE)
            ->exists();

        if (! $live) {
            app(MembershipLedger::class)->start($user, $complex, $role, ComplexMembership::SOURCE_DIRECT, activate: false);
        }

        return ! $live;
    }

    /**
     * دوره‌ی جاریِ مالک یا مستأجر در واحد؛ `true` اگر همین حالا ساخته شد. دوره‌ی بسته‌شده (پایانِ عضویت) سابقه است و
     * دست نمی‌خورد — دوره‌ی تازه کنارش می‌نشیند.
     */
    private function ensureResident(User $user, Complex $complex, UserRole $role, string $unitNumber): bool
    {
        $unit = Unit::withoutGlobalScopes()->where('complex_id', $complex->id)->where('unit_number', $unitNumber)->firstOrFail();
        $relation = $role === UserRole::Tenant ? ResidentRelation::Tenant : ResidentRelation::Owner;

        if ($unit->residents()->where('users.id', $user->id)->wherePivot('is_current', true)->exists()) {
            return false;
        }

        $unit->residents()->attach($user->id, [
            'complex_id' => $complex->id,
            'relation' => $relation->value,
            'share_percent' => 100,
            'is_current' => true,
            'start_date' => now()->subMonths(3),
        ]);

        return true;
    }

    /**
     * مجتمعِ نمونه با دادهٔ کافی برای بررسیِ قابلیت‌های واقعی (بندِ ۶.۱): مدیرِ نمونه (برای زمینه‌هایی که حسابِ
     * مدیر در آن‌ها عضوِ واحد است)، ساختمان، چهار واحد با مالک و مستأجر، قوانینِ شارژ، صورتحساب، هزینه، درآمد،
     * اطلاعیه و پیامِ گروه، و اشتراکِ نمونه.
     *
     * @param  array{name: string, type: ComplexType, plan: string, role: UserRole}  $spec
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

        // جایی که حسابِ آزمایشی مدیر است مدیرِ دیگری لازم نیست؛ در بقیه یک مدیرِ نمونه تا مجتمع بی‌مدیر نماند
        $admin = $spec['role'] === UserRole::ComplexAdmin
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

            // واحدِ ۱ مالِ حسابِ آزمایشی است هر جا نقشش «واحد» است؛ بقیه ساکنِ نمونه دارند
            if ($spec2['no'] === '1' && $spec['role'] !== UserRole::ComplexAdmin) {
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
