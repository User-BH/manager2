<?php

namespace Tests\Unit;

use App\Services\Sms\IppanelSms;
use App\Services\Sms\LogSms;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IppanelSmsTest extends TestCase
{
    public function test_otp_is_sent_via_the_edge_pattern_endpoint_when_a_pattern_is_configured(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response(['meta' => ['status' => true]], 200)]);

        $driver = new IppanelSms([
            'apikey' => 'key-123',
            'sender' => '+983000505',
            'pattern_code' => 'pat-987',
            'pattern_variable' => 'code',
        ]);

        $this->assertTrue($driver->sendOtp('09120000010', '654321'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'edge.ippanel.com/v1/api/send')
                && $request['sending_type'] === 'pattern'
                && $request['code'] === 'pat-987'
                && $request['from_number'] === '+983000505'
                // شماره به E.164 تبدیل می‌شود
                && $request['recipients'] === ['+989120000010']
                && ($request['params']['code'] ?? null) === '654321'
                && $request->hasHeader('Authorization', 'key-123');
        });
    }

    public function test_a_configured_phonebook_id_saves_the_recipient(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response(['meta' => ['status' => true]], 200)]);

        $driver = new IppanelSms([
            'apikey' => 'key-123',
            'sender' => '+983000505',
            'pattern_code' => 'pat-987',
            'phonebook_id' => '1234',
        ]);

        $driver->sendOtp('09120000010', '654321');

        Http::assertSent(fn ($request) => ($request['phonebook']['id'] ?? null) === 1234);
    }

    public function test_without_a_pattern_it_falls_back_to_a_plain_webservice_message(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response(['meta' => ['status' => true]], 200)]);

        $driver = new IppanelSms(['apikey' => 'key-123', 'sender' => '+983000505']);

        $this->assertTrue($driver->sendOtp('09120000010', '654321'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'edge.ippanel.com/v1/api/send')
                && $request['sending_type'] === 'webservice'
                && str_contains($request['message'], '654321');
        });
    }

    public function test_a_meta_status_false_response_is_treated_as_failure(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response(['meta' => ['status' => false]], 200)]);

        $driver = new IppanelSms(['apikey' => 'key-123', 'sender' => '+983000505', 'pattern_code' => 'p']);

        $this->assertFalse($driver->sendOtp('09120000010', '654321'));
    }

    public function test_fallback_otp_message_carries_the_code_and_a_webotp_line(): void
    {
        // پیامِ پیش‌فرض هم کد را دارد هم خطِ WebOTP (@دامنه #کد) برای پرشدنِ خودکار
        $message = LogSms::otpMessage('654321');

        $this->assertStringContainsString('654321', $message);
        $this->assertMatchesRegularExpression('/@\S+ #654321\s*$/u', trim($message));
    }
}
