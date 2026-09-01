<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class MacroSymbol
{
    /**
     * @param  array<int, MacroParameterSymbol>  $parameters
     */
    public function __construct(
        public string $name,
        public string $targetClass,
        public ?string $facadeClass = null,
        public array $parameters = [],
        public ?TypeRef $returnType = null,
        public ?string $sourcePath = null,
        public ?int $sourceLine = null,
        public bool $isStatic = true,
        public string $documentation = '',
    ) {
        $this->returnType ??= TypeRef::fromString('mixed');
    }

    public function formattedSignature(): string
    {
        $paramsStr = implode(', ', array_map(fn (MacroParameterSymbol $p) => $p->formatted(), $this->parameters));
        $returnStr = $this->returnType ? ": {$this->returnType->displayName}" : '';

        return "{$this->name}({$paramsStr}){$returnStr}";
    }
}
