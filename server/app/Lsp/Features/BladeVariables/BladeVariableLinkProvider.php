<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;

class BladeVariableLinkProvider implements LinkProvider
{
    protected BladeAstAnalyzer $bladeAnalyzer;
    protected BladeScopeResolver $scopeResolver;

    public function __construct(
        protected Project $project,
    ) {
        $this->bladeAnalyzer = new BladeAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
    }

    /**
     * Get document links for variables in the Blade document.
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
        $lines = explode("\n", $document->content);

        foreach ($lines as $lineIndex => $line) {
            if (!preg_match_all('/\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $variables = $this->collectVariablesForView($document, $viewKey, ['line' => $lineIndex, 'character' => 0]);

            foreach ($matches[1] as $idx => $match) {
                $varName = $match[0];
                if (!isset($variables[$varName])) {
                    continue;
                }

                $var = $variables[$varName];
                $source = $var['source'] ?? null;
                $targetLine = (int) ($var['line'] ?? 1);

                if (!$source || !is_string($source)) {
                    $type = $var['type'] ?? null;
                    if ($type && is_string($type)) {
                        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');
                        $classFile = $this->resolveClassFilePath($cleanType);
                        if ($classFile && file_exists($classFile)) {
                            $source = $classFile;
                            $targetLine = 1;
                        }
                    }
                }

                if (!$source || !is_string($source)) {
                    continue;
                }

                $dollarOffset = $matches[0][$idx][1];
                $length = strlen($matches[0][$idx][0]);

                $range = [
                    'start' => [
                        'line' => $lineIndex,
                        'character' => $dollarOffset,
                    ],
                    'end' => [
                        'line' => $lineIndex,
                        'character' => $dollarOffset + $length,
                    ],
                ];

                $basePath = rtrim($this->project->path(), '/\\');
                $absPath = str_starts_with($source, '/') ? $source : "{$basePath}/{$source}";

                if (file_exists($absPath)) {
                    $targetUri = FileUri::fromPath($absPath);
                    $target = "{$targetUri}#L{$targetLine}";

                    $links[] = [
                        'range' => $range,
                        'target' => $target,
                        'tooltip' => "Go to definition: {$source}:{$targetLine}",
                    ];
                }
            }
        }

        return $links;
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
            $package = $matches[1];
            $subPath = str_replace('/', '.', $matches[2]);
            return "{$package}::{$subPath}";
        }

        if (preg_match('/Modules\/([^\/]+)\/resources\/views\/(.+)\.blade\.php$/i', $path, $matches)) {
            $module = strtolower($matches[1]);
            $subPath = str_replace('/', '.', $matches[2]);
            return "{$module}::{$subPath}";
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
}
