<?php

namespace Tests\Unit;

use App\Services\Sms\IppanelSms;
use App\Services\Sms\LogSms;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IppanelSmsTest extends TestCase
{
    public function test_otp_is_sent_via_the_pattern_endpoint_when_a_pattern_is_configured(): void
    {
        Http::fake(['api2.ippanel.com/*' => Http::response(['status' => 'OK'], 200)]);

        $driver = new IppanelSms([
            'apikey' => 'key-123',
            'sender' => '+983000505',
            'pattern_code' => 'pat-987',
            'pattern_variable' => 'code',
        ]);

        $this->assertTrue($driver->sendOtp('09120000010', '654321'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sms/pattern/normal/send')
                && $request['code'] === 'pat-987'
                && $request['recipient'] === '09120000010'
                && ($request['variable']['code'] ?? null) === '654321';
        });
    }

    public function test_without_a_pattern_it_falls_back_to_a_plain_message(): void
    {
        Http::fake(['api2.ippanel.com/*' => Http::response(['status' => 'OK'], 200)]);

        $driver = new IppanelSms(['apikey' => 'key-123', 'sender' => '+983000505']);

        $this->assertTrue($driver->sendOtp('09120000010', '654321'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sms/send/webservice/single'));
    }

    public function test_fallback_otp_message_carries_the_code_and_a_webotp_line(): void
    {
        // پیامِ پیش‌فرض هم کد را دارد هم خطِ WebOTP (@دامنه #کد) برای پرشدنِ خودکار
        $message = LogSms::otpMessage('654321');

        $this->assertStringContainsString('654321', $message);
        $this->assertMatchesRegularExpression('/@\S+ #654321\s*$/u', trim($message));
    }
}
