<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\PhpViewVariableRenameAnalyzer;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use Stillat\BladeParser\Document\Document as BladeDocument;
use Symfony\Component\Finder\Finder;
use Throwable;

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
    protected PhpViewVariableRenameAnalyzer $phpAnalyzer;

    public function __construct(
        protected Project $project,
    ) {
        $this->astAnalyzer = new BladePhpAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project);
        $this->phpAnalyzer = new PhpViewVariableRenameAnalyzer();
    }

    /**
     * Prepare rename for the given document and position.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function prepareRename(Document $document, array $position): ?array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        if (str_ends_with($document->uri, '.blade.php')) {
            return $this->prepareRenameInBlade($document, $lineNumber, $character);
        }

        if (str_ends_with($document->uri, '.php')) {
            return $this->prepareRenameInPhp($document, $lineNumber, $character);
        }

        return null;
    }

    protected function prepareRenameInBlade(Document $document, int $lineNumber, int $character): ?array
    {
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

    protected function prepareRenameInPhp(Document $document, int $lineNumber, int $character): ?array
    {
        $target = $this->phpAnalyzer->findTargetAtPosition($document->content, $lineNumber, $character);
        if ($target === null) {
            return null;
        }

        $varName = $target['name'];
        if (in_array($varName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        return [
            'range' => $target['range'],
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
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $cleanNewName = ltrim($newName, '$');
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $cleanNewName)) {
            return null;
        }

        if (in_array($cleanNewName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        if (str_ends_with($document->uri, '.blade.php')) {
            return $this->renameFromBlade($document, $lineNumber, $character, $cleanNewName);
        }

        if (str_ends_with($document->uri, '.php')) {
            return $this->renameFromPhp($document, $lineNumber, $character, $cleanNewName);
        }

        return null;
    }

    protected function renameFromBlade(Document $document, int $lineNumber, int $character, string $cleanNewName): ?array
    {
        $expressions = $this->astAnalyzer->extractAllExpressions($document->content);
        $targetExpr = $this->findVariableExpressionAtPosition($expressions, $lineNumber, $character);

        if ($targetExpr === null) {
            return null;
        }

        $targetVarName = $targetExpr['name'];
        if (in_array($targetVarName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        // Build directive scope hierarchy
        $scopes = $this->buildLoopScopes($document->content);
        $declaringScope = $this->findDeclaringScopeForPosition($scopes, $targetVarName, (int) $targetExpr['startOffset']);

        $bladeEdits = $this->generateEditsForBladeDocument($document, $targetVarName, $cleanNewName, $declaringScope);
        if (empty($bladeEdits)) {
            return null;
        }

        $changes = [
            $document->uri => $bladeEdits,
        ];

        // If this variable is at template scope (not inside a local loop scope), find originating PHP sources
        if ($declaringScope === null) {
            $viewKey = $this->scopeResolver->resolveViewKey($document->uri);
            $this->addPhpSourceEditsForView($changes, $viewKey, $targetVarName, $cleanNewName);
        }

        return ['changes' => $changes];
    }

    protected function renameFromPhp(Document $document, int $lineNumber, int $character, string $cleanNewName): ?array
    {
        $target = $this->phpAnalyzer->findTargetAtPosition($document->content, $lineNumber, $character);
        if ($target === null) {
            return null;
        }

        $targetVarName = $target['name'];
        if (in_array($targetVarName, self::RESERVED_VARIABLES, true)) {
            return null;
        }

        $targetViews = $target['viewNames'];
        $changes = [];

        // 1. Rename occurrences in the current PHP document
        $thisDocEdits = $this->phpAnalyzer->findEditsForViewVariable($document->content, $targetVarName, $targetViews, $cleanNewName);
        if (!empty($thisDocEdits)) {
            $changes[$document->uri] = $thisDocEdits;
        }

        // 2. Find and update all associated Blade templates
        $this->addBladeTemplateEditsForViews($changes, $targetViews, $targetVarName, $cleanNewName);

        if (empty($changes)) {
            return null;
        }

        return ['changes' => $changes];
    }

    /**
     * Add text edits from PHP files providing data to the given view key.
     *
     * @param  array<string, array<int, mixed>>  $changes
     */
    protected function addPhpSourceEditsForView(array &$changes, string $viewKey, string $targetVarName, string $cleanNewName): void
    {
        $sourcesToScan = [];

        try {
            $viewVariables = $this->project->index->viewVariables();

            // 1. Sources explicitly registered for matching view keys
            foreach ($viewVariables['views'] ?? [] as $indexedKey => $viewData) {
                if (is_array($viewData) && $this->scopeResolver->matchesViewKey((string) $indexedKey, $viewKey)) {
                    foreach ($viewData['sources'] ?? [] as $src) {
                        $sourcesToScan[$src] = true;
                    }
                }
            }

            // 2. Global share / wildcard sources
            if (isset($viewVariables['views']['*']['sources'])) {
                foreach ($viewVariables['views']['*']['sources'] as $src) {
                    $sourcesToScan[$src] = true;
                }
            }

            foreach ($viewVariables['globals'] ?? [] as $globalVar) {
                if (isset($globalVar['source']) && !empty($globalVar['source'])) {
                    $sourcesToScan[$globalVar['source']] = true;
                }
            }
        } catch (Throwable) {}

        // 3. Fallback: scan controller and provider directories if no sources found in index
        if (empty($sourcesToScan)) {
            $basePath = $this->project->path();
            $searchDirs = [
                $basePath . '/app/Http/Controllers',
                $basePath . '/routes',
                $basePath . '/app/Providers',
                $basePath . '/app/View',
                $basePath . '/app/Mail',
                $basePath . '/app/Livewire',
            ];
            foreach ($searchDirs as $dir) {
                if (!is_dir($dir)) {
                    continue;
                }
                try {
                    $files = Finder::create()->files()->name('*.php')->in($dir);
                    foreach ($files as $file) {
                        $sourcesToScan[$file->getRealPath()] = true;
                    }
                } catch (Throwable) {}
            }
        }

        foreach (array_keys($sourcesToScan) as $srcPath) {
            $absPath = $this->resolveAbsolutePath((string) $srcPath);
            if (!file_exists($absPath)) {
                continue;
            }

            $content = $this->getFileContent($absPath);
            if ($content === '') {
                continue;
            }

            if (!str_contains($content, $targetVarName)) {
                continue;
            }

            $edits = $this->phpAnalyzer->findEditsForViewVariable($content, $targetVarName, $viewKey, $cleanNewName);
            if (!empty($edits)) {
                $uri = (string) FileUri::fromPath($absPath);
                $changes[$uri] = $edits;
            }
        }
    }

    /**
     * Add text edits for Blade templates matching target view keys.
     *
     * @param  array<string, array<int, mixed>>  $changes
     * @param  array<int, string>  $targetViews
     */
    protected function addBladeTemplateEditsForViews(array &$changes, array $targetViews, string $targetVarName, string $cleanNewName): void
    {
        $matchedBladePaths = [];

        try {
            $views = $this->project->index->views();
            foreach ($views as $view) {
                $vKey = (string) ($view['key'] ?? '');
                $vPath = (string) ($view['path'] ?? '');

                if ($vKey === '' || $vPath === '') {
                    continue;
                }

                $matches = in_array('*', $targetViews, true);
                if (!$matches) {
                    foreach ($targetViews as $tView) {
                        if ($this->scopeResolver->matchesViewKey($tView, $vKey)) {
                            $matches = true;
                            break;
                        }
                    }
                }

                if ($matches) {
                    $matchedBladePaths[$vPath] = true;
                }
            }
        } catch (Throwable) {}

        // Fallback: resolve path directly for explicit view keys
        if (empty($matchedBladePaths)) {
            $basePath = rtrim($this->project->path(), '/\\');
            foreach ($targetViews as $tView) {
                if ($tView === '*' || str_contains($tView, '*')) {
                    continue;
                }

                $relPath = 'resources/views/' . str_replace('.', '/', $tView) . '.blade.php';
                $absPath = "{$basePath}/{$relPath}";
                if (file_exists($absPath)) {
                    $matchedBladePaths[$relPath] = true;
                }
            }
        }

        foreach (array_keys($matchedBladePaths) as $bPath) {
            $absPath = $this->resolveAbsolutePath((string) $bPath);
            if (!file_exists($absPath)) {
                continue;
            }

            $content = $this->getFileContent($absPath);
            if ($content === '') {
                continue;
            }

            if (!str_contains($content, '$' . $targetVarName)) {
                continue;
            }

            $uri = (string) FileUri::fromPath($absPath);
            $bladeDoc = new Document($uri, $content);
            $bladeEdits = $this->generateEditsForBladeDocument($bladeDoc, $targetVarName, $cleanNewName, null);

            if (!empty($bladeEdits)) {
                $changes[$uri] = $bladeEdits;
            }
        }
    }

    /**
     * Generate text edits for a Blade document.
     *
     * @return array<int, array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}>
     */
    public function generateEditsForBladeDocument(
        Document $document,
        string $targetVarName,
        string $cleanNewName,
        ?BladeLoopScopeInfo $declaringScope
    ): array {
        $expressions = $this->astAnalyzer->extractAllExpressions($document->content);
        $scopes = $this->buildLoopScopes($document->content);

        $minLine = 0;
        $maxLine = PHP_INT_MAX;
        $excludedOffsetRanges = [];

        if ($declaringScope !== null) {
            $minLine = $declaringScope->startLine;
            $maxLine = $declaringScope->endLine;

            if ($declaringScope->declarationOffset > $declaringScope->startOffset) {
                $excludedOffsetRanges[] = [
                    'start' => $declaringScope->startOffset,
                    'end' => $declaringScope->declarationOffset - 1,
                ];
            }

            $this->collectShadowExclusions($declaringScope->children, $targetVarName, $excludedOffsetRanges);
        } else {
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

        usort($edits, function (array $a, array $b): int {
            $lineCmp = ($a['range']['start']['line'] ?? 0) <=> ($b['range']['start']['line'] ?? 0);
            if ($lineCmp !== 0) {
                return $lineCmp;
            }

            return ($a['range']['start']['character'] ?? 0) <=> ($b['range']['start']['character'] ?? 0);
        });

        return $edits;
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
        } catch (Throwable) {}

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

    protected function resolveAbsolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return $path;
        }

        $basePath = rtrim($this->project->path(), '/\\');
        return "{$basePath}/" . ltrim($path, '/\\');
    }

    protected function getFileContent(string $absPath): string
    {
        $uri = FileUri::fromPath($absPath)->toString();
        try {
            if (isset($this->project->container)) {
                $docManager = $this->project->container->make(DocumentManager::class);
                $openDoc = $docManager->get($uri);
                if ($openDoc !== null) {
                    return $openDoc->content;
                }
            }
        } catch (Throwable) {}

        return (string) @file_get_contents($absPath);
    }
}
