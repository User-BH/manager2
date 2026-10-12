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
 * ساختِ مجتمعِ تازه و مدیر شدنِ خریدار (R21؛ از مرحلهٔ ۱۰ · فاز ۵ برای هر حسابِ غیرِ سکو).
 *
 * ─── تصمیمِ کارفرما (۱۰/۵) ───────────────────────────────────────────────────
 * پیش از این فقط حسابِ «حالتِ اولیه» مجتمع می‌خرید (سه نگهبانِ `account.already_member` در همین
 * سرویس و `ComplexPurchase`). حالا عضوِ مجتمع‌های دیگر هم با همان حساب می‌خرد: مالکِ A و مستأجرِ
 * B، پس از خریدِ معتبر مدیرِ C. عضویت‌ها، نقش‌ها، داده، کیف و تاریخچه‌ی A و B دست نمی‌خورند و
 * زمینه‌ی فعالِ حسابِ عضو هم جابه‌جا نمی‌شود (کاربر خودش به C می‌رود).
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
        return DB::transaction(function () use ($user, $complexName, $address, $type): Complex {
            /*
             * حساب **زیرِ قفل** خوانده می‌شود. یکتاییِ خرید را قصدِ خرید تضمین می‌کند (یک
             * `complete` برای هر قصد، زیرِ قفلِ همان ردیف) — نه «حسابِ بی‌مجتمع»، که از ۱۰/۵ قاعده نیست.
             */
            $fresh = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            if (AccountState::of($fresh) === AccountState::Platform || ! $fresh->is_active) {
                throw DomainException::invalid('این حساب نمی‌تواند مجتمع بخرد.', 'checkout.buyer_not_allowed');
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

            /*
             * عضویتِ مدیر (مرحلهٔ ۱۰ · فاز ۲). حسابِ اولیه همین‌جا به زمینه‌ی تازه می‌رود؛ عضوِ مجتمعِ
             * دیگر نه — بازگشتِ درگاه بی نشست هم می‌رسد و تب‌های بازش را خبر نمی‌کند؛ تعویض با خودِ
             * کاربر است، پس از بازگشت (۱۰/۵).
             */
            $this->ledger->start($fresh, $complex, UserRole::ComplexAdmin, ComplexMembership::SOURCE_PURCHASE, activate: false);

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
