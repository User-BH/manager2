<?php

namespace App\Services\Account;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ارتقای حسابِ «حالتِ اولیه» به مدیرِ مجتمع (R21).
 *
 * ─── چرا یک سرویسِ جدا ─────────────────────────────────────────────────────
 * این کار دو نوشتنِ وابسته دارد که **هرگز نباید نیمه‌کاره بمانند**: ساختِ
 * مجتمع و تغییرِ نقشِ کاربر. اگر مجتمع ساخته شود و نقش عوض نشود، کاربر
 * صاحبِ مجتمعی است که به آن دسترسی ندارد و هیچ‌کس هم مدیرش نیست — یعنی یک
 * مجتمعِ یتیم که فقط ادمینِ کل می‌تواند نجاتش بدهد.
 */
class ComplexUpgrader
{
    public function __construct(private readonly MembershipLedger $ledger) {}

    /**
     * ساختِ مجتمع و ارتقای کاربر به مدیرِ آن.
     *
     * پس از این، حساب از حالتِ اولیه بیرون می‌آید و قفلِ فقط‌خواندنی
     * خودبه‌خود برداشته می‌شود (چون از `complex_id` مشتق می‌شود، نه از یک
     * پرچمِ جداگانه که ممکن است یادمان برود عوضش کنیم).
     */
    public function upgrade(
        User $user,
        string $complexName,
        ?string $address,
        ComplexType $type,
    ): Complex {
        if (AccountState::of($user) !== AccountState::Initial) {
            throw DomainException::invalid(
                'این حساب از قبل به یک مجتمع وصل است.',
                'account.already_member',
            );
        }

        return DB::transaction(function () use ($user, $complexName, $address, $type): Complex {
            /*
             * وضعیت **داخلِ تراکنش** دوباره خوانده می‌شود: دو ارسالِ هم‌زمانِ
             * فرم نباید دو مجتمع بسازد و کاربر را به دومی وصل کند، در حالی
             * که اولی بی‌صاحب می‌ماند.
             */
            $fresh = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            if ($fresh->complex_id !== null) {
                throw DomainException::invalid(
                    'این حساب از قبل به یک مجتمع وصل است.',
                    'account.already_member',
                );
            }

            $complex = Complex::create([
                'name' => $complexName,
                /*
                 * ⚠️ نوع در همین لحظه تثبیت می‌شود (R67).
                 *
                 * پس از این، فقط ادمینِ کل می‌تواند عوضش کند — چون تعرفه‌ی
                 * پکیج به آن گره خورده و تغییرِ آزادانه‌اش یعنی مدیر بتواند
                 * با یک سوییچ، تعرفه‌ی ارزان‌تر را بگیرد.
                 */
                'type' => $type,
                // پسوندِ تصادفی: دو مجتمع می‌توانند نامِ یکسان داشته باشند
                'slug' => Str::slug($complexName).'-'.Str::random(4),
                'address' => $address,
                'payment_gateway' => 'none',
            ]);

            // عضویتِ مدیر و زمینه‌ی فعالِ حساب با هم (مرحلهٔ ۱۰ · فاز ۲)
            $this->ledger->start($fresh, $complex, UserRole::ComplexAdmin, ComplexMembership::SOURCE_PURCHASE);

            /*
             * دعوت‌های در انتظار دیگر باطل نمی‌شوند (مرحلهٔ ۱۰ · فاز ۴): پیش از این پذیرفتنِ
             * دعوتِ قدیمی خریدار را بی‌سروصدا از مجتمعِ خودش بیرون می‌برد. حالا پذیرفتنش
             * عضویتِ **دوم** است و مدیریتِ مجتمعِ خودش سرِ جایش می‌ماند.
             */

            Audit::log('account.upgraded', 'ارتقا به مدیر مجتمع', $complex, [
                'user_id' => $fresh->id,
            ]);

            return $complex;
        }, attempts: 3);
    }
}
