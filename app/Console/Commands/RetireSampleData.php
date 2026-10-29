<?php

namespace App\Console\Commands;

use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Support\Audit;
use App\Support\Preview;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * کنار گذاشتنِ دادهٔ نمونه‌ی `DemoSeeder` از محصول (۲۰۲۶-۱۰-۲۹، بندِ ۱).
 *
 * روی sakena.ir پیش‌تر `DemoSeeder` اجرا شده بود: سوپر ادمینِ «مدیر سیستم»، حسابِ «ثبت‌نامِ اولیه»، مدیرِ «مجتمع مسکونی
 * آفتاب» و ساکنانش — همه با رمزِ عمومیِ «password». داشبوردهای نمونه حالا در preview.sakena.ir اند؛ روی محصول فقط
 * سوپر ادمین‌های واقعی و کسانی که خودشان ثبت‌نام کرده‌اند وارد می‌شوند.
 *
 * ─── چرا «کنار گذاشتن» و نه حذفِ ردیف ─────────────────────────────────────
 * قبض، پرداخت و دفترِ مالیِ نمونه به این حساب‌ها و مجتمع گره خورده‌اند و پاک‌کردنِ زنجیره‌ای‌شان از کلیدهای خارجی و
 * قفل‌های دفتر رد می‌شد یا نیمه‌کاره می‌ماند. پس هر حساب و مجتمعِ نمونه همان نشانگرِ `is_demo` را می‌گیرد که Preview
 * دارد — و روی Production این یعنی:
 *
 *   • ورود هرگز (`DemoData::refuses`) و نشستِ باز در درخواستِ بعدی بسته (`EnsureActive`)؛ به‌علاوه غیرفعال و رمزِ تصادفی؛
 *   • در فهرست‌ها، جست‌وجو و آمارِ سوپر ادمین دیده نمی‌شوند (`operational`)؛
 *   • شماره‌ها آزاد می‌شوند: اگر صاحبِ واقعیِ یکی از این شماره‌ها ثبت‌نام کند، حسابِ تازه‌ی خودش را می‌گیرد.
 *
 * ─── فقط دادهٔ نمونه، با اثرانگشت ─────────────────────────────────────────
 * حساب فقط وقتی نمونه است که **هم** شماره‌اش از فهرستِ seeder باشد **هم** ایمیل یا نامِ ساخته‌ی همان seeder را داشته
 * باشد. مجتمع فقط وقتی کنار می‌رود که همه‌ی اعضایش نمونه باشند. پیش‌فرض فقط گزارش است؛ `--force` اجرا می‌کند.
 */
class RetireSampleData extends Command
{
    protected $signature = 'sample-data:retire
        {--force : اجرا (بی آن فقط فهرست چاپ می‌شود)}
        {--keep=* : شماره‌ای که با وجودِ اثرانگشت نباید دست بخورد}';

    protected $description = 'حساب‌ها و مجتمعِ نمونه‌ی DemoSeeder را روی محصول از ورود و فهرست‌ها کنار می‌گذارد';

    /** مجتمعِ نمونه — همان نام و تلفنِ `DemoSeeder` */
    private const SAMPLE_COMPLEX = ['name' => 'مجتمع مسکونی آفتاب', 'phone' => '02112345678'];

    /** @return array<string, list<string>> شماره ← نام‌های ساخته‌ی seeder */
    public static function sampleAccounts(): array
    {
        $accounts = [
            '09120000001' => ['مدیر سیستم'],
            '09120000002' => ['کاربر تازه‌ثبت‌نام'],
            '09120000000' => ['مدیر ساختمان آفتاب'],
        ];
        foreach (range(1, 6) as $n) {
            $accounts['0912111110'.$n] = ["مالک واحد {$n}"];
            $accounts['0912222220'.$n] = ["مستاجر واحد {$n}"];
        }

        return $accounts;
    }

