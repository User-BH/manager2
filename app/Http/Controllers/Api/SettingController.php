<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Services\Payment\Sandbox;
use App\Services\Subscription\PlanGate;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function show(PlanGate $plans): JsonResponse
    {
        $complex = $this->requireComplex();
        $gateway = $complex->gateway_config ?? [];

        return response()->json([
            'settings' => [
                'name' => $complex->name,
                'address' => $complex->address,
                'phone' => $complex->phone,
                'currency' => $complex->currency,
                'charge_due_day' => (int) $complex->charge_due_day,
                'payment_gateway' => $complex->payment_gateway,
                // اعتبارنامه‌ی درگاه هرگز به کلاینت برنمی‌گردد؛ فقط اینکه پر
                // شده یا نه. وگرنه رمز درگاه بانکی در پاسخ API دیده می‌شود.
                'gw_terminal_id' => $gateway['terminal_id'] ?? '',
                'gw_username' => $gateway['username'] ?? '',
                'gw_password_set' => filled($gateway['password'] ?? null),
                'messenger_enabled' => (bool) $complex->messenger_enabled,
                'good_payer_enabled' => (bool) $complex->good_payer_enabled,
                'penalty_enabled' => (bool) $complex->penalty_enabled,
                'penalty_type' => $complex->penalty_type,
                'penalty_value' => (float) $complex->penalty_value,
                'penalty_grace_days' => (int) $complex->penalty_grace_days,
            ],
            'options' => [
                'currencies' => [
                    ['value' => 'toman', 'label' => 'تومان'],
                    ['value' => 'rial', 'label' => 'ریال'],
                ],
                'gateways' => $this->gatewayOptions($complex),
                'penaltyTypes' => [
                    ['value' => 'fixed', 'label' => 'مبلغ ثابت'],
                    ['value' => 'percent', 'label' => 'درصدی از مبلغ قبض'],
                    ['value' => 'percent_per_day', 'label' => 'درصد روزانه'],
                ],
            ],
            // کلاینت با این دو، هشدار مناسب را بالای بخش درگاه نشان می‌دهد
            'sandboxAllowed' => Sandbox::isAllowed(),
            'sandboxActive' => $complex->payment_gateway === 'fake',
            // درگاه واقعی تنظیم شده ولی اشتراک پرو ندارد ⇒ پرداخت آنلاین خوابیده
            'gatewayBlockedByPlan' => in_array($complex->payment_gateway, ['mellat', 'saman'], true)
                && ! $plans->isPro($complex),
        ]);
    }

    /**
     * فهرست درگاه‌های قابل انتخاب.
     *
     * سندباکس روی سرور واقعی اصلاً پیشنهاد نمی‌شود. ولی اگر مجتمعی از قبل
     * روی سندباکس مانده باشد، گزینه با برچسب هشدار در فهرست می‌ماند — وگرنه
     * مقدار فعلیِ فرم در هیچ گزینه‌ای نمی‌نشست و ذخیره‌ی ساده‌ی تنظیمات،
     * درگاه را بی‌خبر عوض می‌کرد.
     *
     * @return array<int,array<string,string>>
     */
    private function gatewayOptions(Complex $complex): array
    {
        $options = [['value' => 'none', 'label' => 'بدون درگاه']];

        if (Sandbox::isAllowed()) {
            $options[] = ['value' => 'fake', 'label' => 'سندباکس (تست — بدون پول واقعی)'];
        } elseif ($complex->payment_gateway === 'fake') {
            $options[] = ['value' => 'fake', 'label' => 'سندباکس (روی این سرور غیرفعال است — تغییرش دهید)'];
        }

        return array_merge($options, [
            ['value' => 'mellat', 'label' => 'به‌پرداخت ملت'],
            ['value' => 'saman', 'label' => 'سامان / سپ'],
        ]);
    }

    public function update(Request $request, PlanGate $plans): JsonResponse
    {
        $complex = $this->requireComplex();

        /*
         * اتصال درگاه بانکیِ واقعی از امکانات پرو است. «سندباکس» و «بدون
         * درگاه» آزادند تا کاربر رایگان بتواند مسیر پرداخت را بیازماید و
         * تنظیمات فعلی‌اش هم قفل نشود.
         */
        if (in_array($request->input('payment_gateway'), ['mellat', 'saman'], true)) {
            $plans->assertCanUseRealGateway($complex);
        }

        /*
         * سندباکس روی سرور واقعی پذیرفته نمی‌شود. بررسی اینجاست و نه فقط در
         * فهرست گزینه‌ها، چون فهرست فقط رابط کاربری را می‌سازد و درخواست
         * مستقیم به سرور آن را دور می‌زد.
         *
         * تنها استثنا: مجتمعی که از قبل روی سندباکس مانده، می‌تواند تنظیمات
         * دیگرش را ذخیره کند بی‌آنکه فرم قفل شود. خودِ درگاه به‌هرحال در
         * `GatewayManager` مسدود است، پس این استثنا پرداختی را باز نمی‌کند.
         */
        $sandboxAcceptable = Sandbox::isAllowed() || $complex->payment_gateway === 'fake';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'currency' => ['required', 'in:toman,rial'],
            'charge_due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'payment_gateway' => [
                'required',
                Rule::in(array_filter(['none', $sandboxAcceptable ? 'fake' : null, 'mellat', 'saman'])),
            ],
            'gw_terminal_id' => ['nullable', 'string', 'max:50'],
            'gw_username' => ['nullable', 'string', 'max:100'],
            'gw_password' => ['nullable', 'string', 'max:100'],
            'messenger_enabled' => ['nullable', 'boolean'],
            'good_payer_enabled' => ['nullable', 'boolean'],
            'penalty_enabled' => ['nullable', 'boolean'],
            'penalty_type' => ['required', 'in:fixed,percent,percent_per_day'],
            'penalty_value' => ['required', 'numeric', 'min:0'],
            'penalty_grace_days' => ['required', 'integer', 'min:0', 'max:60'],
        ], [
            'payment_gateway.in' => 'درگاه آزمایشی روی سرور واقعی مجاز نیست؛ درگاه بانکی واقعی را انتخاب کنید یا «بدون درگاه» را بگذارید.',
        ], [
            'name' => 'نام مجتمع',
            'currency' => 'واحد پول',
            'charge_due_day' => 'روز سررسید',
            'payment_gateway' => 'درگاه پرداخت',
            'penalty_type' => 'نوع جریمه',
            'penalty_value' => 'مقدار جریمه',
            'penalty_grace_days' => 'روزهای مهلت',
        ]);

        $existing = $complex->gateway_config ?? [];
        $previousGateway = $complex->payment_gateway;

        $complex->update([
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'currency' => $data['currency'],
            'charge_due_day' => $data['charge_due_day'],
            'payment_gateway' => $data['payment_gateway'],
            'gateway_config' => [
                'terminal_id' => $data['gw_terminal_id'] ?? '',
                'username' => $data['gw_username'] ?? '',
                // رمز خالی یعنی «دست نزن»، نه «پاک کن» — چون فرم هرگز مقدار
                // فعلی را نمایش نمی‌دهد و ارسال خالی نباید آن را از بین ببرد.
                'password' => filled($data['gw_password'] ?? null)
                    ? $data['gw_password']
                    : ($existing['password'] ?? ''),
            ],
            'messenger_enabled' => $request->boolean('messenger_enabled'),
            'good_payer_enabled' => $request->boolean('good_payer_enabled'),
            'penalty_enabled' => $request->boolean('penalty_enabled'),
            'penalty_type' => $data['penalty_type'],
            'penalty_value' => $data['penalty_value'],
            'penalty_grace_days' => $data['penalty_grace_days'],
        ]);

        // تغییر درگاه بانکی مستقیم روی مسیر پول اثر دارد و باید ردیابی شود
        if ($previousGateway !== $data['payment_gateway']) {
            Audit::log('settings.gateway_changed', 'تغییر درگاه پرداخت مجتمع', $complex, [
                'from' => $previousGateway,
                'to' => $data['payment_gateway'],
            ]);
        }

        return response()->json(['message' => 'تنظیمات مجتمع ذخیره شد.']);
    }
}
