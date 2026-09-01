<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Document;
use App\Lsp\Project;
use Stillat\BladeParser\Document\Document as BladeDocument;

class BladeVariableRenameProvider
{
    public const RESERVED_VARIABLES = [
        'loop',
        'errors',
        '__env',
        'this',
        'app',
    ];

    protected BladePhpAstAnalyzer $astAnalyzer;
    protected BladeScopeResolver $scopeResolver;

    public function __construct(
        protected Project $project,
    ) {
        $this->astAnalyzer = new BladePhpAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project);
    }

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
        $targetExpr = $this->findVariableExpressionAtPosition($expressions, $lineNumber, $character);

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

        $expressions = $this->astAnalyzer->extractAllExpressions($document->content);
        $targetExpr = $this->findVariableExpressionAtPosition($expressions, $lineNumber, $character);

        if ($targetExpr === null) {
            return null;
        }

        $targetVarName = $targetExpr['name'];
        if (in_array($targetVarName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        $cleanNewName = ltrim($newName, '$');
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $cleanNewName)) {
            return null;
        }

        if (in_array($cleanNewName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        // Build directive scope hierarchy
        $scopes = $this->buildLoopScopes($document->content);

        // Find the declaring loop scope for this target variable at cursor position
        $declaringScope = $this->findDeclaringScopeForPosition($scopes, $targetVarName, (int) $targetExpr['startOffset']);

        $minLine = 0;
        $maxLine = PHP_INT_MAX;
        $excludedOffsetRanges = [];

        if ($declaringScope !== null) {
            $minLine = $declaringScope->startLine;
            $maxLine = $declaringScope->endLine;

            // Expressions on declaringScope->startLine before its declaration offset belong to outer scope
            if ($declaringScope->declarationOffset > $declaringScope->startOffset) {
                $excludedOffsetRanges[] = [
                    'start' => $declaringScope->startOffset,
                    'end' => $declaringScope->declarationOffset - 1,
                ];
            }

            // Exclude child scopes that shadow this variable
            $this->collectShadowExclusions($declaringScope->children, $targetVarName, $excludedOffsetRanges);
        } else {
            // Template level: exclude all loop scopes declaring $targetVarName
            $this->collectShadowExclusions($scopes, $targetVarName, $excludedOffsetRanges);
        }

        $edits = [];
        $seenRanges = [];

        foreach ($expressions as $expr) {
            if ($expr['kind'] !== 'variable' || $expr['name'] !== $targetVarName) {
                continue;
            }

            $eLine = (int) $expr['startLine'];
            if ($eLine < $minLine || $eLine > $maxLine) {
                continue;
            }

            $startOffset = (int) $expr['startOffset'];

            // Check if this expression falls in any excluded/shadowed offset range
            if ($this->isOffsetExcluded($startOffset, $excludedOffsetRanges)) {
                continue;
            }

            $varStartCol = (int) $expr['startCol'] + 1;
            $varEndCol = (int) $expr['endCol'];
            $key = "{$eLine}:{$varStartCol}";

            if (!isset($seenRanges[$key])) {
                $seenRanges[$key] = true;
                $edits[] = [
                    'range' => [
                        'start' => ['line' => $eLine, 'character' => $varStartCol],
                        'end' => ['line' => $eLine, 'character' => $varEndCol],
                    ],
                    'newText' => $cleanNewName,
                ];
            }
        }

        if (empty($edits)) {
            return null;
        }

        // Sort edits in document order (line asc, character asc)
        usort($edits, function (array $a, array $b): int {
            $lineCmp = ($a['range']['start']['line'] ?? 0) <=> ($b['range']['start']['line'] ?? 0);
            if ($lineCmp !== 0) {
                return $lineCmp;
            }

            return ($a['range']['start']['character'] ?? 0) <=> ($b['range']['start']['character'] ?? 0);
        });

        return [
            'changes' => [
                $document->uri => $edits,
            ],
        ];
    }

    /**
     * Find matching variable expression at given line and column.
     *
     * @param  array<int, array<string, mixed>>  $expressions
     * @return array<string, mixed>|null
     */
    protected function findVariableExpressionAtPosition(array $expressions, int $lineNumber, int $character): ?array
    {
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
                return $expr;
            }
        }

        return null;
    }

    /**
     * @return array<int, BladeLoopScopeInfo>
     */
    protected function buildLoopScopes(string $content): array
    {
        $allScopes = [];
        $stack = [];

        try {
            $doc = BladeDocument::fromText($content);
            foreach ($doc->getDirectives() as $dir) {
                $name = (string) $dir->content;
                $dStartLine = $dir->position ? $dir->position->startLine - 1 : 0;
                $dEndLine = $dir->position ? $dir->position->endLine - 1 : $dStartLine;
                $dStartOffset = $dir->position ? $dir->position->startOffset : 0;
                $dEndOffset = $dir->position ? $dir->position->endOffset : $dStartOffset;

                if (in_array($name, ['foreach', 'forelse', 'for', 'while'], true)) {
                    $scope = new BladeLoopScopeInfo();
                    $scope->type = $name;
                    $scope->startLine = $dStartLine;
                    $scope->startOffset = $dStartOffset;
                    $scope->declarationOffset = $dStartOffset;
                    $args = (string) $dir->arguments;

                    if ($name === 'foreach' || $name === 'forelse') {
                        if (preg_match('/\bas\b/i', $args, $asMatch, PREG_OFFSET_CAPTURE)) {
                            $asPos = (int) $asMatch[0][1];
                            $afterAs = substr($args, $asPos);
                            if (preg_match_all('/\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $afterAs, $vm)) {
                                $scope->declaredVariables = array_fill_keys($vm[1], true);
                            }
                            $argsStart = $dir->arguments && $dir->arguments->position
                                ? $dir->arguments->position->startOffset
                                : $dStartOffset + strlen($name) + 1;
                            $scope->declarationOffset = $argsStart + $asPos;
                        }
                    } elseif ($name === 'for') {
                        $firstClause = explode(';', $args)[0] ?? '';
                        if (preg_match_all('/\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $firstClause, $vm)) {
                            $scope->declaredVariables = array_fill_keys($vm[1], true);
                        }
                        $scope->declarationOffset = $dStartOffset;
                    }

                    if (!empty($stack)) {
                        $parent = end($stack);
                        $scope->parent = $parent;
                        $parent->children[] = $scope;
                    } else {
                        $allScopes[] = $scope;
                    }

                    $stack[] = $scope;
                } elseif (in_array($name, ['endforeach', 'endforelse', 'endfor', 'endwhile'], true)) {
                    if (!empty($stack)) {
                        $top = array_pop($stack);
                        $top->endLine = $dEndLine;
                        $top->endOffset = $dEndOffset;
                    }
                }
            }
        } catch (\Throwable) {}

        return $allScopes;
    }

    /**
     * @param  array<int, BladeLoopScopeInfo>  $scopes
     */
    protected function findDeclaringScopeForPosition(array $scopes, string $varName, int $offset): ?BladeLoopScopeInfo
    {
        $candidate = null;

        foreach ($scopes as $scope) {
            if ($offset >= $scope->startOffset && $offset <= $scope->endOffset) {
                if (isset($scope->declaredVariables[$varName]) && $offset >= $scope->declarationOffset) {
                    $candidate = $scope;
                }

                $childCandidate = $this->findDeclaringScopeForPosition($scope->children, $varName, $offset);
                if ($childCandidate !== null) {
                    $candidate = $childCandidate;
                }
            }
        }

        return $candidate;
    }

    /**
     * @param  array<int, BladeLoopScopeInfo>  $scopes
     * @param  array<int, array{start: int, end: int}>  $excluded
     */
    protected function collectShadowExclusions(array $scopes, string $varName, array &$excluded): void
    {
        foreach ($scopes as $scope) {
            if (isset($scope->declaredVariables[$varName])) {
                $excluded[] = [
                    'start' => $scope->declarationOffset,
                    'end' => $scope->endOffset,
                ];
            } else {
                $this->collectShadowExclusions($scope->children, $varName, $excluded);
            }
        }
    }

    /**
     * @param  array<int, array{start: int, end: int}>  $excludedRanges
     */
    protected function isOffsetExcluded(int $offset, array $excludedRanges): bool
    {
        foreach ($excludedRanges as $range) {
            if ($offset >= $range['start'] && $offset <= $range['end']) {
                return true;
            }
        }

        return false;
    }
}
