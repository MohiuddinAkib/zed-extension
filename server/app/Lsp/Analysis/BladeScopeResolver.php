<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Semantics\ScopeOrigin;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VariableSymbol;
use App\Lsp\Semantics\ViewScope;
use App\Lsp\Support\FileUri;
use Throwable;

class BladeScopeResolver
{
    protected DocBlockParser $docBlockParser;

    public function __construct(
        protected Project $project,
        protected ?BladeAstAnalyzer $bladeAnalyzer = null,
    ) {
        $this->bladeAnalyzer ??= new BladeAstAnalyzer();
        $this->docBlockParser = new DocBlockParser();
    }

    public function resolve(Document $document, ?string $viewKey = null): ViewScope
    {
        $viewKey ??= $this->resolveViewKey($document->uri);
        $scope = new ViewScope($viewKey);
        $data = $this->project->index->viewVariables();
        $isComponent = $this->isComponentView($document->uri, $viewKey, $document->content);

        // 1. Default Framework Globals ($__env, $errors, $app, $request)
        foreach (\App\Lsp\Data\ViewVariables::defaultGlobals() as $defGlobal) {
            $scope->addVariable(VariableSymbol::fromLegacy($defGlobal, (string) $defGlobal['name']));
        }

        // 2. Project Index Globals
        foreach ($data['globals'] ?? [] as $global) {
            if (!is_array($global)) {
                continue;
            }

            $originName = (string) ($global['origin'] ?? 'Global');
            if ($originName === 'Component' && !$isComponent) {
                continue;
            }

            $scope->addVariable(VariableSymbol::fromLegacy($global, isset($global['name']) ? (string) $global['name'] : null));
        }

        if ($isComponent) {
            foreach (\App\Lsp\Data\ViewVariables::componentGlobals() as $compGlobal) {
                $scope->addVariable(VariableSymbol::fromLegacy($compGlobal, (string) $compGlobal['name']));
            }
        }

        if (isset($data['views'][$viewKey]) && is_array($data['views'][$viewKey])) {
            $indexedScope = ViewScope::fromLegacy($viewKey, $data['views'][$viewKey]);
            $scope->merge($indexedScope);
        }

        $relSource = $this->relativePath($document->uri);
        foreach ($this->bladeAnalyzer->extractTemplateSymbols($document->content, $relSource) as $symbol) {
            $scope->addVariable($symbol);
        }

        return $scope;
    }

    public function isComponentView(string $uri, string $viewKey, string $content = ''): bool
    {
        $path = str_replace('\\', '/', $uri);

        return str_contains($path, '/components/')
            || str_starts_with($viewKey, 'components.')
            || str_starts_with($viewKey, 'components::')
            || str_contains($content, '@props')
            || str_contains($content, '@aware');
    }

