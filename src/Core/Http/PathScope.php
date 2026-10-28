<?php

declare(strict_types=1);

namespace IbnNajjaar\MIBGlobalPay\Core\Http;

/**
 * What an operation's path is relative to.
 */
enum PathScope
{
    /** https://{host}/api/rest/version/{v}/merchant/{merchantId}/{path}, with merchant authentication. */
    case Merchant;

    /** https://{host}/api/rest/version/{v}/{path}, without authentication (e.g. Check Gateway). */
    case Gateway;
}
