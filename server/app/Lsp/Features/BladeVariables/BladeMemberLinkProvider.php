<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Features\AppBindings\AppBindingContainerTypeMap;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;

class BladeMemberLinkProvider implements LinkProvider
{
    protected BladeAstAnalyzer $bladeAnalyzer;
    protected BladePhpAstAnalyzer $bladePhpAstAnalyzer;
    protected BladeScopeResolver $scopeResolver;
    protected BladeMemberHoverProvider $hoverHelper;

    public function __construct(
        protected Project $project,
    ) {
        $this->bladeAnalyzer = new BladeAstAnalyzer();
        $this->bladePhpAstAnalyzer = new BladePhpAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
        $this->hoverHelper = new BladeMemberHoverProvider($this->project);
    }

    /**
     * Get document links for object members and array keys in the Blade document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $viewKey = $this->resolveViewKey($document->uri);
        $links = [];
        $expressions = $this->bladePhpAstAnalyzer->extractAllExpressions($document->content);

        $this->ensureAutoloaderRegistered();

        foreach ($expressions as $expr) {
            if ($expr['kind'] === 'variable') {
                continue;
            }

            $varName = $expr['rootVar'] ?? '';
            $rootCall = $expr['rootCall'] ?? null;
            $rootCallArg = $expr['rootCallArg'] ?? null;
            $chain = $expr['chain'];
            $memberName = $expr['name'];
            $isArrayAccess = $expr['isArrayAccess'];
            $variables = $this->collectVariablesForView($document, $viewKey, ['line' => $expr['startLine'], 'character' => $expr['startCol']]);

            $rootType = 'mixed';
            $accessorType = null;
            $varSource = null;
            $varLine = null;

            if ($rootCall === 'class') {
                $rootClass = $rootCallArg ?: $varName;
                $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
                if (isset($importedUses[$rootClass])) {
                    $rootType = $importedUses[$rootClass]['class'];
                } elseif (\App\Lsp\Features\Facades\FacadeMap::isFacadeOrAlias($rootClass)) {
                    $rootType = \App\Lsp\Features\Facades\FacadeMap::resolve($rootClass);
                    $accessorType = \App\Lsp\Features\Facades\FacadeMap::resolveAccessor($rootClass);
                } else {
                    $rootType = '\\' . ltrim($rootClass, '\\');
                }
            } elseif ($rootCall !== null) {
                $rootType = match ($rootCall) {
                    'app', 'resolve' => $rootCallArg ? AppBindingContainerTypeMap::resolveType($rootCallArg) : '\Illuminate\Foundation\Application',
                    'auth' => '\Illuminate\Auth\AuthManager',
                    'request' => '\Illuminate\Http\Request',
                    'session' => '\Illuminate\Session\SessionManager',
                    'now', 'today' => '\Illuminate\Support\Carbon',
                    default => null,
                };
            } elseif ($varName !== '' && isset($variables[$varName])) {
                $rootType = $variables[$varName]['type'] ?? 'mixed';
                $rootType = $this->hoverHelper->qualifyType($rootType, $document);
                $varSource = $variables[$varName]['source'] ?? null;
                $varLine = $variables[$varName]['line'] ?? null;
            }

            if (!$rootType || $rootType === 'mixed') {
                continue;
            }

            $targetType = $this->resolveChainedType($rootType, $chain);
            if (!$targetType || $targetType === 'mixed') {
                continue;
            }

            $memberData = $this->hoverHelper->resolveMemberDetails($targetType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), $varSource, $varLine);
            if ($memberData === null && $accessorType !== null && $accessorType !== $targetType) {
                $memberData = $this->hoverHelper->resolveMemberDetails($accessorType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), $varSource, $varLine);
            }

            $source = $memberData['source'] ?? null;
            $targetLine = (int) ($memberData['line'] ?? 1);

            // Fallback to class file if member source is not directly resolved (e.g. Eloquent attribute or method)
            if (!$source || !is_string($source)) {
                $cleanTarget = ltrim(preg_replace('/\|null|\?/', '', $targetType), '\\');
                $classFile = $this->resolveClassFilePath($cleanTarget);
                if ($classFile && file_exists($classFile)) {
                    $source = $classFile;
                    $targetLine = 1;
                    $fileContent = @file_get_contents($classFile);
                    if ($fileContent) {
                        $fLines = explode("\n", $fileContent);
                        foreach ($fLines as $idx => $lText) {
                            if (preg_match('/(?:function|var|public|protected|private)\s+(?:\$)?' . preg_quote($memberName, '/') . '\b/i', $lText)) {
                                $targetLine = $idx + 1;
                                break;
                            }
                        }
                    }
                }
            }

            if ($source && is_string($source)) {
                $basePath = rtrim($this->project->path(), '/\\');
                $absPath = str_starts_with($source, '/') ? $source : "{$basePath}/{$source}";

                if (file_exists($absPath)) {
                    $targetUri = FileUri::fromPath($absPath);
                    $links[] = [
                        'range' => [
                            'start' => ['line' => $expr['startLine'], 'character' => $expr['startCol']],
                            'end' => ['line' => $expr['startLine'], 'character' => $expr['endCol']],
                        ],
                        'target' => "{$targetUri}#L{$targetLine}",
                        'tooltip' => "Go to definition: {$source}:{$targetLine}",
                    ];
                }
            }
        }

        // 2. Add links for @use('...') directives
        $lines = explode("\n", $document->content);
        foreach ($this->bladeAnalyzer->extractUseDirectives($document->content) as $alias => $uInfo) {
            $classFqcn = $uInfo['class'];
            $clean = ltrim($classFqcn, '\\');
            $classFile = $this->resolveClassFilePath($clean);
            if ($classFile && file_exists($classFile)) {
                $lineIdx = max(0, $uInfo['line'] - 1);
                $lineContent = $lines[$lineIdx] ?? '';
                if (preg_match('/@use\s*\(\s*([\'"]?' . preg_quote($clean, '/') . '[\'"]?)/', $lineContent, $m, PREG_OFFSET_CAPTURE)) {
                    $startCol = $m[1][1];
                    $endCol = $startCol + strlen($m[1][0]);
                    $links[] = [
                        'range' => [
                            'start' => ['line' => $lineIdx, 'character' => $startCol],
                            'end' => ['line' => $lineIdx, 'character' => $endCol],
                        ],
                        'target' => (string) FileUri::fromPath($classFile),
                        'tooltip' => "Go to {$clean}",
                    ];
                }
            }
        }

        // 3. Add links for global & custom helper functions (e.g. custom_helper())
        $functionRegistry = new \App\Lsp\Features\Functions\GlobalFunctionRegistry($this->project);
        foreach ($expressions as $expr) {
            if ($expr['rootCall'] !== null && $expr['rootCall'] !== 'class') {
                $fnName = $expr['rootCall'];
                $fnInfo = $functionRegistry->get($fnName);
                if ($fnInfo && !empty($fnInfo['source']) && file_exists($fnInfo['source'])) {
                    $lineIdx = (int) $expr['startLine'];
                    $lineContent = $lines[$lineIdx] ?? '';
                    if (preg_match_all('/\b' . preg_quote($fnName, '/') . '\s*\(/', $lineContent, $m, PREG_OFFSET_CAPTURE)) {
                        foreach ($m[0] as $fnMatch) {
                            $startCol = $fnMatch[1];
                            $endCol = $startCol + strlen($fnName);
                            $fnLine = (int) ($fnInfo['line'] ?? 1);
                            $targetUri = FileUri::fromPath($fnInfo['source']);
                            $links[] = [
                                'range' => [
                                    'start' => ['line' => $lineIdx, 'character' => $startCol],
                                    'end' => ['line' => $lineIdx, 'character' => $endCol],
                                ],
                                'target' => "{$targetUri}#L{$fnLine}",
                                'tooltip' => "Go to function {$fnName}()",
                            ];
                        }
                    }
                }
            }
        }

        return $links;
    }

    protected function resolveChainedType(string $rootType, string $chain): string
    {
        if ($chain === '') {
            return $rootType;
        }

        $currentType = $rootType;
        if (preg_match_all('/(?:->|\?->|\[\s*[\'"]?)([a-zA-Z0-9_]+)(?:[\'"]?\s*\]|\([^\)]*\))?/', $chain, $m)) {
            foreach ($m[1] as $member) {
                $details = $this->hoverHelper->resolveMemberDetails($currentType, $member, false, 'item');
                if ($details && !empty($details['type'])) {
                    $currentType = $details['type'];
                } else {
                    break;
                }
            }
        }

        return $currentType;
    }

    /**
     * Collect all variables available for the current view.
     *
     * @param array<string, mixed>|null $position
     * @return array<string, array<string, mixed>>
     */
    protected function collectVariablesForView(Document $document, string $viewKey, ?array $position = null): array
    {
        if ($position !== null && isset($position['line'], $position['character'])) {
            return $this->scopeResolver->resolveAtPosition($document, (int) $position['line'], (int) $position['character'], $viewKey)->legacyVariables();
        }

        return $this->scopeResolver->legacyVariables($document, $viewKey);
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

    /**
     * Resolve the dot-notation view key from the file URI.
     */
    protected function resolveViewKey(string $uri): string
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
        } catch (\Throwable) {}

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

