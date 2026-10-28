<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| The 1.x test classes extend PHPUnit\Framework\TestCase directly and run
| unchanged under Pest. New v2 tests are written in Pest's functional style.
|
*/

uses(IbnNajjaar\MIBGlobalPay\Tests\Laravel\TestCase::class)->in('Laravel');
require_once __DIR__ . '/Laravel/Support/helpers.php';
