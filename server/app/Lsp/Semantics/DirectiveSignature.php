<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class DirectiveSignature
{
    /**
     * @param list<array{name: string, type: TypeRef, optional?: bool, documentation?: string}> $parameters
     */
    public function __construct(
        public string $name,
        public array $parameters = [],
        public string $documentation = '',
        public ?string $snippet = null,
    ) {}

    public function formatSignature(): string
    {
        $params = array_map(function (array $p): string {
            $prefix = ($p['optional'] ?? false) ? '?' : '';
            $type = isset($p['type']) ? (string) $p['type'] . ' ' : '';
            return "{$type}\${$prefix}{$p['name']}";
        }, $this->parameters);

        return "@{$this->name}(" . implode(', ', $params) . ')';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'parameters' => $this->parameters,
            'documentation' => $this->documentation,
            'snippet' => $this->snippet,
            'signature' => $this->formatSignature(),
        ];
    }
}
