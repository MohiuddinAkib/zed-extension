<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class TypeRef
{
    /**
     * @param  list<TypeRef>  $children
     * @param  array<string, TypeRef>  $shape
     */
    public function __construct(
        public string $kind,
        public string $displayName,
        public bool $nullable = false,
        public array $children = [],
        public array $shape = [],
    ) {}

    public static function mixed(): self
    {
        return new self('mixed', 'mixed');
    }

    public static function fromString(?string $type): self
    {
        $type = trim((string) $type);
        if ($type === '') {
            return self::mixed();
        }

        $nullable = str_starts_with($type, '?');
        $normalized = $nullable ? substr($type, 1) : $type;

        if ($normalized === 'mixed') {
            return new self('mixed', $type, $nullable);
        }

        $unionParts = self::splitTopLevel($normalized, '|');
        if (count($unionParts) > 1) {
            $nullable = $nullable || in_array('null', array_map('strtolower', $unionParts), true);

            return new self(
                'union',
                $type,
                $nullable,
                array_map(fn (string $part): self => self::fromString($part), $unionParts)
            );
        }

        $intersectionParts = self::splitTopLevel($normalized, '&');
        if (count($intersectionParts) > 1) {
            return new self(
                'intersection',
                $type,
                $nullable,
                array_map(fn (string $part): self => self::fromString($part), $intersectionParts)
            );
        }

        if (in_array(strtolower($normalized), ['view-string', 'view_string'], true)) {
            return new self('semantic', $type, $nullable);
        }

        if (preg_match('/^class-string(?:<(.+)>)?$/i', $normalized, $matches)) {
            return new self('semantic', $type, $nullable, isset($matches[1]) ? self::splitGenericArgs($matches[1]) : []);
        }

        if (preg_match('/^enum-string(?:<(.+)>)?$/i', $normalized, $matches)) {
            return new self('enum-string', $type, $nullable, isset($matches[1]) ? self::splitGenericArgs($matches[1]) : []);
        }

        if (str_starts_with($normalized, 'array{') || str_starts_with($normalized, 'object{')) {
            $isObject = str_starts_with($normalized, 'object{');
            $kind = $isObject ? 'object-shape' : 'array-shape';
            $openPos = strpos($normalized, '{');
            $inner = $openPos !== false ? substr($normalized, $openPos + 1, -1) : '';
            $shapeItems = self::splitTopLevel($inner, ',');
            $shape = [];
            foreach ($shapeItems as $item) {
                if (preg_match('/^([\'"]?)([a-zA-Z0-9_\-\.]+)\1(\??)\s*:\s*(.+)$/', trim($item), $m)) {
                    $propName = $m[2];
                    $isOptional = $m[3] === '?';
                    $propTypeStr = trim($m[4]);
                    $propTypeRef = self::fromString($propTypeStr);
                    if ($isOptional) {
                        $propTypeRef->nullable = true;
                    }
                    $shape[$propName] = $propTypeRef;
                }
            }
            return new self($kind, $type, $nullable, [], $shape);
        }

        if (preg_match('/^([^<]+)<(.+)>$/', $normalized, $matches)) {
            return new self('generic', $type, $nullable, self::splitGenericArgs($matches[2]));
        }

        if ((str_starts_with($normalized, "'") && str_ends_with($normalized, "'"))
            || (str_starts_with($normalized, '"') && str_ends_with($normalized, '"'))
            || preg_match('/^-?[0-9]+$/', $normalized)
            || in_array(strtolower($normalized), ['true', 'false'], true)) {
            return new self('literal', $type, $nullable);
        }

        if (in_array(strtolower($normalized), ['int', 'float', 'string', 'bool', 'boolean', 'array', 'object', 'callable', 'iterable', 'void', 'never', 'null'], true)) {
            return new self('scalar', $type, $nullable);
        }

        if (enum_exists($normalized)) {
            return new self('enum', $type, $nullable);
        }

        return new self('named', $type, $nullable);
    }

    /**
     * @param  list<TypeRef>  $types
     */
    public static function union(array $types): self
    {
        $unique = [];

        foreach ($types as $type) {
            if ((string) $type === 'mixed' && count($types) > 1) {
                continue;
            }

            $unique[(string) $type] = $type;
        }

        if (count($unique) === 0) {
            return self::mixed();
        }

        if (count($unique) === 1) {
            return array_values($unique)[0];
        }

        return new self('union', implode('|', array_keys($unique)), in_array('null', array_keys($unique), true), array_values($unique));
    }

    /**
     * @return list<TypeRef>
     */
    protected static function splitGenericArgs(string $args): array
    {
        return array_map(fn (string $part): self => self::fromString($part), self::splitTopLevel($args, ','));
    }

    /**
     * @return list<string>
     */
    protected static function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '<' || $char === '{' || $char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === '>' || $char === '}' || $char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /**
     * @return array{kind: string, displayName: string, nullable: bool, children: array<int, mixed>, shape: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'displayName' => $this->displayName,
            'nullable' => $this->nullable,
            'children' => array_map(fn (self $child): array => $child->toArray(), $this->children),
            'shape' => array_map(fn (self $child): array => $child->toArray(), $this->shape),
        ];
    }

    public function isShape(): bool
    {
        return $this->kind === 'array-shape' || $this->kind === 'object-shape';
    }

    public function isUnion(): bool
    {
        return $this->kind === 'union';
    }

    public function isGeneric(): bool
    {
        return $this->kind === 'generic';
    }

    public function isEnum(): bool
    {
        return $this->kind === 'enum' || $this->kind === 'enum-string';
    }

    public function isLiteral(): bool
    {
        return $this->kind === 'literal';
    }

    /**
     * @return array<string, TypeRef>
     */
    public function shapeMembers(): array
    {
        return $this->shape;
    }

    public function getShapeMember(string $name): ?self
    {
        return $this->shape[$name] ?? null;
    }

    public function __toString(): string
    {
        return $this->displayName;
    }
}
