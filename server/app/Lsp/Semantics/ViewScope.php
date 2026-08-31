<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class ViewScope
{
    /**
     * @var array<string, VariableSymbol>
     */
    public array $variables = [];

    /**
     * @var list<string>
     */
    public array $sources = [];

    public function __construct(public string $key) {}

    /**
     * @param  array<string, mixed>  $viewData
     */
    public static function fromLegacy(string $key, array $viewData): self
    {
        $scope = new self($key);

        foreach ($viewData['sources'] ?? [] as $source) {
            if (is_string($source)) {
                $scope->addSource($source);
            }
        }

        foreach ($viewData['variables'] ?? [] as $name => $variable) {
            if (is_array($variable)) {
                $scope->addVariable(VariableSymbol::fromLegacy($variable, is_string($name) ? $name : null));
            }
        }

        return $scope;
    }

    public function addVariable(VariableSymbol $symbol): void
    {
        if ($symbol->name === '') {
            return;
        }

        if (isset($this->variables[$symbol->name])) {
            $this->variables[$symbol->name]->mergeWith($symbol);
        } else {
            $this->variables[$symbol->name] = $symbol;
        }

        foreach ($symbol->origins as $origin) {
            if ($origin->source !== null) {
                $this->addSource($origin->source);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $variables
     * @param  list<string>  $sources
     */
    public function addLegacyVariables(array $variables, array $sources = []): void
    {
        foreach ($sources as $source) {
            $this->addSource($source);
        }

        foreach ($variables as $name => $variable) {
            if (!is_array($variable)) {
                continue;
            }

            if (empty($variable['source']) && count($sources) === 1) {
                $variable['source'] = $sources[0];
            }

            $this->addVariable(VariableSymbol::fromLegacy($variable, is_string($name) ? $name : null));
        }
    }

    public function merge(self $scope): void
    {
        foreach ($scope->sources as $source) {
            $this->addSource($source);
        }

        foreach ($scope->variables as $symbol) {
            $this->addVariable($symbol);
        }
    }

    public function addSource(string $source): void
    {
        if (!in_array($source, $this->sources, true)) {
            $this->sources[] = $source;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function legacyVariables(): array
    {
        return array_map(fn (VariableSymbol $symbol): array => $symbol->toLegacyArray(), $this->variables);
    }

    /**
     * @return array{key: string, variables: array<string, array<string, mixed>>, sources: list<string>}
     */
    public function toLegacyArray(): array
    {
        return [
            'key' => $this->key,
            'variables' => $this->legacyVariables(),
            'sources' => $this->sources,
        ];
    }

    /**
     * @return array{key: string, variables: array<string, mixed>, sources: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'variables' => array_map(fn (VariableSymbol $symbol): array => $symbol->toLegacyArray(), $this->variables),
            'sources' => $this->sources,
        ];
    }
}
