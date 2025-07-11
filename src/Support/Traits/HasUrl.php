<?php

namespace IbnNajjaar\MIBGlobalPay\Support\Traits;

trait HasUrl
{
    private function validateUrl(?string $url, ?string $fieldName, bool $required = false)
    {
        if (! $required && empty($url)) {
            return;
        }

        if (empty($url)) {
            throw new \InvalidArgumentException("$fieldName is required");
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("$fieldName must be a valid URL");
        }
    }
}
