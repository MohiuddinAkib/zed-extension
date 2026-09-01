<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Analysis\DocBlockParser;
use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Contracts\Method;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\Facades\FacadeMap;
use App\Lsp\Project;
use App\Lsp\Semantics\MacroSymbol;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Position;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;
use Illuminate\Container\Container;

class TextDocumentDefinition implements Method
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
        protected Project $project,
    ) {}

    /**
     * Handle the textDocument/definition request.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get(
            (string) $request->get('textDocument.uri')
        );

        if ($document === null) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $position = $request->get('position', []);

        if (!is_array($position)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        if (!is_int($position['line'] ?? null) || !is_int($position['character'] ?? null)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $locationLinks = [];

        foreach ($this->links($request, $document) as $link) {
            $range = $link['range'] ?? null;

            if (!is_array($range) || !Position::inRange($range, $position)) {
                continue;
            }

            $locationLinks[] = $this->locationLink($link, $range);
        }

        if (empty($locationLinks)) {
            $macroDef = $this->resolveMacroDefinition($document, $position);
            if ($macroDef !== null) {
                return JsonRpcResponse::result($request->id(), [$macroDef]);
            }
        }

        if (empty($locationLinks) && str_ends_with($document->uri, '.blade.php')) {
            try {
                $scope = $this->features->scopeResolver()->resolveAtPosition($document, (int) $position['line'], (int) $position['character']);
                $virtualDoc = $this->features->bladeCompiler()->compile($document, $scope);
                $virtualPos = $virtualDoc->sourceMap->bladeToVirtualPosition((int) $position['line'], (int) $position['character']);
                if ($virtualPos !== null) {
                    $phpDefs = $this->features->phpIntelligence()->definition($virtualDoc, $virtualPos);
                    if (!empty($phpDefs)) {
                        return JsonRpcResponse::result($request->id(), $phpDefs);
                    }
                }
            } catch (\Throwable) {}
        }

        return JsonRpcResponse::result($request->id(), $locationLinks);
    }

    /**
     * Resolve definition location for macros on static or instance calls.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    protected function resolveMacroDefinition(Document $document, array $position): ?array
    {
        $macroRegistry = Container::getInstance()->bound(MacroRegistry::class)
            ? Container::getInstance()->make(MacroRegistry::class)
            : new MacroRegistry($this->project);

        $lineNum = (int) $position['line'];
        $char = (int) $position['character'];
        $lines = explode("\n", $document->content);
        $line = $lines[$lineNum] ?? '';

        // 1. Static call: Facade::macroName or Class::macroName
        if (preg_match_all('/([a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]+)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $class = $m[1][0];
                $method = $m[2][0];
                $methodStart = $m[2][1];
                $methodEnd = $methodStart + strlen($method);
                $fullStart = $m[0][1];
                $fullEnd = $fullStart + strlen($m[0][0]);

                if ($char >= $fullStart && $char <= $fullEnd) {
                    $macro = $macroRegistry->getMacro($class, $method);
                    if ($macro === null && FacadeMap::isFacadeOrAlias($class)) {
                        $target = FacadeMap::resolve($class);
                        $macro = $macroRegistry->getMacro($target, $method);
                        if ($macro === null && ($accessor = FacadeMap::resolveAccessor($class))) {
                            $macro = $macroRegistry->getMacro($accessor, $method);
                        }
                    }
                    if ($macro && $macro->sourcePath) {
                        return $this->buildMacroLocationLink($macro, $lineNum, $methodStart, $methodEnd);
                    }
                }
            }
        }

        // 2. Instance call: $var->macroName
        if (preg_match_all('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]+)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $varName = $m[1][0];
                $method = $m[3][0];
                $methodStart = $m[3][1];
                $methodEnd = $methodStart + strlen($method);

                if ($char >= $methodStart && $char <= $methodEnd) {
                    $varType = $this->inferVariableTypeFromDocBlock($document, $varName);
                    if ($varType !== null) {
                        $macro = $macroRegistry->getMacro($varType, $method);
                        if ($macro && $macro->sourcePath) {
                            return $this->buildMacroLocationLink($macro, $lineNum, $methodStart, $methodEnd);
                        }
                    } else {
                        $macro = $macroRegistry->getMacro($varName, $method);
                        if ($macro && $macro->sourcePath) {
                            return $this->buildMacroLocationLink($macro, $lineNum, $methodStart, $methodEnd);
                        }
                    }
                }
            }
        }

        // 3. Static call chain: Facade::init()->macroName
        if (preg_match_all('/([a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]+)(?:\([^\)]*\))?((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]+)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rootClass = $m[1][0];
                $method = $m[4][0];
                $methodStart = $m[4][1];
                $methodEnd = $methodStart + strlen($method);

                if ($char >= $methodStart && $char <= $methodEnd) {
                    $macro = $macroRegistry->getMacro($rootClass, $method);
                    if ($macro === null && FacadeMap::isFacadeOrAlias($rootClass)) {
                        $target = FacadeMap::resolve($rootClass);
                        $macro = $macroRegistry->getMacro($target, $method);
                        if ($macro === null && ($accessor = FacadeMap::resolveAccessor($rootClass))) {
                            $macro = $macroRegistry->getMacro($accessor, $method);
                        }
                    }
                    if ($macro && $macro->sourcePath) {
                        return $this->buildMacroLocationLink($macro, $lineNum, $methodStart, $methodEnd);
                    }
                }
            }
        }

        return null;
    }

    protected function inferVariableTypeFromDocBlock(Document $document, string $varName): ?string
    {
        $docBlockParser = new DocBlockParser();
        if (preg_match_all('/\/\*\*[\s\S]*?\*\//', $document->content, $matches)) {
            foreach (array_reverse($matches[0]) as $docComment) {
                $varTags = $docBlockParser->extractVarTags($docComment);
                if (isset($varTags[$varName])) {
                    return $varTags[$varName];
                }
                if (isset($varTags['']) && count($varTags) === 1) {
                    return $varTags[''];
                }
                $paramTags = $docBlockParser->extractParamTags($docComment);
                if (isset($paramTags[$varName])) {
                    return $paramTags[$varName];
                }
            }
        }

        return null;
    }

    protected function buildMacroLocationLink(MacroSymbol $macro, int $lineNum, int $startCol, int $endCol): array
    {
        $basePath = rtrim($this->project->path(), '/\\');
        $absPath = str_starts_with((string) $macro->sourcePath, '/') ? (string) $macro->sourcePath : "{$basePath}/{$macro->sourcePath}";
        $targetUri = FileUri::fromPath($absPath);
        $targetLine = max(0, ($macro->sourceLine ?? 1) - 1);
        $targetRange = [
            'start' => ['line' => $targetLine, 'character' => 0],
            'end'   => ['line' => $targetLine, 'character' => 0],
        ];

        return [
            'originSelectionRange' => [
                'start' => ['line' => $lineNum, 'character' => $startCol],
                'end'   => ['line' => $lineNum, 'character' => $endCol],
            ],
            'targetUri'            => (string) $targetUri,
            'targetRange'          => $targetRange,
            'targetSelectionRange' => $targetRange,
        ];
    }

    /**
     * Get document links from every registered link provider.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function links(JsonRpcRequest $request, Document $document): array
    {
        $links = [];

        foreach ($this->features->links() as $provider) {
            array_push($links, ...$provider->get($document));

            $request->cancelIfRequested();
        }

        return $this->uniqueLinks($links);
    }

    /**
     * @param  array<int, array<string, mixed>>  $links
     * @return array<int, array<string, mixed>>
     */
    protected function uniqueLinks(array $links): array
    {
        $seen = [];
        $unique = [];

        foreach ($links as $link) {
            $key = json_encode([
                'range' => $link['range'] ?? null,
                'target' => (string) ($link['target'] ?? ''),
            ]);

            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $link;
        }

        return $unique;
    }

    /**
     * Convert a document link into a definition LocationLink.
     *
     * @param  array<string, mixed>  $link
     * @param  array<string, mixed>  $originRange
     * @return array<string, mixed>
     */
    protected function locationLink(array $link, array $originRange): array
    {
        [$targetUri, $targetRange] = $this->target((string) $link['target']);

        return [
            'originSelectionRange' => $originRange,
            'targetUri'            => $targetUri,
            'targetRange'          => $targetRange,
            'targetSelectionRange' => $targetRange,
        ];
    }

    /**
     * Get the target URI and range for a document link target.
     *
     * @return array{0: string, 1: array<string, array<string, int>>}
     */
    protected function target(string $target): array
    {
        $targetUri = $target;
        $line = 0;

        if (preg_match('/^(.*)#L([1-9][0-9]*)$/', $target, $matches) === 1) {
            $targetUri = $matches[1];
            $line = ((int) $matches[2]) - 1;
        }

        return [
            $targetUri,
            [
                'start' => [
                    'line'      => $line,
                    'character' => 0,
                ],
                'end' => [
                    'line'      => $line,
                    'character' => 0,
                ],
            ],
        ];
    }
}
