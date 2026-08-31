<?php

declare(strict_types=1);

namespace App\Lsp\Support;

class Position
{
    /**
     * Determine if the given range contains the position.
     *
     * @param  array<string, mixed>|null  $range
     * @param  array<string, mixed>  $position
     */
    public static function inRange(?array $range, array $position): bool
    {
        if (!self::valid($position)) {
            return false;
        }

        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;

        if (!is_array($start) || !is_array($end)) {
            return false;
        }

        if (!self::valid($start) || !self::valid($end)) {
            return false;
        }

        return self::compare($position, $start) >= 0
            && self::compare($position, $end) < 0;
    }

    /**
     * Determine if the value is an LSP position.
     *
     * @param  array<string, mixed>  $position
     */
    protected static function valid(array $position): bool
    {
        return is_int($position['line'] ?? null)
            && is_int($position['character'] ?? null);
    }

    /**
     * Compare two LSP positions.
     *
     * @param  array{line: int, character: int}  $left
     * @param  array{line: int, character: int}  $right
     */
    protected static function compare(array $left, array $right): int
    {
        return $left['line'] <=> $right['line']
            ?: $left['character'] <=> $right['character'];
    }
}
