<?php

declare(strict_types=1);

namespace App\Lsp\Features\Configs;

use App\Lsp\Detection\AutocompleteArgument;
use App\Lsp\Detection\DetectedArgument;
use App\Lsp\Detection\Pattern;
use App\Lsp\Document;
use App\Lsp\Features\Support\DocumentMapper;
use App\Lsp\Project;
use App\Lsp\Support\Utf16Position;
use Illuminate\Support\Collection;

class ConfigDocumentMapper extends DocumentMapper
{
    /**
     * Create a new config document mapper instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Get config detection patterns.
     *
     * @return array<int, Pattern>
     */
    protected function patterns(): array
    {
        return [
            Pattern::method(method: 'config', argument: 0),
            Pattern::attribute(class: Pattern::containerAttribute('Config'), argument: 0),
            Pattern::method(method: ['get', 'prepend', 'push'], class: Pattern::contract('Config\\Repository'), argument: 0),
            Pattern::method(method: ['get', 'getMany', 'string', 'integer', 'boolean', 'float', 'array', 'prepend', 'push'], class: [...Pattern::facade('Config'), 'config'], argument: 0),
        ];
    }

    /**
     * Convert the given argument to document links.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toLinks(DetectedArgument $argument): array
    {
        $config = $this->find($argument);

        if ($config === null || !is_string($config['file'] ?? null)) {
            return [];
        }

        return [
            $this->project->link(
                $argument->range(),
                $config['file'],
                is_numeric($config['line'] ?? null) ? (int) $config['line'] : null,
            ),
        ];
    }

    /**
     * Convert the given argument to hover.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    protected function toHover(DetectedArgument $argument, array $position): ?array
    {
        $config = $this->find($argument);

        if ($config === null) {
            return null;
        }

        $lines = [];
        $value = $config['value'] ?? null;

        if ($value !== null) {
            $display = is_scalar($value) ? (string) $value : 'array(...)';

            $lines[] = '`' . ($display === '' ? '[empty string]' : $display) . '`';
        }

        if (is_string($config['file'] ?? null)) {
            $line = is_numeric($config['line'] ?? null) ? (int) $config['line'] : null;
            $target = $this->project->target($config['file'], $line);

            $lines[] = "[{$config['file']}]({$target})";
        }

        if ($lines === []) {
            return null;
        }

        return [
            'range'    => $argument->range(),
            'contents' => [
                'kind'  => 'markdown',
                'value' => implode("\n\n", array_values(array_filter($lines))),
            ],
        ];
    }

    /**
     * Convert the given argument to diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toDiagnostics(DetectedArgument $argument): array
    {
        $value = $argument->stringValue();

        if ($value === null || $this->find($argument) !== null) {
            return [];
        }

        return [[
            'range'    => $argument->range(),
            'severity' => 2,
            'source'   => 'Laravel Extension',
            'code'     => 'config',
            'message'  => "Config [{$value}] not found.",
        ]];
    }

    /**
     * Get completions.
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

        if ($this->isInsideComment($document, $lineNumber, $character)) {
            return [];
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';
        $textBeforeCursor = Utf16Position::substr($line, 0, $character);

        $pattern = '/(?:'
            . '(?<!->)(?<!\?->)(?<!::)(?<![a-zA-Z0-9_\$\x7f-\xff])(?:\\\\)?config'
            . '|'
            . '(?<!->)(?<!\?->)(?<![a-zA-Z0-9_\$\x7f-\xff])(?:\\\\)?(?:Illuminate\\\\Support\\\\Facades\\\\)?Config::(?:get|getMany|string|integer|boolean|float|array|prepend|push)'
            . ')\s*\(\s*(["\'])([^"\',)]*)$/i';

        if (preg_match($pattern, $textBeforeCursor, $matches)) {
            $prefix = $matches[2];
            $prefixLength = Utf16Position::length($prefix);
            $startCharacter = $character - $prefixLength;

            $textAfterCursor = Utf16Position::substr($line, $character);
            preg_match('/^([a-zA-Z0-9_\-\.]*)/', $textAfterCursor, $suffixMatch);
            $suffix = $suffixMatch[1] ?? '';
            $suffixLength = Utf16Position::length($suffix);
            $endCharacter = $character + $suffixLength;

            $range = [
                'start' => [
                    'line'      => $lineNumber,
                    'character' => $startCharacter,
                ],
                'end' => [
                    'line'      => $lineNumber,
                    'character' => $endCharacter,
                ],
            ];

            $prefixLower = strtolower($prefix);

            return $this->configs()
                ->filter(fn (mixed $config): bool => is_array($config) && is_string($config['name'] ?? null) && $config['name'] !== '')
                ->filter(fn (array $config): bool => $prefixLower === '' || str_starts_with(strtolower($config['name']), $prefixLower))
                ->map(function (array $config) use ($range): array {
                    $name = $config['name'];
                    $item = [
                        'label'      => $name,
                        'kind'       => 12,
                        'filterText' => $name,
                        'textEdit'   => [
                            'range'   => $range,
                            'newText' => $name,
                        ],
                    ];

                    $value = $config['value'] ?? null;
                    if ($value !== null) {
                        if (is_bool($value)) {
                            $item['detail'] = $value ? 'true' : 'false';
                        } elseif (is_scalar($value)) {
                            $item['detail'] = (string) $value;
                        } else {
                            $item['detail'] = 'array(...)';
                        }
                    }

                    return $item;
                })
                ->values()
                ->all();
        }

        return parent::completions($document, $position);
    }

    /**
     * Check if cursor is inside a Blade, block, or line comment.
     */
    protected function isInsideComment(Document $document, int $lineNumber, int $character): bool
    {
        $lines = explode("\n", $document->content);
        $textBefore = '';
        for ($i = 0; $i < $lineNumber; $i++) {
            $textBefore .= ($lines[$i] ?? '') . "\n";
        }
        $currentLine = $lines[$lineNumber] ?? '';
        $textBeforeCursor = Utf16Position::substr($currentLine, 0, $character);
        $textBefore .= $textBeforeCursor;

        // 1. Blade comment check: {{-- ... --}}
        $lastBladeOpen = strrpos($textBefore, '{{--');
        if ($lastBladeOpen !== false) {
            $lastBladeClose = strrpos($textBefore, '--}}');
            if ($lastBladeClose === false || $lastBladeClose < $lastBladeOpen) {
                return true;
            }
        }

        // 2. Multi-line comment check: /* ... */
        $lastBlockOpen = strrpos($textBefore, '/*');
        if ($lastBlockOpen !== false) {
            $lastBlockClose = strrpos($textBefore, '*/');
            if ($lastBlockClose === false || $lastBlockClose < $lastBlockOpen) {
                return true;
            }
        }

        // 3. Single-line comment on current line: // or #
        return $this->isLineCommentedBeforeCursor($textBeforeCursor);
    }

