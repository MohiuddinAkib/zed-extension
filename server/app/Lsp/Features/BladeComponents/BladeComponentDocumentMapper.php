<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeComponents;

use App\Lsp\Document;
use App\Lsp\Support\Position;
use App\Lsp\Support\Utf16Position;
use App\Lsp\Project;
use Illuminate\Support\Collection;

class BladeComponentDocumentMapper
{
    protected \App\Lsp\Analysis\ComponentRegistry $componentRegistry;

    /**
     * Create a new Blade component document mapper instance.
     */
    public function __construct(
        protected Project $project,
        ?\App\Lsp\Analysis\ComponentRegistry $componentRegistry = null,
    ) {
        $this->componentRegistry = $componentRegistry ?? (
            \Illuminate\Container\Container::getInstance()->bound(\App\Lsp\Analysis\ComponentRegistry::class)
                ? \Illuminate\Container\Container::getInstance()->make(\App\Lsp\Analysis\ComponentRegistry::class)
                : new \App\Lsp\Analysis\ComponentRegistry($this->project)
        );
    }

    /**
     * Get Blade component document links.
     *
     * @return array<int, array<string, mixed>>
     */
    public function links(Document $document): array
    {
        return collect($this->matches($document))
            ->map(function (array $match): ?array {
                $component = $this->component($match['name']);
                $path = $this->path($component);

                return $path ? $this->project->link($match['range'], $path) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get Blade component hover for the given position.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function hover(Document $document, array $position): ?array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $line = explode("\n", $document->content)[$lineNumber] ?? '';

        // Check if hovering over a prop attribute within a component tag
        if (preg_match_all('/<x-([a-zA-Z0-9_.:-]+)([^>]*)>/', $line, $tagMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($tagMatches as $tm) {
                $tagName = $tm[1][0];
                $attrsText = $tm[2][0];
                $attrsOffset = $tm[2][1];
                if ($character >= $attrsOffset && $character <= $attrsOffset + strlen($attrsText)) {
                    // Find attribute at character position
                    if (preg_match_all('/(:?)([a-zA-Z0-9_-]+)(?:=("[^"]*"|\'[^\']*\'))?/', $attrsText, $attrMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                        foreach ($attrMatches as $am) {
                            $attrStart = $attrsOffset + $am[0][1];
                            $attrEnd = $attrStart + strlen($am[0][0]);
                            if ($character >= $attrStart && $character <= $attrEnd) {
                                $isBound = $am[1][0] === ':';
                                $attrName = $am[2][0];
                                $symbol = $this->componentRegistry->getComponent($tagName);
                                if ($symbol && isset($symbol->props[$attrName])) {
                                    $prop = $symbol->props[$attrName];
                                    $reqStr = $prop->required ? ' *(required)*' : ' *(optional)*';
                                    $defStr = $prop->defaultValue !== null ? " = `{$prop->defaultValue}`" : '';
                                    $lines = [
                                        "**Component Prop:** `\${$prop->name}`{$reqStr}",
                                        "**Type:** `{$prop->type->displayName}`{$defStr}",
                                        $prop->description,
                                        "*Component:* `<x-{$tagName}>`",
                                    ];
                                    $range = [
                                        'start' => ['line' => $lineNumber, 'character' => $attrStart],
                                        'end' => ['line' => $lineNumber, 'character' => $attrEnd],
                                    ];
                                    return $this->markdownHover($range, $lines);
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($this->matches($document) as $match) {
            if (!Position::inRange($match['range'], $position)) {
                continue;
            }

            $component = $this->component($match['name']);
            $symbol = $this->componentRegistry->getComponent($match['name']);

            $lines = [];
            if ($symbol) {
                $lines[] = "**{$symbol->description}**";
                if (!empty($symbol->props)) {
                    $lines[] = "### Props:";
                    foreach ($symbol->uniqueProps() as $p) {
                        $req = $p->required ? ' *(required)*' : '';
                        $def = $p->defaultValue !== null ? " = {$p->defaultValue}" : '';
                        $lines[] = "- `{$p->name}` (`{$p->type->displayName}`){$def}{$req}";
                    }
                }
                if ($symbol->viewPath) {
                    $lines[] = $this->markdownPath($symbol->viewPath);
                }
            } elseif (is_array($component)) {
                $lines = collect($component['paths'] ?? [])
                    ->filter(fn (mixed $path): bool => is_string($path))
                    ->map(fn (string $path): string => $this->markdownPath($path))
                    ->all();

                if (is_string($component['props'] ?? null)) {
                    $lines[] = "```blade\n{$component['props']}\n```";
                } elseif (is_iterable($component['props'] ?? null)) {
                    foreach ($component['props'] as $prop) {
                        if (!is_array($prop)) {
                            continue;
                        }

                        $default = isset($prop['default']) && $prop['default'] !== null ? " = {$prop['default']}" : '';
                        $lines[] = '`' . ($prop['type'] ?? 'mixed') . '` `' . ($prop['name'] ?? '') . "`{$default}";
                    }
                }
            }

            return $lines === [] ? null : $this->markdownHover($match['range'], $lines);
        }

        return null;
    }

    /**
     * Get Blade component completions (tags, props, slots).
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function completions(Document $document, array $position): array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (!is_int($lineNumber) || !is_int($character)) {
            return [];
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';
        $linePrefix = Utf16Position::substr($line, 0, $character);

        // 1. Attribute / Prop completion inside component tag
        if (preg_match('/<x-([a-zA-Z0-9_.:-]+)(?:\s+[^>]*)?\s+(:?)([a-zA-Z0-9_-]*)$/', $linePrefix, $propMatch)) {
            $tagName = $propMatch[1];
            $isBound = $propMatch[2] === ':';
            $propPrefix = $propMatch[3];
            $symbol = $this->componentRegistry->getComponent($tagName);

            if ($symbol && !empty($symbol->props)) {
                $completions = [];
                foreach ($symbol->uniqueProps() as $prop) {
                    $name = $prop->kebabName();
                    if ($propPrefix !== '' && !str_starts_with(strtolower($name), strtolower($propPrefix))) {
                        continue;
                    }

                    $insertText = "{$name}=\"$1\"";
                    $completions[] = [
                        'label' => ($isBound ? ':' : '') . $name,
                        'kind' => 10, // Property
                        'detail' => $prop->type->displayName . ($prop->required ? ' (required)' : ''),
                        'documentation' => [
                            'kind' => 'markdown',
                            'value' => $prop->description . ($prop->defaultValue !== null ? "\n\n*Default:* `{$prop->defaultValue}`" : ''),
                        ],
                        'insertText' => $insertText,
                        'insertTextFormat' => 2, // Snippet format
                    ];
                }

                if (!empty($completions)) {
                    return $completions;
                }
            }
        }

        // 2. Slot completion for <x-slot:name> or <x-slot
        if (preg_match('/<x-slot(?::([a-zA-Z0-9_-]*))?$/', $linePrefix, $slotMatch)) {
            $slotPrefix = $slotMatch[1] ?? '';
            $completions = [];
            $seenSlots = [];

            // Detect parent enclosing <x-component-name>
            $parentComponent = $this->findParentComponentTag($lines, $lineNumber);
            if ($parentComponent !== null) {
                $compSymbol = $this->componentRegistry->getComponent($parentComponent);
                if ($compSymbol && !empty($compSymbol->slots)) {
                    foreach ($compSymbol->slots as $sName => $slotSymbol) {
                        if ($sName === 'slot') {
                            continue;
                        }
                        if ($slotPrefix !== '' && !str_starts_with(strtolower($sName), strtolower($slotPrefix))) {
                            continue;
                        }
                        $seenSlots[$sName] = true;
                        $completions[] = [
                            'label' => $sName,
                            'kind' => 14, // Keyword
                            'detail' => "Slot for <x-{$parentComponent}>",
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => $slotSymbol->description,
                            ],
                            'insertText' => $sName,
                        ];
                    }
                }
            }

            $commonSlots = ['header', 'footer', 'title', 'actions', 'trigger', 'content', 'icon', 'heading'];
            foreach ($commonSlots as $s) {
                if (isset($seenSlots[$s])) {
                    continue;
                }
                if ($slotPrefix !== '' && !str_starts_with($s, strtolower($slotPrefix))) {
                    continue;
                }
                $completions[] = [
                    'label' => $s,
                    'kind' => 14, // Keyword
                    'detail' => 'Component slot',
                    'insertText' => $s,
                ];
            }
            return $completions;
        }

        // 3. Component tag completion
        $prefix = $this->completionPrefix($document, $position);
        if ($prefix === null) {
            return [];
        }

        $allComponents = [];
        foreach ($this->componentRegistry->all() as $name => $sym) {
            $allComponents[$sym->name] = true;
        }
        $components = $this->project->index->bladeComponents()['components'] ?? [];
        if (is_array($components)) {
            foreach (array_keys($components) as $k) {
                if (is_string($k) && $k !== '') {
                    $allComponents[$k] = true;
                }
            }
        }

        $prefixLower = strtolower($prefix);
        $prefixLength = Utf16Position::length($prefix);

        return collect(array_keys($allComponents))
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->map(fn (string $key): string => $this->completionLabel($key))
            ->unique()
            ->filter(fn (string $label): bool => str_starts_with(strtolower($label), $prefixLower))
            ->map(fn (string $label): array => [
                'label'      => $label,
                'kind'       => 7, // Class / Component
                'filterText' => $label,
                'textEdit'   => [
                    'range'   => [
                        'start' => [
                            'line'      => $position['line'],
                            'character' => $position['character'] - $prefixLength,
                        ],
                        'end' => [
                            'line'      => $position['line'],
                            'character' => $position['character'],
                        ],
                    ],
                    'newText' => $label,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Find Blade component tag matches.
     *
     * @return array<int, array{name: string, range: array<string, array<string, int>>}>
     */
    protected function matches(Document $document): array
    {
        $matches = [];
        $prefixes = $this->project->index->bladeComponents()['prefixes'] ?? [];
        $prefixPattern = collect($prefixes)->filter()->map(fn (string $prefix): string => preg_quote($prefix, '/'))->implode('|');
        $patterns = ['/<\/?x-([^\s>]+)/'];

        if ($prefixPattern !== '') {
            $patterns[] = '/<\/?((' . $prefixPattern . ')\:[^\s>]+)/';
        }

        foreach (explode("\n", $document->content) as $lineNumber => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $line, $lineMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
                    continue;
                }

                foreach ($lineMatches as $match) {
                    $matches[] = [
                        'name'  => $match[1][0],
                        'range' => [
                            'start' => ['line' => $lineNumber, 'character' => $match[0][1] + 1],
                            'end'   => ['line' => $lineNumber, 'character' => $match[0][1] + strlen($match[0][0])],
                        ],
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * Get a component by name.
     *
     * @return array<string, mixed>|null
     */
    protected function component(string $name): ?array
    {
        $components = $this->project->index->bladeComponents()['components'] ?? [];

        return is_array($components[$name] ?? null) ? $components[$name] : null;
    }

    /**
     * Get the component prefix being completed.
     *
     * @param  array<string, mixed>  $position
     */
    protected function completionPrefix(Document $document, array $position): ?string
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';
        $linePrefix = Utf16Position::substr($line, 0, $character);

        if (!preg_match('/(?<!\/)<([a-zA-Z0-9_.:-]+)$/', $linePrefix, $matches)) {
            return null;
        }

        $token = $matches[1];

        $componentPrefixes = $this->componentPrefixes();
        $isPrefixMatch = $componentPrefixes->contains(function (string $prefix) use ($token): bool {
            return str_starts_with(strtolower($token), strtolower($prefix))
                || str_starts_with(strtolower($prefix), strtolower($token));
        });

        if (!$isPrefixMatch) {
            return null;
        }

        return $token;
    }

    /**
     * Get Blade component completion prefixes.
     *
     * @return Collection<int, string>
     */
    protected function componentPrefixes(): Collection
    {
        $prefixes = $this->project->index->bladeComponents()['prefixes'] ?? [];

        return collect(['x', 'x-'])
            ->merge($prefixes)
            ->filter(fn (mixed $prefix): bool => is_string($prefix) && $prefix !== '')
            ->sortByDesc(fn (string $prefix): int => strlen($prefix))
            ->values();
    }

    /**
     * Get the completion label for the component key.
     */
    protected function completionLabel(string $key): string
    {
        if (str_starts_with($key, 'x-')) {
            return $key;
        }

        if (str_contains($key, '::') || !str_contains($key, ':')) {
            return "x-{$key}";
        }

        return $key;
    }

    /**
     * Get the preferred component path.
     *
     * @param  array<string, mixed>|null  $component
     */
    protected function path(?array $component): ?string
    {
        if ($component === null || !is_iterable($component['paths'] ?? null)) {
            return null;
        }

        foreach ($component['paths'] as $path) {
            if (is_string($path) && str_ends_with($path, '.blade.php')) {
                return $path;
            }
        }

        return is_string($component['paths'][0] ?? null) ? $component['paths'][0] : null;
    }

    /**
     * Create a markdown hover response.
     *
     * @param  array<string, array<string, int>>  $range
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    protected function markdownHover(array $range, array $lines): array
    {
        return [
            'range'    => $range,
            'contents' => [
                'kind'  => 'markdown',
                'value' => implode("\n\n", array_values(array_filter($lines))),
            ],
        ];
    }

    /**
     * Get a markdown link for a workspace path.
     */
    protected function markdownPath(string $path): string
    {
        return "[{$path}]({$this->project->target($path)})";
    }

    /**
     * Find parent component tag above current line.
     *
     * @param array<int, string> $lines
     */
    protected function findParentComponentTag(array $lines, int $currentLine): ?string
    {
        for ($i = $currentLine - 1; $i >= 0; $i--) {
            $l = $lines[$i] ?? '';
            if (preg_match('/<x-([a-zA-Z0-9_.:-]+)(?:\s+[^>]*?)?(?<!\/)>/', $l, $m)) {
                $tagName = $m[1];
                if (!str_starts_with($tagName, 'slot')) {
                    return $tagName;
                }
            }
        }

        return null;
    }
}