    public function handle(): int
    {
        if (Preview::active()) {
            $this->error('Preview دادهٔ نمونه‌ی خودش را دارد (preview:reset)؛ این فرمان مالِ محصول است.');

            return self::FAILURE;
        }

        $users = $this->sampleUsers();
        $complexes = $this->sampleComplexes($users);

        if ($users->isEmpty() && $complexes->isEmpty()) {
            $this->info('دادهٔ نمونه‌ای پیدا نشد؛ کاری نماند.');

            return self::SUCCESS;
        }

        $this->table(['شناسه', 'نام', 'شماره', 'نقش', 'فعال'], $users->map(fn (User $u) => [
            $u->id, $u->name, $u->phone, $u->role->label(), $u->is_active ? 'بله' : 'خیر',
        ])->all());
        foreach ($complexes as $complex) {
            $this->line("مجتمعِ نمونه: #{$complex->id} {$complex->name}");
        }

        if (! $this->option('force')) {
            $this->warn('فقط گزارش. برای اجرا (پس از پشتیبان‌گیری): php artisan sample-data:retire --force');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($users, $complexes): void {
            foreach ($users as $user) {
                $user->forceFill([
                    'is_demo' => true,
                    'is_active' => false,
                    'phone' => null,
                    'username' => null,
                    'email' => null,
                    // «password» ِ عمومی دیگر هیچ‌جا کار نکند
                    'password' => Hash::make(Str::random(48)),
                    'remember_token' => null,
                ])->save();
                $user->trustedDevices()->delete();
            }
            // نشستِ باز را `EnsureActive` هم در درخواستِ بعدی می‌بندد؛ این فقط زودتر
            if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', $users->pluck('id')->all())->delete();
            }

            foreach ($complexes as $complex) {
                $complex->forceFill(['is_demo' => true])->save();
            }

            Audit::log('sample_data.retired', 'کنار گذاشتنِ حساب‌ها و مجتمعِ نمونه از محصول', null, [
                'users' => $users->pluck('id')->all(),
                'complexes' => $complexes->pluck('id')->all(),
            ], null);
        });

        $this->info(sprintf('%d حساب و %d مجتمعِ نمونه کنار گذاشته شد: ورود بسته، شماره‌ها آزاد، از فهرست‌ها بیرون.', $users->count(), $complexes->count()));

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function sampleUsers(): Collection
    {
        $keep = array_map('strval', (array) $this->option('keep'));
        $accounts = array_diff_key(self::sampleAccounts(), array_flip($keep));

        return User::withoutGlobalScopes()
            ->whereIn('phone', array_keys($accounts))
            ->where('is_demo', false)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u): bool => in_array($u->name, $accounts[$u->phone] ?? [], true)
                || ($u->email !== null && (Str::endsWith($u->email, '@aftab.test') || $u->email === 'admin@system.test')))
            ->values();
    }

    /**
     * مجتمعِ نمونه، فقط اگر همه‌ی اعضای فعلی‌اش حسابِ نمونه‌اند — مجتمعی که کسِ واقعی در آن است دست نمی‌خورد.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, Complex>
     */
    private function sampleComplexes(Collection $users): Collection
    {
        $ids = $users->pluck('id')->all();

        return Complex::withoutGlobalScopes()
            ->where(self::SAMPLE_COMPLEX)
            ->where('is_demo', false)
            ->get()
            ->filter(function (Complex $complex) use ($ids): bool {
                $members = array_unique([
                    ...User::withoutGlobalScopes()->where('complex_id', $complex->id)->pluck('id')->all(),
                    ...ComplexMembership::withoutGlobalScopes()->where('complex_id', $complex->id)->pluck('user_id')->all(),
                ]);
                $real = array_diff($members, $ids);
                if ($real !== []) {
                    $this->warn("مجتمعِ «{$complex->name}» عضوِ واقعی دارد (".count($real).' نفر) و دست نمی‌خورد.');

                    return false;
                }

                return true;
            })
            ->values();
    }
}
