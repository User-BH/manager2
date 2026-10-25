<?php

namespace App\Services\Auth;

/**
 * رمزِ یک‌بارمصرفِ زمان‌محور — RFC 6238 (TOTP) روی RFC 4226 (HOTP)، با HMAC-SHA1، شش رقم و گامِ ۳۰ ثانیه؛ همان
 * پیش‌فرضی که Google Authenticator، Microsoft Authenticator، 1Password و Bitwarden می‌فهمند.
 *
 * بی بسته‌ی تازه: الگوریتم چند خط است و با بردارهای آزمونِ پیوستِ B ِ خودِ RFC 6238 سنجیده می‌شود (`TotpTest`).
 *
 * 🔴 پذیرش فقط برای گامِ **بزرگ‌تر** از آخرین گامِ پذیرفته‌شده: کدی که یک بار پذیرفته شد — حتی در همان ۳۰ ثانیه —
 * دوباره پذیرفته نمی‌شود (replay). پنجره‌ی ±۱ گام فقط برای اختلافِ ساعتِ گوشی است.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** یک گام پیش و پس — اختلافِ ساعتِ حداکثر حدودِ ۳۰ ثانیه */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** کلیدِ تازه: ۱۶۰ بیتِ تصادفی (طولِ پیشنهادیِ RFC 4226 برای SHA1)، به base32 */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /** کدِ شش‌رقمیِ یک گام — RFC 4226 بخشِ ۵.۳ */
    public static function at(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * گامِ کدِ درست در پنجره، یا null. هر گام با `hash_equals` سنجیده می‌شود و همه‌ی گام‌های پنجره همیشه سنجیده
     * می‌شوند تا زمانِ پاسخ نگوید کدام گام نزدیک بود.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $now = self::step($timestamp);
        $matched = null;
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $now + $offset;
            if (hash_equals(self::at($secret, $step), $code) && ($lastStep === null || $step > $lastStep) && $matched === null) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /**
     * نشانیِ `otpauth://` برای QR ِ برنامه‌ی Authenticator (قالبِ Key Uri ِ Google Authenticator).
     */
    public static function uri(string $secret, string $issuer, string $account): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', $text) ?? '');

        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
