<?php

namespace Tests\Unit;

use App\Support\ChileanRut;
use PHPUnit\Framework\TestCase;

class ChileanRutTest extends TestCase
{
    public function test_normalize_formats_rut_consistently(): void
    {
        $this->assertSame('11111111-1', ChileanRut::normalize('11.111.111-1'));
        $this->assertSame('11111111-1', ChileanRut::normalize('111111111'));
        $this->assertSame('76086428-5', ChileanRut::normalize('76.086.428-5'));
    }

    public function test_validates_rut_check_digit(): void
    {
        $this->assertTrue(ChileanRut::isValid('76086428-5'));
        $this->assertTrue(ChileanRut::isValid('76.086.428-5'));

        $this->assertFalse(ChileanRut::isValid('76086428-4'));
        $this->assertFalse(ChileanRut::isValid(''));
    }
}
