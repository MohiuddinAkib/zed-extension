<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Semantics\TypeDisplay;
use App\Lsp\Support\FileUri;

class BladeVariableHoverProvider implements HoverProvider
{
    protected BladeAstAnalyzer $bladeAnalyzer;
    protected BladeScopeResolver $scopeResolver;

    /**
     * Create a new Blade variable hover provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {
        $this->bladeAnalyzer = new BladeAstAnalyzer($this->project);
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
    }

    /**
     * Provide variable hover information for the given document and position.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function get(Document $document, array $position): ?array
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

        $varName = $varInfo['name'];
        $range = [
            'start' => ['line' => $lineNumber, 'character' => $varInfo['start']],
            'end' => ['line' => $lineNumber, 'character' => $varInfo['end']],
        ];

        $viewKey = $this->resolveViewKey($document->uri);
        $variables = $this->collectVariablesForView($document, $viewKey, $position);

        if (!isset($variables[$varName])) {
            return null;
        }

        $var = $variables[$varName];
        $type = $var['type'] ?? 'mixed';
        $origin = $var['origin'] ?? 'Inferred';
        $detail = $var['detail'] ?? '';
        $source = $var['source'] ?? null;
        $line = $var['line'] ?? null;

        // Build symbol title
        $title = "\${$varName}";
        if ($source && !is_array($source)) {
            if (preg_match('/app\/(?:Mail|View\/Components|Livewire|Http\/Controllers)\/([a-zA-Z0-9_\/]+)\.php/', $source, $m)) {
                $classFqcn = 'App\\' . str_replace('/', '\\', $m[1]);
                $title = "{$classFqcn}::\${$varName}";
            }
        }

        $nativePhpType = TypeDisplay::nativePhpType($type);

        // Build PHP code block for syntax highlighting
        $phpCode = "<?php\n";
        if (str_starts_with($origin, 'Property') || str_starts_with($origin, 'Constructor Property')) {
            $phpCode .= "public {$nativePhpType} \${$varName};";
        } elseif ($origin === '@props') {
            $phpCode .= "/** @props */\n{$nativePhpType} \${$varName};";
        } else {
            $phpCode .= "{$nativePhpType} \${$varName};";
        }

        $markdown = "**{$title}**\n\n"
            . "```php\n{$phpCode}\n```\n\n"
            . "`@var {$type} \${$varName}`\n\n"
            . "*Origin:* `{$origin}`  \n";

        if ($detail !== '') {
            $markdown .= "{$detail}  \n";
        }

        if (!empty($source)) {
            $basePath = rtrim($this->project->path(), '/\\');
            $sourceStr = is_array($source) ? implode(', ', $source) : (string) $source;
            if (!empty($line) && !is_array($source)) {
                $absPath = str_starts_with($source, '/') ? $source : "{$basePath}/{$source}";
                $fileUri = FileUri::fromPath($absPath);
                $markdown .= "*Source:* [{$source}:{$line}]({$fileUri}#L{$line})\n";
            } else {
                $markdown .= "*Source:* `{$sourceStr}`\n";
            }
        }

        return [
            'contents' => [
                'kind' => 'markdown',
                'value' => $markdown,
            ],
            'range' => $range,
        ];
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
            $endOffset = $dollarOffset + strlen($matches[0][$idx][0]);

            if ($character >= $dollarOffset && $character <= $endOffset) {
                return [
                    'name' => $varName,
                    'start' => $dollarOffset,
                    'end' => $endOffset,
                ];
            }
        }

        return null;
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

        // 1. Check exact key match from ProjectIndex views list
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

        // 2. Overridden vendor views: resources/views/vendor/{package}/{subpath}.blade.php
        if (preg_match('/resources\/views\/vendor\/([^\/]+)\/(.+)\.blade\.php$/', $path, $matches)) {
            $package = $matches[1];
            $subPath = str_replace('/', '.', $matches[2]);
            return "{$package}::{$subPath}";
        }

        // 3. Module views: Modules/{Module}/resources/views/{subpath}.blade.php
        if (preg_match('/Modules\/([^\/]+)\/resources\/views\/(.+)\.blade\.php$/i', $path, $matches)) {
            $module = strtolower($matches[1]);
            $subPath = str_replace('/', '.', $matches[2]);
            return "{$module}::{$subPath}";
        }

        // 4. Standard views: resources/views/{subpath}.blade.php
        if (preg_match('/resources\/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        if (preg_match('/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return basename($path, '.blade.php');
    }
}
