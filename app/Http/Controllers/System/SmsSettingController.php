<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Sms\SmsManager;
use App\Support\Phone;
use App\Support\SystemSettings;
use Illuminate\Http\Request;

class SmsSettingController extends Controller
{
    public function edit()
    {
        return view('system.sms.edit', [
            'driver' => SystemSettings::get('sms_driver', 'log'),
            'config' => SystemSettings::getJson('sms_config', []),
            'drivers' => SmsManager::DRIVERS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'sms_driver' => ['required', 'in:'.implode(',', array_keys(SmsManager::DRIVERS))],
            'apikey' => ['nullable', 'string', 'max:255'],
            'sender' => ['nullable', 'string', 'max:30'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:100'],
        ]);

        SystemSettings::set('sms_driver', $data['sms_driver']);
        SystemSettings::set('sms_config', [
            'apikey' => $data['apikey'] ?? '',
            'sender' => $data['sender'] ?? '',
            'username' => $data['username'] ?? '',
            'password' => $data['password'] ?? '',
        ]);

        return back()->with('success', 'تنظیمات پنل پیامک ذخیره شد.');
    }

    public function test(Request $request, SmsManager $sms)
    {
        $request->validate(['phone' => ['required', 'string']]);
        $phone = Phone::normalize($request->input('phone'));

        if (! Phone::isValidMobile($phone)) {
            return back()->with('error', 'شماره تلفن معتبر نیست.');
        }

        $ok = $sms->send($phone, 'پیام آزمایشی سامانه مدیریت ساختمان. اتصال پنل پیامک برقرار است.');

        return back()->with($ok ? 'success' : 'error',
            $ok ? 'پیام آزمایشی ارسال شد (در حالت تست، در فایل لاگ ثبت می‌شود).' : 'ارسال پیام آزمایشی ناموفق بود.');
    }
}
