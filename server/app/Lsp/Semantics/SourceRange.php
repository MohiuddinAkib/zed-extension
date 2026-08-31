<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class SourceRange
{
    public function __construct(
        public int $startLine = 1,
        public int $startCharacter = 0,
        public int $endLine = 1,
        public int $endCharacter = 0,
    ) {}

    public static function line(int $line): self
    {
        return new self($line, 0, $line, 0);
    }

    /**
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    public function toArray(): array
    {
        return [
            'start' => [
                'line' => $this->startLine,
                'character' => $this->startCharacter,
            ],
            'end' => [
                'line' => $this->endLine,
                'character' => $this->endCharacter,
            ],
        ];
    }
}
