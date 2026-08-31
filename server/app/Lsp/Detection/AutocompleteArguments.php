<?php

declare(strict_types=1);

namespace App\Lsp\Detection;

use App\Lsp\Document;
use Illuminate\Support\Collection;

class AutocompleteArguments
{
    /**
     * The autocomplete patterns to match.
     *
     * @var array<int, Pattern>
     */
    protected array $patterns = [];

    /**
     * Create a new autocomplete argument selector.
     *
     * @param  array<string, mixed>  $position
     */
    protected function __construct(
        protected Document $document,
        protected array $position,
    ) {}

    /**
     * Create a selector for the given document and position.
     *
     * @param  array<string, mixed>  $position
     */
    public static function in(Document $document, array $position): self
    {
        return new self($document, $position);
    }

    /**
     * Select autocomplete arguments matching the given patterns.
     *
     * @param  array<int, Pattern>  $patterns
     */
    public function matching(array $patterns): self
    {
        $this->patterns = $patterns;

        return $this;
    }

    /**
     * Get matched autocomplete arguments.
     *
     * @return Collection<int, AutocompleteArgument>
     */
    public function values(): Collection
    {
        if ($this->patterns === []) {
            return collect();
        }

        $item = $this->document->autocomplete($this->position);
        $argumentIndex = $this->argumentIndex($item);

        if ($item === [] || $argumentIndex === null) {
            return collect();
        }

        return collect($this->patterns)
            ->filter(fn (Pattern $pattern): bool => $pattern->matches($item))
            ->filter(fn (Pattern $pattern): bool => in_array($argumentIndex, $pattern->arguments(), true))
            ->map(fn (Pattern $pattern): AutocompleteArgument => new AutocompleteArgument(
                $item,
                $pattern,
                $argumentIndex,
                $this->document,
                $this->position,
            ))
            ->values();
    }

    /**
     * Get the autocompleting argument index.
     *
     * @param  array<string, mixed>  $item
     */
    protected function argumentIndex(array $item): ?int
    {
        $index = $item['arguments']['autocompletingIndex'] ?? null;

        return is_int($index) ? $index : null;
    }
}
