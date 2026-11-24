<?php

namespace App\Support;

/**
 * Money is integer nanodollars. Token costs are exact: tokens * (USD per 1M tokens) = tokens * rate * 1000 nanodollars.
 */
class Money
{
    public const MARKUP_PERCENT = 25;

    /** Cost in nanodollars of $tokens at $rate (decimal string, USD per 1M tokens). Exact for rates with up to 3 decimals. */
    public static function tokenCost(int $tokens, string $rate): int
    {
        return (int) bcmul(bcmul((string) $tokens, $rate, 6), '1000', 0);
    }

    /** What the user pays for a given provider cost: cost + 25%, rounded up to a whole nanodollar. */
    public static function withMarkup(int $providerNano): int
    {
        return intdiv($providerNano * (100 + self::MARKUP_PERCENT) + 99, 100);
    }

    /** Exact decimal string with 9 places, e.g. 0.046125000. */
    public static function decimal(int $nano): string
    {
        $neg = $nano < 0;
        $nano = abs($nano);
        $s = intdiv($nano, 1_000_000_000).'.'.str_pad((string) ($nano % 1_000_000_000), 9, '0', STR_PAD_LEFT);

        return ($neg ? '-' : '').$s;
    }

    /** Parse a decimal string like "0.046125000" into nanodollars. */
    public static function fromDecimal(string $s): int
    {
        return (int) bcmul($s, '1000000000', 0);
    }
}
