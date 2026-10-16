<?php

namespace IbnNajjaar\MIBGlobalPay\Support;

final class Amount
{
    /**
     * Format an amount as the decimal string the gateway expects, e.g. "100.50".
     *
     * @param float|int|string $amount
     * @throws \InvalidArgumentException
     */
    public static function format($amount): string
    {
        self::validate($amount);

        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param mixed $amount
     * @throws \InvalidArgumentException
     */
    public static function validate($amount): void
    {
        if ($amount === null || $amount === '') {
            throw new \InvalidArgumentException('Amount is required');
        }

        if (is_string($amount)) {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                throw new \InvalidArgumentException('Amount must be a decimal number with at most 2 decimal places');
            }
        } elseif (!is_int($amount) && !is_float($amount)) {
            throw new \InvalidArgumentException('Amount must be numeric');
        }

        if ((float) $amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        if (is_float($amount)) {
            $amount_str = rtrim(rtrim((string) $amount, '0'), '.');
            if (strpos($amount_str, '.') !== false && strlen(substr($amount_str, strpos($amount_str, '.') + 1)) > 2) {
                throw new \InvalidArgumentException('Amount cannot have more than 2 decimal places');
            }
        }
    }
}
