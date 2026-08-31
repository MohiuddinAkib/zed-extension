<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class SourceMap
{
    /**
     * @var list<array{
     *     bladeStart: int,
     *     bladeEnd: int,
     *     virtualStart: int,
     *     virtualEnd: int,
     *     bladeLine: int,
     *     bladeCol: int,
     *     virtualLine: int,
     *     virtualCol: int
     * }>
     */
    protected array $mappings = [];

    /**
     * @var list<int>
     */
    protected array $bladeLineOffsets = [0];

    /**
     * @var list<int>
     */
    protected array $virtualLineOffsets = [0];

    /**
     * @var list<string>
     */
    protected array $bladeLines = [];

    /**
     * @var list<string>
     */
    protected array $virtualLines = [];

    public function __construct(string $bladeContent = '', string $virtualContent = '')
    {
        if ($bladeContent !== '') {
            $this->bladeLineOffsets = $this->calculateLineOffsets($bladeContent);
            $this->bladeLines = explode("\n", $bladeContent);
        }
        if ($virtualContent !== '') {
            $this->virtualLineOffsets = $this->calculateLineOffsets($virtualContent);
            $this->virtualLines = explode("\n", $virtualContent);
        }
    }

    /**
     * Register a contiguous mapped segment between Blade content and Virtual PHP content.
     */
    public function addMapping(int $bladeStart, int $virtualStart, int $length): void
    {
        if ($length <= 0) {
            return;
        }

        $bladeLoc = $this->offsetToLineAndCol($bladeStart, $this->bladeLineOffsets, $this->bladeLines);
        $virtualLoc = $this->offsetToLineAndCol($virtualStart, $this->virtualLineOffsets, $this->virtualLines);

        $this->mappings[] = [
            'bladeStart' => $bladeStart,
            'bladeEnd' => $bladeStart + $length,
            'virtualStart' => $virtualStart,
            'virtualEnd' => $virtualStart + $length,
            'bladeLine' => $bladeLoc['line'],
            'bladeCol' => $bladeLoc['col'],
            'virtualLine' => $virtualLoc['line'],
            'virtualCol' => $virtualLoc['col'],
        ];
    }

    /**
     * Map a Blade offset to its corresponding Virtual PHP offset using half-open ranges [start, end).
     */
    public function bladeToVirtualOffset(int $bladeOffset): ?int
    {
        $exactEndMatch = null;
        foreach ($this->mappings as $m) {
            if ($bladeOffset >= $m['bladeStart'] && $bladeOffset < $m['bladeEnd']) {
                $delta = $bladeOffset - $m['bladeStart'];
                return $m['virtualStart'] + $delta;
            }
            if ($bladeOffset === $m['bladeEnd']) {
                $exactEndMatch = $m['virtualEnd'];
            }
        }

        return $exactEndMatch;
    }

    /**
     * Map a Virtual PHP offset to its corresponding Blade offset using half-open ranges [start, end).
     */
    public function virtualToBladeOffset(int $virtualOffset): ?int
    {
        $exactEndMatch = null;
        foreach ($this->mappings as $m) {
            if ($virtualOffset >= $m['virtualStart'] && $virtualOffset < $m['virtualEnd']) {
                $delta = $virtualOffset - $m['virtualStart'];
                return $m['bladeStart'] + $delta;
            }
            if ($virtualOffset === $m['virtualEnd']) {
                $exactEndMatch = $m['bladeEnd'];
            }
        }

        return $exactEndMatch;
    }

    /**
     * Map a Blade position (0-indexed line, character in UTF-16 code units) to Virtual PHP position.
     *
     * @return array{line: int, character: int}|null
     */
    public function bladeToVirtualPosition(int $line, int $character): ?array
    {
        $offset = $this->lineAndColToOffset($line, $character, $this->bladeLineOffsets, $this->bladeLines);
        $virtualOffset = $this->bladeToVirtualOffset($offset);

        if ($virtualOffset === null) {
            return null;
        }

        $loc = $this->offsetToLineAndCol($virtualOffset, $this->virtualLineOffsets, $this->virtualLines);
        return [
            'line' => $loc['line'],
            'character' => $loc['col'],
        ];
    }

    /**
     * Map a Virtual PHP position (0-indexed line, character in UTF-16 code units) to Blade position.
     *
     * @return array{line: int, character: int}|null
     */
    public function virtualToBladePosition(int $line, int $character): ?array
    {
        $offset = $this->lineAndColToOffset($line, $character, $this->virtualLineOffsets, $this->virtualLines);
        $bladeOffset = $this->virtualToBladeOffset($offset);

        if ($bladeOffset === null) {
            return null;
        }

        $loc = $this->offsetToLineAndCol($bladeOffset, $this->bladeLineOffsets, $this->bladeLines);
        return [
            'line' => $loc['line'],
            'character' => $loc['col'],
        ];
    }

    /**
     * Map a Virtual LSP range to a Blade LSP range.
     *
     * @param  array{start: array{line: int, character: int}, end: array{line: int, character: int}}  $virtualRange
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}|null
     */
    public function virtualRangeToBlade(array $virtualRange): ?array
    {
        $startLine = $virtualRange['start']['line'] ?? 0;
        $startChar = $virtualRange['start']['character'] ?? 0;
        $endLine = $virtualRange['end']['line'] ?? $startLine;
        $endChar = $virtualRange['end']['character'] ?? $startChar;

        $bladeStart = $this->virtualToBladePosition($startLine, $startChar);
        $bladeEnd = $this->virtualToBladePosition($endLine, $endChar);

        if ($bladeStart === null || $bladeEnd === null) {
            return null;
        }

        return [
            'start' => $bladeStart,
            'end' => $bladeEnd,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMappings(): array
    {
        return $this->mappings;
    }

    /**
     * @return list<int>
     */
    protected function calculateLineOffsets(string $content): array
    {
        $offsets = [0];
        $pos = 0;
        while (($pos = strpos($content, "\n", $pos)) !== false) {
            $pos++;
            $offsets[] = $pos;
        }
        return $offsets;
    }

    /**
     * Convert byte offset to 0-indexed line and UTF-16 character column.
     *
     * @param  list<int>  $lineOffsets
     * @param  list<string>  $lines
     * @return array{line: int, col: int}
     */
    protected function offsetToLineAndCol(int $offset, array $lineOffsets, array $lines = []): array
    {
        $low = 0;
        $high = count($lineOffsets) - 1;
        $line = 0;

        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            if ($lineOffsets[$mid] <= $offset) {
                $line = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        $byteCol = max(0, $offset - $lineOffsets[$line]);
        $lineText = $lines[$line] ?? '';
        $utf16Col = self::byteToUtf16Col($lineText, $byteCol);

        return ['line' => $line, 'col' => $utf16Col];
    }

    /**
     * Convert 0-indexed line and UTF-16 character column to byte offset.
     *
     * @param  list<int>  $lineOffsets
     * @param  list<string>  $lines
     */
    protected function lineAndColToOffset(int $line, int $col, array $lineOffsets, array $lines = []): int
    {
        if (!isset($lineOffsets[$line])) {
            return end($lineOffsets) ?: 0;
        }

        $lineText = $lines[$line] ?? '';
        $byteCol = self::utf16ToByteCol($lineText, $col);

        return $lineOffsets[$line] + $byteCol;
    }

    public static function byteToUtf16Col(string $line, int $byteCol): int
    {
        if ($byteCol <= 0 || $line === '') {
            return 0;
        }
        $sub = substr($line, 0, $byteCol);
        return (int) (strlen(mb_convert_encoding($sub, 'UTF-16LE', 'UTF-8')) / 2);
    }

    public static function utf16ToByteCol(string $line, int $utf16Col): int
    {
        if ($utf16Col <= 0 || $line === '') {
            return 0;
        }
        $utf16 = mb_convert_encoding($line, 'UTF-16LE', 'UTF-8');
        $sub16 = substr($utf16, 0, $utf16Col * 2);
        return strlen(mb_convert_encoding($sub16, 'UTF-8', 'UTF-16LE'));
    }
}
