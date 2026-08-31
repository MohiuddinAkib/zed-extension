<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class ComponentPropSymbol
{
    public string $documentation;

    public function __construct(
        public string $name,
        public TypeRef $type,
        public bool $required = false,
        public mixed $defaultValue = null,
        string $documentation = '',
        public ?string $description = null,
        public ?SourceRange $range = null,
    ) {
        $this->documentation = $documentation !== '' ? $documentation : ($description ?? '');
        $this->description = $this->documentation;
    }

    public function kebabName(): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $this->name));
    }

    public function camelName(): string
    {
        return lcfirst(str_replace('-', '', ucwords($this->name, '-')));
    }

    /**
     * @return array{name: string, kebabName: string, type: array<string, mixed>, required: bool, defaultValue: mixed, documentation: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kebabName' => $this->kebabName(),
            'type' => $this->type->toArray(),
            'required' => $this->required,
            'defaultValue' => $this->defaultValue,
            'documentation' => $this->documentation,
        ];
    }
}
