<?php

declare(strict_types=1);

namespace App\Lsp\Detection;

use App\Lsp\Document;

class AutocompleteArgument
{
    /**
     * Create a new autocomplete argument instance.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $position
     */
    public function __construct(
        protected array $item,
        protected Pattern $pattern,
        protected int $argumentIndex,
        protected Document $document,
        protected array $position,
    ) {}

    /**
     * Get the autocomplete parser item.
     *
     * @return array<string, mixed>
     */
    public function item(): array
    {
        return $this->item;
    }

    /**
     * Get the matched pattern.
     */
    public function pattern(): Pattern
    {
        return $this->pattern;
    }

    /**
     * Get the matched argument index.
     */
    public function argumentIndex(): int
    {
        return $this->argumentIndex;
    }

    /**
     * Get the autocomplete position.
     *
     * @return array<string, mixed>
     */
    public function position(): array
    {
        return $this->position;
    }

    /**
     * Get a string value from the given argument index.
     */
    public function stringValueAt(int $index): ?string
    {
        $argument = $this->item['arguments']['children'][$index]['children'][0] ?? null;

        if (!is_array($argument) || ($argument['type'] ?? null) !== 'string') {
            return null;
        }

        $value = $argument['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Determine if the current autocomplete argument is an array.
     */
    public function isArray(): bool
    {
        $argument = $this->item['arguments']['children'][$this->argumentIndex]['children'][0] ?? null;

        return is_array($argument) && ($argument['type'] ?? null) === 'array';
    }

    /**
     * Determine if the current autocomplete argument is completing an array key.
     */
    public function isArrayKeyCompletion(): bool
    {
        $argument = $this->currentArgument();

        return is_array($argument)
            && ($argument['type'] ?? null) === 'array'
            && ($argument['autocompletingKey'] ?? false) === true;
    }

    /**
     * Get string keys from the current autocomplete array argument.
     *
     * @return array<int, string>
     */
    public function arrayKeys(): array
    {
        $argument = $this->currentArgument();

        if (!is_array($argument) || ($argument['type'] ?? null) !== 'array') {
            return [];
        }

        return collect($argument['children'] ?? [])
            ->map(fn (mixed $child): mixed => is_array($child) ? ($child['key']['value'] ?? null) : null)
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values()
            ->all();
    }

    /**
     * Determine if the current autocomplete argument has the given name.
     */
    public function isArgumentNamed(string $name): bool
    {
        $argument = $this->item['arguments']['children'][$this->argumentIndex - 1] ?? null;

        return is_array($argument) && ($argument['name'] ?? null) === $name;
    }

    /**
     * Get the character immediately before the autocomplete position.
     */
    public function precedingCharacter(): ?string
    {
        $line = (int) $this->position['line'];
        $character = (int) $this->position['character'];

        if ($character < 1) {
            return null;
        }

        return substr($this->lineBefore($line, $character), -1) ?: null;
    }

    /**
     * Get the range that should be replaced by the completion.
     *
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    public function replacementRange(): array
    {
        $line = (int) $this->position['line'];
        $character = (int) $this->position['character'];
        $text = $this->lineBefore($line, $character);
        $start = $character - strlen($this->fragment($text));

        return [
            'start' => [
                'line'      => $line,
                'character' => max(0, $start),
            ],
            'end' => [
                'line'      => $line,
                'character' => $character,
            ],
        ];
    }

    /**
     * Get the current line text before the given position.
     */
    protected function lineBefore(int $line, int $character): string
    {
        $lines = explode("\n", $this->document->content);
        $text = $lines[$line] ?? '';

        return substr($text, 0, $character);
    }

    /**
     * Get the current autocomplete argument node.
     *
     * @return array<string, mixed>|null
     */
    protected function currentArgument(): ?array
    {
        $argument = $this->item['arguments']['children'][$this->argumentIndex]['children'][0] ?? null;

        return is_array($argument) ? $argument : null;
    }

    /**
     * Get the current word fragment.
     */
    protected function fragment(string $text): string
    {
        preg_match('/[\\w\\d\\-_\\.\\:\\\\\/@]+$/', $text, $matches);

        return $matches[0] ?? '';
    }
}
