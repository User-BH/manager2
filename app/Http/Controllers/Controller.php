<?php

namespace App\Http\Controllers;

use App\Models\Complex;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /**
     * The complex the current admin is acting within. Complex-admins are
     * locked to their own; the super-admin uses the one selected in session.
     */
    protected function currentComplex(): ?Complex
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            $id = session('active_complex_id');

            return $id ? Complex::find($id) : null;
        }

        return $user->complex;
    }

    /** Resolve the current complex or abort with a helpful message. */
    protected function requireComplex(): Complex
    {
        $complex = $this->currentComplex();
        abort_if($complex === null, 409, 'ابتدا یک مجتمع را انتخاب کنید.');

        return $complex;
    }
}
