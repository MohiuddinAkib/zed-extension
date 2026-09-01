<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class MacroParameterSymbol
{
    public function __construct(
        public string $name,
        public TypeRef $type,
        public bool $required = true,
        public ?string $defaultValue = null,
        public ?string $description = null,
    ) {}

    public function formatted(): string
    {
        $typeStr = $this->type->displayName !== 'mixed' ? "{$this->type->displayName} " : '';
        $defaultStr = $this->defaultValue !== null ? " = {$this->defaultValue}" : '';

        return "{$typeStr}\${$this->name}{$defaultStr}";
    }
}
