<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\SMS\GEOSMS;

use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Tests\Adapter\Base;

final class CallingCodeTest extends Base
{
    public function testFromPhoneNumber(): void
    {
        $this->assertSame(CallingCode::NORTH_AMERICA, CallingCode::fromPhoneNumber('+11234567890'));
        $this->assertSame(CallingCode::INDIA, CallingCode::fromPhoneNumber('+911234567890'));
        $this->assertSame(CallingCode::ISRAEL, CallingCode::fromPhoneNumber('9721234567890'));
        $this->assertSame(CallingCode::UNITED_ARAB_EMIRATES, CallingCode::fromPhoneNumber('009711234567890'));
        $this->assertSame(CallingCode::UNITED_KINGDOM, CallingCode::fromPhoneNumber('011441234567890'));
        $this->assertEquals(null, CallingCode::fromPhoneNumber('2'));
    }

    public function testSwissMobileIsSwitzerland(): void
    {
        $this->assertSame(CallingCode::SWITZERLAND, CallingCode::fromPhoneNumber('+41791234567'));
        $this->assertSame(CallingCode::LIECHTENSTEIN, CallingCode::fromPhoneNumber('+4237891234'));
    }

    public function testCodesArePrefixFree(): void
    {
        $codes = array_unique(array_values(new \ReflectionClass(CallingCode::class)->getConstants(\ReflectionClassConstant::IS_PUBLIC)));

        foreach ($codes as $code) {
            foreach ($codes as $other) {
                $this->assertFalse(
                    $code !== $other && str_starts_with((string) $code, (string) $other),
                    "Calling code {$code} shadows {$other}",
                );
            }
        }
    }
}
