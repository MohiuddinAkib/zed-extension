<?php

declare(strict_types=1);

namespace App\Lsp\Features\DataPaths;

use App\Lsp\Analysis\DataPathResolver;
use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\Utf16Position;

class DataPathCompletionProvider implements CompletionProvider
{
    public function __construct(
        protected ?Project $project = null,
        protected ?DataPathResolver $resolver = null,
    ) {
        $this->resolver ??= new DataPathResolver($this->project);
    }

    /**
     * Provide completions for data-path arguments.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return [];
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';
        $textBefore = Utf16Position::substr($line, 0, $character);
        $textAfter = Utf16Position::substr($line, $character);

        $context = $this->parseDataPathContext($textBefore);
        if ($context === null) {
            return [];
        }

        return $this->resolveAndComplete(
            $context['targetExpr'],
            $context['pathBeforeCursor'],
            $textAfter,
            $lineNumber,
            $character,
            $document,
            $position,
        );
    }

    /**
     * Parse the active data-path call context from the text before cursor.
     */
    public function parseDataPathContext(string $textBefore): ?array
    {
        $globalHelpers = ['data_get', 'data_set', 'data_fill', 'data_has', 'data_forget'];
        $arrMethods = ['get', 'set', 'add', 'has', 'hasAny', 'forget', 'pull', 'only', 'except'];

        // Pattern 1: Function or static call expecting (target, 'path'...)
        if (preg_match('/,\s*(["\'])([^"\']*)$/s', $textBefore, $m, PREG_OFFSET_CAPTURE)) {
            $quoteChar = $m[1][0];
            $pathBeforeCursor = $m[2][0];
            $commaOffset = $m[0][1];
            $beforeComma = rtrim(substr($textBefore, 0, $commaOffset));

            $callInfo = $this->findEnclosingCall($beforeComma);
            if ($callInfo !== null) {
                $callName = $callInfo['callName'];
                $cleanCall = ltrim($callName, '\\');
                $isGlobal = in_array(strtolower($cleanCall), $globalHelpers, true);
                $isArr = preg_match('/^(?:Illuminate\\\\Support\\\\)?Arr::(' . implode('|', $arrMethods) . ')$/i', $cleanCall);

                if ($isGlobal || $isArr) {
                    return [
                        'type'             => $isGlobal ? 'global' : 'arr',
                        'callName'         => $cleanCall,
                        'targetExpr'       => trim($callInfo['targetExpr']),
                        'quote'            => $quoteChar,
                        'pathBeforeCursor' => $pathBeforeCursor,
                    ];
                }
            }
        }

        // Pattern 2: Fluent method call: fluent($data)->get('... or $fluentVar->string('...
        if (preg_match('/\(\s*(["\'])([^"\']*)$/s', $textBefore, $m, PREG_OFFSET_CAPTURE)) {
            $quoteChar = $m[1][0];
            $pathBeforeCursor = $m[2][0];
            $parenOffset = $m[0][1];
            $beforeParen = rtrim(substr($textBefore, 0, $parenOffset));

            if (preg_match('/(?:fluent\s*\((.+)\)|(\$[a-zA-Z0-9_]+))->(get|has|set|value|scope|only|except|string|integer|boolean|array|collection|date|enum|object)$/s', $beforeParen, $fm)) {
                $targetExpr = !empty($fm[1]) ? $fm[1] : $fm[2];
                $methodName = $fm[3];

                return [
                    'type'             => 'fluent',
                    'callName'         => $methodName,
                    'targetExpr'       => trim($targetExpr),
                    'quote'            => $quoteChar,
                    'pathBeforeCursor' => $pathBeforeCursor,
                ];
            }
        }

        return null;
    }

    protected function findEnclosingCall(string $code): ?array
    {
        $len = strlen($code);
        $depth = 0;
        $quote = null;

        for ($i = $len - 1; $i >= 0; $i--) {
            $c = $code[$i];

            if ($quote !== null) {
                if ($c === $quote && ($i === 0 || $code[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;
                continue;
            }

            if ($c === ')' || $c === ']' || $c === '}') {
                $depth++;
            } elseif ($c === '(' || $c === '[' || $c === '{') {
                if ($depth > 0) {
                    $depth--;
                } elseif ($c === '(') {
                    $targetExpr = substr($code, $i + 1);
                    $prefix = rtrim(substr($code, 0, $i));
                    if (preg_match('/(?:\\\\?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)*(?:::|\->|\?->)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$/', $prefix, $nameMatch)) {
                        return [
                            'callName'   => $nameMatch[0],
                            'targetExpr' => $targetExpr,
                        ];
                    }
                    return null;
                } else {
                    return null;
                }
            } elseif ($c === ',' && $depth === 0) {
                return null;
            }
        }

        return null;
    }

    protected function findUnclosedQuote(string $text): ?array
    {
        $len = strlen($text);
        $quote = null;
        $quotePos = -1;

        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];

            if ($quote !== null) {
                if ($c === $quote && ($i === 0 || $text[$i - 1] !== '\\')) {
                    $quote = null;
                    $quotePos = -1;
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $quotePos = $i;
            }
        }

        if ($quote !== null) {
            return [
                'quote' => $quote,
                'pos'   => $quotePos,
            ];
        }

        return null;
    }

    /**
     * Resolve target TypeRef and generate data-path completions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveAndComplete(
        string $targetExpr,
        string $pathBeforeCursor,
        string $textAfter,
        int $lineNumber,
        int $character,
        Document $document,
        array $position,
    ): array {
        $targetType = $this->resolver->inferExpressionType($targetExpr, $document, $position);
        if ($targetType === null || (string) $targetType === 'mixed') {
            return [];
        }

        $stringStartChar = $character - Utf16Position::length($pathBeforeCursor);

        return $this->resolver->getCompletionsForPath(
            $targetType,
            $pathBeforeCursor,
            $textAfter,
            $lineNumber,
            $stringStartChar,
        );
    }
}
