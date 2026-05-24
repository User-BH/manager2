<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function edit()
    {
        return view('admin.settings.edit', ['complex' => $this->requireComplex()]);
    }

    public function update(Request $request)
    {
        $complex = $this->requireComplex();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'currency' => ['required', 'in:toman,rial'],
            'charge_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'payment_gateway' => ['required', 'in:none,fake,mellat,saman'],
            'messenger_enabled' => ['nullable', 'boolean'],
            'good_payer_enabled' => ['nullable', 'boolean'],
            'penalty_enabled' => ['nullable', 'boolean'],
            'penalty_type' => ['required', 'in:fixed,percent,percent_per_day'],
            'penalty_value' => ['required', 'numeric', 'min:0'],
            'penalty_grace_days' => ['required', 'integer', 'min:0', 'max:60'],
        ]);

        $complex->update([
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'currency' => $data['currency'],
            'charge_due_day' => $data['charge_due_day'],
            'payment_gateway' => $data['payment_gateway'],
            'messenger_enabled' => $request->boolean('messenger_enabled'),
            'good_payer_enabled' => $request->boolean('good_payer_enabled'),
            'penalty_enabled' => $request->boolean('penalty_enabled'),
            'penalty_type' => $data['penalty_type'],
            'penalty_value' => $data['penalty_value'],
            'penalty_grace_days' => $data['penalty_grace_days'],
        ]);

        return back()->with('success', 'تنظیمات مجتمع ذخیره شد.');
    }
}
