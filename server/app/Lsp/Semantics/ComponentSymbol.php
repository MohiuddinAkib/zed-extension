<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class ComponentSymbol
{
    public string $documentation;

    /**
     * @param array<string, ComponentPropSymbol> $props
     * @param array<string, SlotSymbol> $slots
     */
    public function __construct(
        public string $name,
        public string $tagName,
        public bool $isAnonymous = false,
        public ?string $className = null,
        public ?string $viewPath = null,
        public array $props = [],
        public array $slots = [],
        string $documentation = '',
        public ?string $description = null,
        public ?SourceRange $range = null,
    ) {
        $this->documentation = $documentation !== '' ? $documentation : ($description ?? "Blade component <{$this->tagName}>");
        $this->description = $this->documentation;
    }

    /**
     * Return list of deduplicated unique props.
     *
     * @return list<ComponentPropSymbol>
     */
    public function uniqueProps(): array
    {
        $unique = [];
        foreach ($this->props as $p) {
            $unique[$p->name] = $p;
        }
        return array_values($unique);
    }

    public function getProp(string $name): ?ComponentPropSymbol
    {
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
        $camel = lcfirst(str_replace('-', '', ucwords($name, '-')));

        return $this->props[$kebab] ?? $this->props[$camel] ?? $this->props[$name] ?? null;
    }

    public function getSlot(string $name): ?SlotSymbol
    {
        return $this->slots[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tagName' => $this->tagName,
            'isAnonymous' => $this->isAnonymous,
            'className' => $this->className,
            'viewPath' => $this->viewPath,
            'props' => array_map(fn (ComponentPropSymbol $p) => $p->toArray(), $this->props),
            'slots' => array_map(fn (SlotSymbol $s) => $s->toArray(), $this->slots),
            'documentation' => $this->documentation,
        ];
    }
}
