<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Tests are written in Pest's functional style. Laravel-layer tests run on
| Orchestra Testbench (see tests/Laravel/TestCase.php).
|
*/

uses(IbnNajjaar\MIBGlobalPay\Tests\Laravel\TestCase::class)->in('Laravel');
require_once __DIR__ . '/Laravel/Support/helpers.php';
