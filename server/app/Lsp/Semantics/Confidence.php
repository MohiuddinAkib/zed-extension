<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public static function lowest(self $left, self $right): self
    {
        return $left->rank() <= $right->rank() ? $left : $right;
    }
}
