<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Http;

enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Delete = 'DELETE';
}
