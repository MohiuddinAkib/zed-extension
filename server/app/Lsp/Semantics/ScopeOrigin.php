<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class ScopeOrigin
{
    public function __construct(
        public string $name,
        public ?string $source = null,
        public ?int $line = null,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{name: string, source?: string, line?: int, detail?: string}
     */
    public function toArray(): array
    {
        $origin = ['name' => $this->name];

        if ($this->source !== null) {
            $origin['source'] = $this->source;
        }

        if ($this->line !== null) {
            $origin['line'] = $this->line;
        }

        if ($this->detail !== null && $this->detail !== '') {
            $origin['detail'] = $this->detail;
        }

        return $origin;
    }
}
