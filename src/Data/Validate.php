<?php

declare(strict_types=1);

namespace Maxx\Ai\Data;

final class Validate
{
    public static function identifier(string $value): string
    {
        if (!preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $value)) {
            throw new \InvalidArgumentException('Expected a contract identifier of 1-255 characters.');
        }

        return $value;
    }

    public static function decimal(string $value): string
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,12})?\z/', $value)
            || (strlen(explode('.', $value)[0]) === 13 && $value !== '1000000000000'
                && !preg_match('/\A1000000000000\.0+\z/', $value))) {
            throw new \InvalidArgumentException('Expected a nonnegative decimal string <= 1000000000000 with at most 12 decimal places.');
        }

        return $value;
    }

    public static function hash(string $value): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            throw new \InvalidArgumentException('Expected a SHA-256 hexadecimal digest.');
        }

        return $value;
    }

    public static function storedDecimal(string $value): string
    {
        if (preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]+))?[eE]([+-]?[0-9]{1,3})\z/', $value, $parts)) {
            $exponent = (int) $parts[3];
            if ($exponent < -12 || $exponent > 37 || strlen($parts[1]) > 38 || strlen($parts[2]) > 12) {
                throw new \InvalidArgumentException('Decimal exponent exceeds storage bounds.');
            }
            $digits = $parts[1].$parts[2];
            $point = strlen($parts[1]) + $exponent;
            if ($point <= 0) {
                $value = '0.'.str_repeat('0', -$point).$digits;
            } elseif ($point >= strlen($digits)) {
                $value = $digits.str_repeat('0', $point - strlen($digits));
            } else {
                $value = substr($digits, 0, $point).'.'.substr($digits, $point);
            }
            $value = ltrim($value, '0');
            if ($value === '' || str_starts_with($value, '.')) {
                $value = '0'.$value;
            }
        }
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,37})(?:\.[0-9]{1,12})?\z/', $value)) {
            throw new \InvalidArgumentException('Expected a nonnegative Numeric(50,12) decimal string.');
        }

        return $value;
    }
}
