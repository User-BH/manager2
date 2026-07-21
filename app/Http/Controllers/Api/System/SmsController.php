<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsManager;
use App\Support\Phone;
use App\Support\SystemSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function show(): JsonResponse
    {
        $config = SystemSettings::getJson('sms_config', []);

        return response()->json([
            'settings' => [
                'sms_driver' => SystemSettings::get('sms_driver', 'log'),
                'apikey' => $config['apikey'] ?? '',
                'sender' => $config['sender'] ?? '',
                'username' => $config['username'] ?? '',
                // رمز وب‌سرویس برنمی‌گردد؛ فقط اینکه تنظیم شده یا نه.
                'password_set' => filled($config['password'] ?? null),
            ],
            'drivers' => collect(SmsManager::DRIVERS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms_driver' => ['required', 'in:'.implode(',', array_keys(SmsManager::DRIVERS))],
            'apikey' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:30'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:100'],
        ], [], ['sms_driver' => 'سامانه پیامک']);

        $existing = SystemSettings::getJson('sms_config', []);

        SystemSettings::set('sms_driver', $data['sms_driver']);
        SystemSettings::set('sms_config', [
            'apikey' => $data['apikey'] ?? '',
            'sender' => $data['sender'] ?? '',
            'username' => $data['username'] ?? '',
            // خالی گذاشتن رمز یعنی «تغییرش نده»، چون فرم هرگز مقدار فعلی را
            // نمایش نمی‌دهد.
            'password' => filled($data['password'] ?? null)
                ? $data['password']
                : ($existing['password'] ?? ''),
        ]);

        return response()->json(['message' => 'تنظیمات پنل پیامک ذخیره شد.']);
    }

    public function test(Request $request, SmsManager $sms): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string']], [], ['phone' => 'شماره تلفن']);

        $phone = Phone::normalize($request->input('phone'));

        if (! Phone::isValidMobile($phone)) {
            return response()->json(['message' => 'شماره تلفن معتبر نیست.'], 422);
        }

        $ok = $sms->send($phone, 'پیام آزمایشی سامانه مدیریت ساختمان. اتصال پنل پیامک برقرار است.');

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'پیام آزمایشی ارسال شد (در حالت تست، در فایل لاگ ثبت می‌شود).'
                : 'ارسال پیام آزمایشی ناموفق بود.',
        ], $ok ? 200 : 422);
    }
}