    /**
     * Resolve contextual ViewScope at a specific 0-indexed line & character in the Blade document.
     */
    public function resolveAtPosition(Document $document, int $line, int $character, ?string $viewKey = null): ViewScope
    {
        $viewKey ??= $this->resolveViewKey($document->uri);
        $scope = new ViewScope($viewKey);
        $data = $this->project->index->viewVariables();
        $isComponent = $this->isComponentView($document->uri, $viewKey, $document->content);

        // 1. Default Framework Globals ($__env, $errors, $app, $request)
        foreach (\App\Lsp\Data\ViewVariables::defaultGlobals() as $defGlobal) {
            $scope->addVariable(VariableSymbol::fromLegacy($defGlobal, (string) $defGlobal['name']));
        }

        // 2. Project Index Globals
        foreach ($data['globals'] ?? [] as $global) {
            if (!is_array($global)) {
                continue;
            }

            $originName = (string) ($global['origin'] ?? 'Global');
            if ($originName === 'Component' && !$isComponent) {
                continue;
            }

            $scope->addVariable(VariableSymbol::fromLegacy($global, isset($global['name']) ? (string) $global['name'] : null));
        }

        if ($isComponent) {
            foreach (\App\Lsp\Data\ViewVariables::componentGlobals() as $compGlobal) {
                $scope->addVariable(VariableSymbol::fromLegacy($compGlobal, (string) $compGlobal['name']));
            }
        }

        // 2. Controller/View indexed variables
        if (isset($data['views'][$viewKey]) && is_array($data['views'][$viewKey])) {
            $indexedScope = ViewScope::fromLegacy($viewKey, $data['views'][$viewKey]);
            $scope->merge($indexedScope);
        }

        $bladeLineIndex = $line + 1; // 1-indexed

        // 3. Template-level symbols filtered by line availability
        $relSource = $this->relativePath($document->uri);
        foreach ($this->bladeAnalyzer->extractTemplateSymbols($document->content, $relSource) as $symbol) {
            // @php local assignments are only visible from their declaration line onwards
            if ($symbol->origin->name === '@php' && $symbol->range !== null) {
                if ($bladeLineIndex < $symbol->range->startLine) {
                    continue;
                }
            }

            $scope->addVariable($symbol);
        }

        try {
            $lines = explode("\n", $document->content);
            $lineOffsets = $this->calculateLineOffsets($document->content);

            $doc = \Stillat\BladeParser\Document\Document::fromText($document->content);
            $allDirectives = $doc->getDirectives()->all();

            // 4. Match paired @foreach / @endforeach and @forelse / @endforelse ranges
            $loopStack = [];
            $loopRanges = [];

            foreach ($allDirectives as $dir) {
                $name = $dir->content;
                $dLine = 1;
                if ($dir->position) {
                    $startChar = $dir->position->startOffset;
                    $startByte = strlen(mb_substr($document->content, 0, $startChar));
                    $loc = $this->offsetToLineAndCol($startByte, $lineOffsets);
                    $dLine = $loc['line'] + 1;
                }

                if ($name === 'foreach' || $name === 'forelse') {
                    $args = $dir->arguments ? (string) ($dir->arguments->innerContent ?? $dir->arguments) : '';
                    if (str_starts_with($args, '(') && str_ends_with($args, ')')) {
                        $args = substr($args, 1, -1);
                    }
                    $loopStack[] = [
                        'startLine' => $dLine,
                        'args' => $args,
                        'name' => $name,
                    ];
                } elseif ($name === 'endforeach' || $name === 'endforelse') {
                    if (!empty($loopStack)) {
                        $top = array_pop($loopStack);
                        $loopRanges[] = [
                            'startLine' => $top['startLine'],
                            'endLine' => $dLine,
                            'args' => $top['args'],
                            'name' => $top['name'],
                        ];
                    }
                }
            }

            // Unclosed loops fallback to end of document
            while (!empty($loopStack)) {
                $top = array_pop($loopStack);
                $loopRanges[] = [
                    'startLine' => $top['startLine'],
                    'endLine' => count($lines),
                    'args' => $top['args'],
                    'name' => $top['name'],
                ];
            }

            foreach ($loopRanges as $range) {
                if ($bladeLineIndex < $range['startLine'] || $bladeLineIndex > $range['endLine']) {
                    continue;
                }

                $args = $range['args'];
                if ($args === '') {
                    continue;
                }

                $parsedForeach = $this->parseForeachArgs($args);
                if ($parsedForeach !== null) {
                    $collExpr = $parsedForeach['collection'];
                    $keyName = $parsedForeach['key'];
                    $itemName = $parsedForeach['item'];

                    $itemType = 'mixed';
                    if ($parsedForeach['rootVar'] && isset($scope->variables[$parsedForeach['rootVar']])) {
                        $collType = $scope->variables[$parsedForeach['rootVar']]->type->displayName;
                        $unwrapped = $this->docBlockParser->unwrapItemType($collType);
                        if ($unwrapped) {
                            $itemType = $unwrapped;
                        }
                    }

                    // Add $item and optional $key
                    $scope->addVariable(new VariableSymbol(
                        name: $itemName,
                        type: TypeRef::fromString($itemType),
                        origin: new ScopeOrigin('@foreach', $this->relativePath($document->uri), $range['startLine'], "Iteration item from {$collExpr}"),
                        detail: "Iteration variable of type {$itemType}",
                    ));

                    if ($keyName) {
                        $scope->addVariable(new VariableSymbol(
                            name: $keyName,
                            type: TypeRef::fromString('int|string'),
                            origin: new ScopeOrigin('@foreach', $this->relativePath($document->uri), $range['startLine'], "Iteration key from {$collExpr}"),
                            detail: 'Iteration key',
                        ));
                    }

                    // Add contextual $loop variable
                    $scope->addVariable(new VariableSymbol(
                        name: 'loop',
                        type: TypeRef::fromString('object'),
                        origin: new ScopeOrigin('@foreach', null, $range['startLine'], 'Blade Loop Variable'),
                        detail: 'Laravel Loop Variable ($loop->iteration, $loop->index, $loop->first, $loop->last, $loop->count, $loop->depth, $loop->parent)',
                    ));
                }
            }

            // 5. Match paired @error / @enderror ranges
            $errorStack = [];
            $errorRanges = [];
            foreach ($allDirectives as $dir) {
                $name = $dir->content;
                $dLine = 1;
                if ($dir->position) {
                    $startChar = $dir->position->startOffset;
                    $startByte = strlen(mb_substr($document->content, 0, $startChar));
                    $loc = $this->offsetToLineAndCol($startByte, $lineOffsets);
                    $dLine = $loc['line'] + 1;
                }
                if ($name === 'error') {
                    $errorStack[] = $dLine;
                } elseif ($name === 'enderror') {
                    if (!empty($errorStack)) {
                        $start = array_pop($errorStack);
                        $errorRanges[] = ['startLine' => $start, 'endLine' => $dLine];
                    }
                }
            }
            while (!empty($errorStack)) {
                $start = array_pop($errorStack);
                $errorRanges[] = ['startLine' => $start, 'endLine' => count($lines)];
            }

            foreach ($errorRanges as $er) {
                if ($bladeLineIndex >= $er['startLine'] && $bladeLineIndex <= $er['endLine']) {
                    $scope->addVariable(new VariableSymbol(
                        name: 'message',
                        type: TypeRef::fromString('string'),
                        origin: new ScopeOrigin('@error', $this->relativePath($document->uri), $er['startLine'], 'Validation error message'),
                        detail: 'Validation error message ($message)',
                    ));
                }
            }
        } catch (Throwable) {}

        return $scope;
    }

