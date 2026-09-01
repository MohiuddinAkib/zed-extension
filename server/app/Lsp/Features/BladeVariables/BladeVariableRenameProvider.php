<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Document;
use App\Lsp\Project;

class BladeVariableRenameProvider
{
    protected BladePhpAstAnalyzer $astAnalyzer;
    protected BladeScopeResolver $scopeResolver;

    public function __construct(
        protected Project $project,
    ) {
        $this->astAnalyzer = new BladePhpAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project);
    }

    public const RESERVED_VARIABLES = [
        'loop',
        'errors',
        '__env',
        'this',
        'app',
    ];

    /**
     * Prepare rename for the given document and position.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function prepareRename(Document $document, array $position): ?array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return null;
        }

        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $expressions = $this->astAnalyzer->extractAllExpressions($document->content);

        $targetExpr = null;
        foreach ($expressions as $expr) {
            if ($expr['kind'] !== 'variable') {
                continue;
            }

            if ((int) $expr['startLine'] !== $lineNumber) {
                continue;
            }

            $startCol = (int) $expr['startCol'];
            $endCol = (int) $expr['endCol'];

            if ($character >= $startCol && $character <= $endCol) {
                $targetExpr = $expr;
                break;
            }
        }

        if ($targetExpr === null) {
            return null;
        }

        $varName = $targetExpr['name'];
        if (in_array($varName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        $varStartCol = (int) $targetExpr['startCol'] + 1;
        $varEndCol = (int) $targetExpr['endCol'];

        return [
            'range' => [
                'start' => ['line' => $lineNumber, 'character' => $varStartCol],
                'end' => ['line' => $lineNumber, 'character' => $varEndCol],
            ],
            'placeholder' => $varName,
        ];
    }

    /**
     * Perform the rename and return workspace edits.
     *
     * @param  array<string, mixed>  $position
     * @param  string  $newName
     * @return array<string, mixed>|null
     */
    public function rename(Document $document, array $position, string $newName): ?array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return null;
        }

        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $line = explode("\n", $document->content)[$lineNumber] ?? '';
        $varInfo = $this->findVariableAtPosition($line, $character);

        if ($varInfo === null) {
            return null;
        }

        $targetVarName = $varInfo['name'];
        $cleanNewName = ltrim($newName, '$');

        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $cleanNewName)) {
            return null;
        }

        // Determine lexical scope boundaries for this variable at cursor position
        $scope = $this->scopeResolver->resolveAtPosition($document, $lineNumber, $character);
        $varSymbol = $scope->variables[$targetVarName] ?? null;

        $minLine = 0;
        $maxLine = PHP_INT_MAX;

        if ($varSymbol && $varSymbol->origin && $varSymbol->origin->name === '@foreach') {
            // Find enclosing loop range
            $ranges = $this->findEnclosingLoopRange($document->content, $lineNumber + 1);
            if ($ranges !== null) {
                $minLine = $ranges['startLine'] - 1;
                $maxLine = $ranges['endLine'] - 1;
            }
        }

        $lines = explode("\n", $document->content);
        $edits = [];
        $seenRanges = [];

        // AST expressions extraction (includes echo, directives, @php blocks, bound attributes)
        $expressions = $this->astAnalyzer->extractAllExpressions($document->content);

        foreach ($expressions as $expr) {
            $eLine = (int) $expr['startLine'];
            if ($eLine < $minLine || $eLine > $maxLine) {
                continue;
            }

            if ($expr['kind'] === 'variable' && $expr['name'] === $targetVarName) {
                $startCol = (int) $expr['startCol'];
                $endCol = (int) $expr['endCol'];
                $key = "{$eLine}:{$startCol}";

                if (!isset($seenRanges[$key])) {
                    $seenRanges[$key] = true;
                    $edits[] = [
                        'range' => [
                            'start' => ['line' => $eLine, 'character' => $startCol],
                            'end' => ['line' => $eLine, 'character' => $endCol],
                        ],
                        'newText' => $cleanNewName,
                    ];
                }
            }
        }

        if (empty($edits)) {
            return null;
        }

        return [
            'changes' => [
                $document->uri => $edits,
            ],
        ];
    }

    protected function findEnclosingLoopRange(string $content, int $targetLine1Indexed): ?array
    {
        try {
            $doc = \Stillat\BladeParser\Document\Document::fromText($content);
            $loopStack = [];

            foreach ($doc->getDirectives() as $dir) {
                $name = $dir->content;
                $dLine = $dir->position ? $dir->position->startLine : 1;

                if ($name === 'foreach' || $name === 'forelse') {
                    $loopStack[] = ['startLine' => $dLine];
                } elseif ($name === 'endforeach' || $name === 'endforelse') {
                    if (!empty($loopStack)) {
                        $top = array_pop($loopStack);
                        if ($targetLine1Indexed >= $top['startLine'] && $targetLine1Indexed <= $dLine) {
                            return ['startLine' => $top['startLine'], 'endLine' => $dLine];
                        }
                    }
                }
            }
        } catch (\Throwable) {}

        return null;
    }

    protected function byteToUtf16Offset(string $line, int $byteOffset): int
    {
        $substr = substr($line, 0, $byteOffset);
        $utf16 = mb_convert_encoding($substr, 'UTF-16LE', 'UTF-8');
        return intdiv(strlen($utf16), 2);
    }

    /**
     * Find variable name and character span at the hovered character position.
     *
     * @return array{name: string, start: int, end: int}|null
     */
    protected function findVariableAtPosition(string $line, int $character): ?array
    {
        if (!preg_match_all('/\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[1] as $idx => $match) {
            $varName = $match[0];
            $dollarOffset = $matches[0][$idx][1];
            $nameOffset = $matches[1][$idx][1];
            $endOffset = $nameOffset + strlen($matches[1][$idx][0]);

            $dollarUtf16 = $this->byteToUtf16Offset($line, $dollarOffset);
            $endUtf16 = $this->byteToUtf16Offset($line, $endOffset);

            if ($character >= $dollarUtf16 && $character <= $endUtf16) {
                return [
                    'name' => $varName,
                    'start' => $nameOffset,
                    'end' => $endOffset,
                ];
            }
        }

        return null;
    }
}
