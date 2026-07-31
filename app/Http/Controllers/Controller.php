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
    protected function currentComplex(): ?Complex
    {
        $id = ComplexResolver::idFor(Auth::user());

        return $id ? Complex::find($id) : null;
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
