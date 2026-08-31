<?php

declare(strict_types=1);

namespace App\Lsp\CodeActions;

use Illuminate\Support\Collection;

class CodeActionContext
{
    /**
     * Create a new code action context instance.
     *
     * @param  array<string, mixed>  $range
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly array $range,
        public readonly array $context,
    ) {}

    /**
     * Determine if the request accepts the given action kind.
     */
    public function accepts(string $kind): bool
    {
        $only = $this->context['only'] ?? null;

        if (!is_array($only)) {
            return true;
        }

        return in_array($kind, $only, true);
    }

    /**
     * Get diagnostics matching the given diagnostic code.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function diagnostics(string $code): Collection
    {
        return collect($this->context['diagnostics'] ?? [])
            ->filter(fn (mixed $diagnostic): bool => is_array($diagnostic))
            ->filter(fn (array $diagnostic): bool => ($diagnostic['code'] ?? null) === $code)
            ->values();
    }
}
