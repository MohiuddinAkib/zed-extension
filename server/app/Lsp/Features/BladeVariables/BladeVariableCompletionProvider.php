<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Semantics\TypeDisplay;
use App\Lsp\Support\FileUri;

class BladeVariableCompletionProvider implements CompletionProvider
{
    protected BladeAstAnalyzer $bladeAnalyzer;
    protected BladeScopeResolver $scopeResolver;

    /**
     * Create a new Blade variable completion provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {
        $this->bladeAnalyzer = new BladeAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
    }

    /**
     * Provide variable completions for the given document and position.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $range = $this->replacementRange($document, $position);

        if ($range === null) {
            return [];
        }

        $viewKey = $this->resolveViewKey($document->uri);
        $allVariables = $this->collectVariablesForView($document, $viewKey, $position);

        return collect($allVariables)
            ->map(fn (array $var): array => $this->completionItem($var, $range))
            ->values()
            ->all();
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

    /**
     * Create a variable completion item.
     *
     * @param  array<string, mixed>  $var
     * @param  array<string, array<string, int>>  $range
     * @return array<string, mixed>
     */
    protected function completionItem(array $var, array $range): array
    {
        $varName = $var['name'];
        $name = '$' . $varName;
        $type = $var['type'] ?? 'mixed';
        $origin = $var['origin'] ?? 'Inferred';
        $detail = $var['detail'] ?? $type;
        $source = $var['source'] ?? null;
        $line = $var['line'] ?? null;

        // Build symbol title
        $title = $name;
        if ($source && !is_array($source)) {
            if (preg_match('/app\/(?:Mail|View\/Components|Livewire|Http\/Controllers)\/([a-zA-Z0-9_\/]+)\.php/', $source, $m)) {
                $classFqcn = 'App\\' . str_replace('/', '\\', $m[1]);
                $title = "{$classFqcn}::{$name}";
            }
        }

        $nativePhpType = TypeDisplay::nativePhpType($type);

        // Build PHP code block for syntax highlighting
        $phpCode = "<?php\n";
        if (str_starts_with($origin, 'Property') || str_starts_with($origin, 'Constructor Property')) {
            $phpCode .= "public {$nativePhpType} {$name};";
        } elseif ($origin === '@props') {
            $phpCode .= "/** @props */\n{$nativePhpType} {$name};";
        } else {
            $phpCode .= "{$nativePhpType} {$name};";
        }

        $docMarkdown = "**{$title}**\n\n"
            . "```php\n{$phpCode}\n```\n\n"
            . "`@var {$type} {$name}`\n\n"
            . "*Origin:* `{$origin}`  \n";

        if (!empty($source)) {
            $basePath = rtrim($this->project->path(), '/\\');
            $sourceStr = is_array($source) ? implode(', ', $source) : (string) $source;
            if (!empty($line) && !is_array($source)) {
                $absPath = str_starts_with($source, '/') ? $source : "{$basePath}/{$source}";
                $fileUri = FileUri::fromPath($absPath);
                $docMarkdown .= "*Source:* [{$source}:{$line}]({$fileUri}#L{$line})\n";
            } else {
                $docMarkdown .= "*Source:* `{$sourceStr}`\n";
            }
        }

        return [
            'label' => $name,
            'kind' => 6, // Variable
            'detail' => $detail,
            'documentation' => [
                'kind' => 'markdown',
                'value' => $docMarkdown,
            ],
            'textEdit' => [
                'range' => $range,
                'newText' => $name,
            ],
            'sortText' => (str_starts_with($origin, '@') || str_starts_with($origin, '<') ? '0_' : ($origin === 'Global' ? '2_' : '1_')) . $name,
        ];
    }

    /**
     * Get the range that should be replaced by the completion.
     *
     * @param  array<string, mixed>  $position
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}|null
     */
    protected function replacementRange(Document $document, array $position): ?array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $line = explode("\n", $document->content)[$lineNumber] ?? '';
        $text = substr($line, 0, $character);

        preg_match('/\$[a-zA-Z0-9_]*$/', $text, $matches);

        if (empty($matches)) {
            return null;
        }

        $token = $matches[0];

        return [
            'start' => [
                'line' => $lineNumber,
                'character' => $character - strlen($token),
            ],
            'end' => [
                'line' => $lineNumber,
                'character' => $character,
            ],
        ];
    }
}
