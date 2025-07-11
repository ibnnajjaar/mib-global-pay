<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

interface IsResponseData
{

    public static function fromArray(array $response): self;
}
