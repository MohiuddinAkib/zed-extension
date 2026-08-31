<?php

declare(strict_types=1);

namespace App\Lsp\Support;

class Utf16Position
{
    /**
     * Get length of string in UTF-16 code units.
     */
    public static function length(string $str): int
    {
        if ($str === '') {
            return 0;
        }

        $utf16Bytes = mb_convert_encoding($str, 'UTF-16LE', 'UTF-8');
        return intdiv(strlen($utf16Bytes), 2);
    }

    /**
     * Substring based on UTF-16 code unit offset and length.
     */
    public static function substr(string $str, int $utf16Offset, ?int $utf16Length = null): string
    {
        if ($str === '') {
            return '';
        }

        $utf16Bytes = mb_convert_encoding($str, 'UTF-16LE', 'UTF-8');
        $byteOffset = max(0, $utf16Offset * 2);
        $totalBytes = strlen($utf16Bytes);

        if ($byteOffset >= $totalBytes) {
            return '';
        }

        if ($utf16Length !== null) {
            $byteLength = max(0, $utf16Length * 2);
            $subBytes = substr($utf16Bytes, $byteOffset, $byteLength);
        } else {
            $subBytes = substr($utf16Bytes, $byteOffset);
        }

        return mb_convert_encoding($subBytes, 'UTF-8', 'UTF-16LE');
    }

    /**
     * Convert byte offset within a line to UTF-16 code unit column.
     */
    public static function byteOffsetToUtf16Column(string $lineContent, int $byteOffset): int
    {
        if ($byteOffset <= 0 || $lineContent === '') {
            return 0;
        }

        $sub = substr($lineContent, 0, $byteOffset);
        return self::length($sub);
    }

    /**
     * Convert UTF-16 code unit column on a line to byte offset.
     */
    public static function utf16ColumnToByteOffset(string $lineContent, int $utf16Col): int
    {
        if ($utf16Col <= 0 || $lineContent === '') {
            return 0;
        }

        $sub = self::substr($lineContent, 0, $utf16Col);
        return strlen($sub);
    }
}