    /**
     * @return array{collection: string, rootVar: ?string, key: ?string, item: string}|null
     */
    protected function parseForeachArgs(string $args): ?array
    {
        $code = '<?php foreach(' . $args . '){}';
        try {
            $stmts = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($code);
            if (!empty($stmts) && $stmts[0] instanceof \PhpParser\Node\Stmt\Foreach_) {
                $fe = $stmts[0];
                $itemName = $fe->valueVar instanceof \PhpParser\Node\Expr\Variable && is_string($fe->valueVar->name)
                    ? $fe->valueVar->name
                    : null;
                $keyName = $fe->keyVar instanceof \PhpParser\Node\Expr\Variable && is_string($fe->keyVar->name)
                    ? $fe->keyVar->name
                    : null;
                $rootVar = $fe->expr instanceof \PhpParser\Node\Expr\Variable && is_string($fe->expr->name)
                    ? $fe->expr->name
                    : null;

                if ($itemName !== null) {
                    return [
                        'collection' => $rootVar ? "\${$rootVar}" : 'collection',
                        'rootVar' => $rootVar,
                        'key' => $keyName,
                        'item' => $itemName,
                    ];
                }
            }
        } catch (\Throwable) {}

        if (preg_match('/\$([a-zA-Z0-9_]+)\s+as\s+(?:\$([a-zA-Z0-9_]+)\s*=>\s*)?\$([a-zA-Z0-9_]+)/', $args, $m)) {
            return [
                'collection' => "\${$m[1]}",
                'rootVar' => $m[1],
                'key' => !empty($m[2]) ? $m[2] : null,
                'item' => $m[3],
            ];
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function legacyVariables(Document $document, ?string $viewKey = null): array
    {
        return $this->resolve($document, $viewKey)->legacyVariables();
    }

    public function syntheticGlobal(string $name, string $type, string $origin = 'Global', string $detail = ''): VariableSymbol
    {
        return new VariableSymbol(
            name: $name,
            type: TypeRef::fromString($type),
            origin: new ScopeOrigin($origin, null, null, $detail !== '' ? $detail : null),
            detail: $detail,
        );
    }

    protected function relativePath(string $uriOrPath): string
    {
        $path = str_starts_with($uriOrPath, 'file://') ? FileUri::of($uriOrPath)->path() : $uriOrPath;
        $base = rtrim($this->project->path(), '/\\');
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $base);

        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
    }

    public function resolveViewKey(string $uri): string
    {
        $path = str_replace('\\', '/', $uri);

        try {
            $views = $this->project->index->views();
            $matched = $views->first(function ($view) use ($path) {
                $viewPath = str_replace('\\', '/', $view['path'] ?? '');
                return $viewPath !== '' && str_ends_with($path, $viewPath);
            });
            if ($matched && !empty($matched['key'])) {
                return $matched['key'];
            }
        } catch (Throwable) {}

        if (preg_match('/resources\/views\/vendor\/([^\/]+)\/(.+)\.blade\.php$/', $path, $matches)) {
            return "{$matches[1]}::" . str_replace('/', '.', $matches[2]);
        }

        if (preg_match('/Modules\/([^\/]+)\/resources\/views\/(.+)\.blade\.php$/i', $path, $matches)) {
            return strtolower($matches[1]) . '::' . str_replace('/', '.', $matches[2]);
        }

        if (preg_match('/resources\/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        if (preg_match('/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return basename($path, '.blade.php');
    }

    protected function calculateLineOffsets(string $content): array
    {
        $offsets = [0];
        $pos = 0;
        while (($pos = strpos($content, "\n", $pos)) !== false) {
            $pos++;
            $offsets[] = $pos;
        }
        return $offsets;
    }

    protected function offsetToLineAndCol(int $offset, array $lineOffsets): array
    {
        $low = 0;
        $high = count($lineOffsets) - 1;
        $line = 0;

        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            if ($lineOffsets[$mid] <= $offset) {
                $line = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        $col = $offset - $lineOffsets[$line];
        return ['line' => $line, 'col' => max(0, $col)];
    }
}
