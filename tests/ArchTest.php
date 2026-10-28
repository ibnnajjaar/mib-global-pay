<?php

declare(strict_types=1);

arch('no debugging helpers are left in the source')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray'])
    ->not->toBeUsed();

arch('the package does not depend on the Laravel framework')
    ->expect('IbnNajjaar\MIBGlobalPay')
    ->not->toUse('Illuminate');
