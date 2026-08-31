<?php

declare(strict_types=1);

namespace App\Lsp\Detection;

use App\Lsp\Document;
use Illuminate\Support\Collection;

class DetectedArguments
{
    /**
     * The detected patterns to match.
     *
     * @var array<int, Pattern>
     */
    protected array $patterns = [];

    /**
     * Create a new detected argument selector.
     */
    protected function __construct(
        protected Document $document,
    ) {}

    /**
     * Create a selector for the given document.
     */
    public static function in(Document $document): self
    {
        return new self($document);
    }

    /**
     * Select arguments matching the given patterns.
     *
     * @param  array<int, Pattern>  $patterns
     */
    public function matching(array $patterns): self
    {
        $this->patterns = $patterns;

        return $this;
    }

    /**
     * Get matched string arguments.
     *
     * @return Collection<int, DetectedArgument>
     */
    public function strings(): Collection
    {
        return $this->parameters(['string']);
    }

    /**
     * Get matched string and array arguments.
     *
     * @return Collection<int, DetectedArgument>
     */
    public function stringsAndArrays(): Collection
    {
        return $this->parameters(['string', 'array']);
    }

    /**
     * Get matched arguments by parameter type.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, DetectedArgument>
     */
    protected function parameters(array $types): Collection
    {
        if ($this->patterns === []) {
            return collect();
        }

        return $this->document->detect()
            ->flatMap(fn (array $item): array => $this->argumentsFromItem($item, $types))
            ->values();
    }

    /**
     * Get matched arguments from a detected parser item.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $types
     * @return array<int, DetectedArgument>
     */
    protected function argumentsFromItem(array $item, array $types): array
    {
        $arguments = [];

        foreach ($this->patterns as $pattern) {
            if (!$pattern->matches($item)) {
                continue;
            }

            foreach ($pattern->arguments() as $argumentIndex) {
                array_push($arguments, ...$this->parametersFromArgument($item, $argumentIndex, $types));
            }
        }

        return $arguments;
    }

    /**
     * Get matched parameters from a detected parser item argument.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $types
     * @return array<int, DetectedArgument>
     */
    protected function parametersFromArgument(array $item, int $argumentIndex, array $types): array
    {
        $arguments = $item['arguments']['children'];

        if (!isset($arguments[$argumentIndex])) {
            return [];
        }

        $params = $arguments[$argumentIndex]['children'];

        $detected = [];

        foreach ($params as $param) {
            if (!in_array($param['type'], $types, true)) {
                continue;
            }

            $detected[] = new DetectedArgument($item, $argumentIndex, $param);
        }

        return $detected;
    }
}
