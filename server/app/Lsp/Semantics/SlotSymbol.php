<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class SlotSymbol
{
    public string $documentation;

    /**
     * @param array<string, VariableSymbol> $scopedVariables
     */
    public function __construct(
        public string $name,
        public array $scopedVariables = [],
        string $documentation = '',
        public ?string $description = null,
        public ?SourceRange $range = null,
    ) {
        $this->documentation = $documentation !== '' ? $documentation : ($description ?? "Slot {$this->name}");
        $this->description = $this->documentation;
    }

    /**
     * @return array{name: string, scopedVariables: array<string, array<string, mixed>>, documentation: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'scopedVariables' => array_map(fn (VariableSymbol $v) => $v->toLegacyArray(), $this->scopedVariables),
            'documentation' => $this->documentation,
        ];
    }
}
