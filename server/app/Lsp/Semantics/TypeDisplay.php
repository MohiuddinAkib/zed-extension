<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class TypeDisplay
{
    public static function nativePhpType(TypeRef|string|null $type): string
    {
        $type = $type instanceof TypeRef ? (string) $type : (string) $type;
        $type = trim($type) !== '' ? trim($type) : 'mixed';

        if (str_contains($type, "'") || str_contains($type, '"')) {
            return 'string';
        }

        if (str_contains($type, '<') || str_contains($type, '[') || str_contains($type, '{')) {
            return 'array';
        }

        if (str_contains($type, '|') || str_contains($type, '&')) {
            $parts = array_values(array_filter(array_map('trim', preg_split('/[|&]/', $type) ?: []), fn (string $part): bool => $part !== 'null' && $part !== ''));

            if (count($parts) === 1) {
                return self::nativePhpType($parts[0]);
            }

            return 'mixed';
        }

        if (str_starts_with($type, '?')) {
            return '?' . self::nativePhpType(substr($type, 1));
        }

        return $type;
    }
}
