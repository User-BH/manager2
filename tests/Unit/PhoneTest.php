<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public function test_normalises_various_formats_to_canonical(): void
    {
        $this->assertSame('09121234567', Phone::normalize('09121234567'));
        $this->assertSame('09121234567', Phone::normalize('+989121234567'));
        $this->assertSame('09121234567', Phone::normalize('0098 912 123 4567'));
        $this->assertSame('09121234567', Phone::normalize('989121234567'));
        $this->assertSame('09121234567', Phone::normalize('9121234567'));
    }

    public function test_converts_persian_digits(): void
    {
        $this->assertSame('09121234567', Phone::normalize('۰۹۱۲۱۲۳۴۵۶۷'));
    }

    public function test_validates_iranian_mobile(): void
    {
        $this->assertTrue(Phone::isValidMobile('0912 123 4567'));
        $this->assertTrue(Phone::isValidMobile('+989351234567'));
        $this->assertFalse(Phone::isValidMobile('021123456'));
        $this->assertFalse(Phone::isValidMobile('12345'));
        $this->assertFalse(Phone::isValidMobile(null));
    }
}