    /**
     * Check if single-line comment begins on line before cursor.
     */
    protected function isLineCommentedBeforeCursor(string $textBeforeCursor): bool
    {
        $len = strlen($textBeforeCursor);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $textBeforeCursor[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                continue;
            }

            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                continue;
            }

            if (!$inSingleQuote && !$inDoubleQuote) {
                if ($char === '/' && $i + 1 < $len && $textBeforeCursor[$i + 1] === '/') {
                    if ($i === 0 || $textBeforeCursor[$i - 1] !== ':') {
                        return true;
                    }
                }
                if ($char === '#' && ($i === 0 || preg_match('/\s/', $textBeforeCursor[$i - 1]))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convert the given argument to completion items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toCompletions(AutocompleteArgument $argument): array
    {
        if (($argument->item()['methodName'] ?? null) === 'getMany' && !$argument->isArray()) {
            return [];
        }

        return $this->configs()
            ->filter(fn (array $config): bool => is_string($config['name'] ?? null) && $config['name'] !== '')
            ->map(function (array $config) use ($argument): array {
                $name = $config['name'];
                $item = [
                    'label'      => $name,
                    'kind'       => 12,
                    'filterText' => $name,
                    'textEdit'   => [
                        'range'   => $argument->replacementRange(),
                        'newText' => $name,
                    ],
                ];

                $value = $config['value'] ?? null;

                if ($value !== null) {
                    if (is_bool($value)) {
                        $item['detail'] = $value ? 'true' : 'false';
                    } elseif (is_scalar($value)) {
                        $item['detail'] = (string) $value;
                    } else {
                        $item['detail'] = 'array(...)';
                    }
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * Determine if the config value should be shown as completion detail.
     */
    protected function hasCompletionDetail(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false && $value !== 0;
    }

    /**
     * Find the config for the given argument.
     *
     * @return array<string, mixed>|null
     */
    protected function find(DetectedArgument $argument): ?array
    {
        $value = $argument->stringValue();

        if ($value === null) {
            return null;
        }

        $config = $this->configs()->firstWhere('name', $value);

        return is_array($config) ? $config : null;
    }

    /**
     * Get the available config entries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function configs(): Collection
    {
        return $this->project->index->configs()['configs'];
    }
}
