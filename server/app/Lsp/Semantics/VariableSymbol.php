<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class VariableSymbol
{
    /**
     * @param  list<ScopeOrigin>  $origins
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public TypeRef $type,
        public ScopeOrigin $origin,
        public string $detail = '',
        public bool $optional = false,
        public Confidence $confidence = Confidence::Medium,
        public ?SourceRange $range = null,
        public mixed $defaultValue = null,
        public array $origins = [],
        public array $metadata = [],
    ) {
        if ($this->origins === []) {
            $this->origins = [$this->origin];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromLegacy(array $data, ?string $fallbackName = null): self
    {
        $name = (string) ($data['name'] ?? $fallbackName ?? '');
        $originName = (string) ($data['origin'] ?? 'Inferred');
        $source = isset($data['source']) && is_string($data['source']) ? $data['source'] : null;
        $line = isset($data['line']) && is_numeric($data['line']) ? (int) $data['line'] : null;
        $detail = (string) ($data['detail'] ?? '');

        return new self(
            name: $name,
            type: TypeRef::fromString((string) ($data['type'] ?? 'mixed')),
            origin: new ScopeOrigin($originName, $source, $line, $detail !== '' ? $detail : null),
            detail: $detail,
            optional: (bool) ($data['optional'] ?? false),
            confidence: Confidence::tryFrom((string) ($data['confidence'] ?? Confidence::Medium->value)) ?? Confidence::Medium,
            range: $line !== null ? SourceRange::line($line) : null,
            defaultValue: $data['default'] ?? null,
            metadata: array_diff_key($data, array_flip(['name', 'type', 'origin', 'source', 'line', 'detail', 'optional', 'confidence', 'default'])),
        );
    }

    public function mergeWith(self $other): self
    {
        if ($other->origin->name === 'PHPDoc' || $other->origin->name === '@php') {
            $this->type = $other->type;
            $this->origin = $other->origin;
            if ($other->detail !== '') {
                $this->detail = $other->detail;
            }
        } elseif ($this->origin->name === 'PHPDoc' || $this->origin->name === '@php') {
            // Keep current explicit template declaration
        } else {
            $this->type = TypeRef::union([$this->type, $other->type]);
        }

        $this->optional = $this->optional || $other->optional || ((string) $this->type !== (string) $other->type);
        $this->confidence = Confidence::lowest($this->confidence, $other->confidence);
        $this->origins = $this->mergeOrigins($this->origins, $other->origins);
        $this->metadata = array_replace_recursive($this->metadata, $other->metadata);

        if ($this->detail === '' && $other->detail !== '') {
            $this->detail = $other->detail;
        }

        if ($this->defaultValue === null && $other->defaultValue !== null) {
            $this->defaultValue = $other->defaultValue;
        }

        return $this;
    }

    /**
     * @param  list<ScopeOrigin>  $left
     * @param  list<ScopeOrigin>  $right
     * @return list<ScopeOrigin>
     */
    protected function mergeOrigins(array $left, array $right): array
    {
        $merged = [];

        foreach ([...$left, ...$right] as $origin) {
            $key = implode('|', [
                $origin->name,
                $origin->source ?? '',
                (string) ($origin->line ?? ''),
            ]);
            $merged[$key] = $origin;
        }

        return array_values($merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        $data = [
            'name' => $this->name,
            'type' => (string) $this->type,
            'origin' => $this->origin->name,
            'optional' => $this->optional,
            'confidence' => $this->confidence->value,
        ];

        if ($this->detail !== '') {
            $data['detail'] = $this->detail;
        }

        if ($this->origin->source !== null) {
            $data['source'] = $this->origin->source;
        }

        if ($this->origin->line !== null) {
            $data['line'] = $this->origin->line;
        }

        if ($this->defaultValue !== null) {
            $data['default'] = $this->defaultValue;
        }

        if ($this->origins !== []) {
            $data['origins'] = array_map(fn (ScopeOrigin $origin): array => $origin->toArray(), $this->origins);
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        if ($this->range !== null) {
            $data['range'] = $this->range->toArray();
        }

        return $data;
    }
}
