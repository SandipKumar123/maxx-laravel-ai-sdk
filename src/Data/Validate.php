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
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,37})(?:\.[0-9]{1,12})?\z/', $value)) {
            throw new \InvalidArgumentException('Expected a nonnegative Numeric(50,12) decimal string.');
        }

        return $value;
    }
}
