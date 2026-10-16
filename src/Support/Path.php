<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

final class Path
{
    /**
     * Encode a value for use as a single URL path segment.
     */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
