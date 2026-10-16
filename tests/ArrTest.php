<?php

namespace IbnNajjaar\MIBGlobalPay\Tests;

use PHPUnit\Framework\TestCase;
use IbnNajjaar\MIBGlobalPay\Support\Arr;

class ArrTest extends TestCase
{
    public function test_get_reads_nested_values()
    {
        $data = ['response' => ['gatewayCode' => 'APPROVED'], 'a' => ['b' => ['c' => 0]]];

        $this->assertSame('APPROVED', Arr::get($data, 'response.gatewayCode'));
        $this->assertSame(0, Arr::get($data, 'a.b.c'));
        $this->assertSame(['gatewayCode' => 'APPROVED'], Arr::get($data, 'response'));
    }

    public function test_get_returns_default_for_missing_segments()
    {
        $data = ['response' => null, 'result' => 'SUCCESS'];

        $this->assertNull(Arr::get($data, 'response.gatewayCode'));
        $this->assertNull(Arr::get($data, 'result.value'));
        $this->assertSame('x', Arr::get($data, 'missing.key', 'x'));
        $this->assertNull(Arr::get([], 'anything'));
    }

    public function test_lower()
    {
        $this->assertSame('captured', Arr::lower('CAPTURED'));
        $this->assertSame('100', Arr::lower(100));
        $this->assertNull(Arr::lower(null));
        $this->assertNull(Arr::lower(''));
        $this->assertNull(Arr::lower(['a']));
        $this->assertNull(Arr::lower(true));
    }

    public function test_string_and_float()
    {
        $this->assertSame('1', Arr::string(1));
        $this->assertNull(Arr::string(null));
        $this->assertSame(25.5, Arr::float('25.50'));
        $this->assertSame(10.0, Arr::float(10));
        $this->assertNull(Arr::float('abc'));
        $this->assertNull(Arr::float(null));
    }
}
