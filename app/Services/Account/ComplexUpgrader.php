<?php

namespace App\Services\Account;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\ComplexInvitation;
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
    /**
     * ساختِ مجتمع و ارتقای کاربر به مدیرِ آن.
     *
     * پس از این، حساب از حالتِ اولیه بیرون می‌آید و قفلِ فقط‌خواندنی
     * خودبه‌خود برداشته می‌شود (چون از `complex_id` مشتق می‌شود، نه از یک
     * پرچمِ جداگانه که ممکن است یادمان برود عوضش کنیم).
     */
    public function upgrade(User $user, string $complexName, ?string $address = null): Complex
    {
        if (AccountState::of($user) !== AccountState::Initial) {
            throw DomainException::invalid(
                'این حساب از قبل به یک مجتمع وصل است.',
                'account.already_member',
            );
        }

        return DB::transaction(function () use ($user, $complexName, $address): Complex {
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
                // پسوندِ تصادفی: دو مجتمع می‌توانند نامِ یکسان داشته باشند
                'slug' => Str::slug($complexName).'-'.Str::random(4),
                'address' => $address,
                'payment_gateway' => 'none',
            ]);

            $fresh->forceFill([
                'complex_id' => $complex->id,
                'role' => UserRole::ComplexAdmin,
            ])->save();

            /*
             * دعوت‌های در انتظار باطل می‌شوند: کاربر حالا مدیرِ مجتمعِ خودش
             * است و پذیرفتنِ دعوتِ قدیمی او را بی‌سروصدا از مجتمعِ خودش بیرون
             * می‌برد و ساکنِ جای دیگری می‌کند.
             */
            ComplexInvitation::where('user_id', $fresh->id)
                ->pending()
                ->update([
                    'status' => ComplexInvitation::DECLINED,
                    'responded_at' => now(),
                ]);

            Audit::log('account.upgraded', 'ارتقا به مدیر مجتمع', $complex, [
                'user_id' => $fresh->id,
            ]);

            return $complex;
        }, attempts: 3);
    }
}