    protected function resolveClassFilePath(string $className): ?string
    {
        $clean = ltrim($className, '\\');
        $basePath = rtrim($this->project->path(), '/\\');

        if (str_starts_with($clean, 'App\\')) {
            $subPath = str_replace('\\', '/', substr($clean, 4)) . '.php';
            $file = "{$basePath}/app/{$subPath}";
            if (file_exists($file)) {
                return $file;
            }
        }

        if (str_starts_with($clean, 'Modules\\')) {
            $parts = explode('\\', substr($clean, 8));
            $modName = array_shift($parts);
            $subPath = implode('/', $parts) . '.php';
            $candidates = [
                "{$basePath}/Modules/{$modName}/app/{$subPath}",
                "{$basePath}/Modules/{$modName}/src/{$subPath}",
                "{$basePath}/Modules/{$modName}/{$subPath}",
            ];
            foreach ($candidates as $c) {
                if (file_exists($c)) {
                    return $c;
                }
            }
        }

        try {
            $autoloader = $basePath . '/vendor/autoload.php';
            if (file_exists($autoloader)) {
                require_once $autoloader;
            }
            if (class_exists($clean) || interface_exists($clean) || enum_exists($clean)) {
                $ref = new \ReflectionClass($clean);
                $fn = $ref->getFileName();
                if ($fn && file_exists($fn)) {
                    return $fn;
                }
            }
        } catch (\Throwable) {}

        return null;
    }

    protected function ensureAutoloaderRegistered(): void
    {
        $basePath = rtrim($this->project->path(), '/\\');
        $autoloadPath = "{$basePath}/vendor/autoload.php";
        if (file_exists($autoloadPath)) {
            try {
                @include_once $autoloadPath;
            } catch (\Throwable) {}
        }
    }
}
