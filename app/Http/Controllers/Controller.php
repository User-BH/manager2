<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Support\ComplexResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /*
     * `authorize()` را در دسترسِ همه‌ی کنترلرها می‌گذارد.
     *
     * پیش از R9 مجوزدهی با `abort_unless(...)`های پراکنده انجام می‌شد؛
     * حالا هر بررسی در Policy است و کنترلر فقط می‌گوید «این را اجازه بده».
     */
    use AuthorizesRequests;

    /**
     * The complex the current admin is acting within. Complex-admins are
     * locked to their own; the super-admin uses the one selected in session.
     */
    /**
     * مجتمعِ جاری، با حافظه‌ی درون‌درخواستی.
     *
     * چند اکشن `requireComplex()` را بیش از یک بار صدا می‌زنند (مثلاً یک بار
     * برای مجوزدهی و یک بار برای فیلترِ کوئری) و هر بار یک `select` تازه
     * می‌رفت. مقدار در طولِ یک درخواست تغییر نمی‌کند، پس یک بار خواندنش کافی
     * است.
     *
     * عمداً `Cache` نیست: مجتمعِ جاری به کاربرِ همین درخواست بستگی دارد و
     * کشِ مشترک بین درخواست‌ها می‌توانست داده‌ی یک مستأجر را به دیگری نشان
     * دهد — بدترین باگِ ممکن در یک سامانه‌ی چندمستأجری.
     */
    private ?Complex $resolvedComplex = null;

    protected function currentComplex(): ?Complex
    {
        $id = ComplexResolver::idFor(Auth::user());

        /*
         * 🔴 حافظه فقط برای **همان** مجتمع (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶).
         *
         * لاراول نمونه‌ی کنترلر را روی خودِ Route نگه می‌دارد؛ در هر فرایندِ
         * ماندگار (تست، صف، Octane) درخواستِ بعدی — کاربرِ دیگر، مجتمعِ دیگر —
         * همان نمونه را می‌گیرد. حافظه‌ی بی‌کلید مجتمعِ کاربرِ قبلی را برمی‌گرداند:
         * `WalletOwnershipTest` دید مدیرِ مجتمعِ دیگر صورت‌حسابِ عضوِ این مجتمع را
         * گرفت. php-fpm هر درخواست را از نو می‌سازد، ولی مرزِ مستأجر نباید به آن تکیه کند.
         */
        if ($this->resolvedComplex !== null && $this->resolvedComplex->id === $id) {
            return $this->resolvedComplex;
        }

        return $this->resolvedComplex = $id ? Complex::find($id) : null;
    }

    /**
     * مجتمعِ جاری، یا خطای پیش‌نیاز.
     *
     * این «نبودِ دسترسی» نیست، «هنوز مجتمعی انتخاب نکرده‌ای» است — پس
     * `DomainException` می‌دهد و نه ۴۰۳، و کدِ ماشین‌خوان دارد تا فرانت
     * بتواند مستقیم انتخابگرِ مجتمع را باز کند.
     */
    protected function requireComplex(): Complex
    {
        return $this->currentComplex() ?? throw DomainException::precondition(
            'ابتدا یک مجتمع را انتخاب کنید.',
            'complex.not_selected',
        );
    }
}
