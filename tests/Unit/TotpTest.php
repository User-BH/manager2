<?php

namespace Tests\Unit;

use App\Services\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP ِ سوپر ادمین با بردارهای آزمونِ خودِ استاندارد (RFC 6238، پیوستِ B؛ SHA1). استاندارد هشت رقم می‌دهد؛ کدِ شش‌رقمی
 * همان شش رقمِ آخر است (`binary mod 10^6`).
 */
class TotpTest extends TestCase
{
    /** base32 ِ «12345678901234567890» — کلیدِ پیوستِ B */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function rfcVectors(): array
    {
        return [
            '59' => [59, '94287082'],
            '1111111109' => [1111111109, '07081804'],
            '1111111111' => [1111111111, '14050471'],
            '1234567890' => [1234567890, '89005924'],
            '2000000000' => [2000000000, '69279037'],
            '20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_6238_vectors(int $time, string $eightDigits): void
    {
        $this->assertSame(substr($eightDigits, -6), Totp::at(self::RFC_SECRET, Totp::step($time)));
    }

    public function test_base32_round_trips_and_matches_the_rfc_key(): void
    {
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));

        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame(32, strlen(Totp::generateSecret()));
    }

    public function test_verify_accepts_one_step_of_clock_drift_and_nothing_more(): void
    {
        $now = 1_800_000_000;
        $secret = Totp::generateSecret();
        $step = Totp::step($now);

        $this->assertSame($step, Totp::verify($secret, Totp::at($secret, $step), null, $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::at($secret, $step - 1), null, $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::at($secret, $step + 1), null, $now));
        $this->assertNull(Totp::verify($secret, Totp::at($secret, $step - 2), null, $now));
        $this->assertNull(Totp::verify($secret, Totp::at($secret, $step + 2), null, $now));
    }

    public function test_a_code_is_never_accepted_twice(): void
    {
        $now = 1_800_000_000;
        $secret = Totp::generateSecret();
        $step = Totp::step($now);
        $code = Totp::at($secret, $step);

        $accepted = Totp::verify($secret, $code, null, $now);
        $this->assertSame($step, $accepted);

        // همان کد در همان ۳۰ ثانیه، و کدِ گامِ قبلی، پس از پذیرش دیگر نه
        $this->assertNull(Totp::verify($secret, $code, $accepted, $now));
        $this->assertNull(Totp::verify($secret, Totp::at($secret, $step - 1), $accepted, $now));
        // گامِ بعدی بله
        $this->assertSame($step + 1, Totp::verify($secret, Totp::at($secret, $step + 1), $accepted, $now + 30));
    }

    public function test_malformed_codes_are_rejected(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', '12345', '1234567', 'abcdef', '12 34 5a', '۱۲۳۴۵۶'] as $code) {
            $this->assertNull(Totp::verify($secret, $code), "«{$code}» پذیرفته شد.");
        }
    }

    public function test_the_uri_carries_the_standard_parameters(): void
    {
        $uri = Totp::uri('ABCDEFGH', 'Sakena', 'sakena.admin');

        $this->assertStringStartsWith('otpauth://totp/Sakena:sakena.admin?', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $this->assertSame(['secret' => 'ABCDEFGH', 'issuer' => 'Sakena', 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30'], $query);
    }
}
