<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Project;
use App\Lsp\Semantics\MacroSymbol;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class MacroRegistry
{
    /**
     * @var array<string, array<string, MacroSymbol>>
     */
    protected array $macrosByClass = [];

    protected bool $indexed = false;
    protected PhpMacroAstAnalyzer $astAnalyzer;

    /**
     * Known bidirectional class bridges for Macroable targets.
     *
     * @var array<string, array<int, string>>
     */
    protected array $bridges = [
        'Illuminate\Support\Facades\Http' => [
            'Illuminate\Http\Client\PendingRequest',
            'Illuminate\Http\Client\Factory',
        ],
        'Illuminate\Http\Client\PendingRequest' => [
            'Illuminate\Support\Facades\Http',
            'Illuminate\Http\Client\Factory',
        ],
        'Illuminate\Http\Client\Factory' => [
            'Illuminate\Support\Facades\Http',
            'Illuminate\Http\Client\PendingRequest',
        ],
        'Illuminate\Support\Str' => [
            'Illuminate\Support\Stringable',
        ],
        'Illuminate\Support\Stringable' => [
            'Illuminate\Support\Str',
        ],
        'Illuminate\Support\Collection' => [
            'Illuminate\Support\LazyCollection',
            'Illuminate\Database\Eloquent\Collection',
        ],
        'Illuminate\Support\LazyCollection' => [
            'Illuminate\Support\Collection',
            'Illuminate\Database\Eloquent\Collection',
        ],
        'Illuminate\Database\Eloquent\Collection' => [
            'Illuminate\Support\Collection',
            'Illuminate\Support\LazyCollection',
        ],
        'Illuminate\Database\Eloquent\Builder' => [
            'Illuminate\Database\Query\Builder',
        ],
        'Illuminate\Database\Query\Builder' => [
            'Illuminate\Database\Eloquent\Builder',
        ],
        'Illuminate\Support\Facades\Response' => [
            'Illuminate\Http\Response',
            'Illuminate\Http\JsonResponse',
            'Illuminate\Contracts\Routing\ResponseFactory',
        ],
        'Illuminate\Contracts\Routing\ResponseFactory' => [
            'Illuminate\Support\Facades\Response',
            'Illuminate\Http\Response',
            'Illuminate\Http\JsonResponse',
        ],
        'Illuminate\Support\Facades\Route' => [
            'Illuminate\Routing\Router',
            'Illuminate\Routing\Route',
        ],
        'Illuminate\Routing\Router' => [
            'Illuminate\Support\Facades\Route',
            'Illuminate\Routing\Route',
        ],
        'Illuminate\Routing\Route' => [
            'Illuminate\Support\Facades\Route',
            'Illuminate\Routing\Router',
        ],
    ];

    public function __construct(
        protected ?Project $project = null,
        ?PhpMacroAstAnalyzer $astAnalyzer = null,
    ) {
        $this->astAnalyzer = $astAnalyzer ?? new PhpMacroAstAnalyzer();
    }

    public function registerMacro(MacroSymbol $macro): void
    {
        $target = ltrim($macro->targetClass, '\\');
        $this->macrosByClass[$target][$macro->name] = $macro;

        // Also register under short class basename
        $basename = class_basename($target);
        if ($basename !== $target) {
            $this->macrosByClass[$basename][$macro->name] = $macro;
        }

        // Register on bridged classes and their basenames
        foreach ($this->getBridgesFor($target) as $bridgedClass) {
            $this->macrosByClass[$bridgedClass][$macro->name] = $macro;
            $bridgedBasename = class_basename($bridgedClass);
            if ($bridgedBasename !== $bridgedClass) {
                $this->macrosByClass[$bridgedBasename][$macro->name] = $macro;
            }
        }
    }

    /**
     * @return array<string, MacroSymbol>
     */
    public function getMacrosForClass(string $className): array
    {
        $this->ensureIndexed();
        $clean = ltrim($className, '\\');

        $result = $this->macrosByClass[$clean] ?? [];

        // Check short name
        $basename = class_basename($clean);
        if (!empty($this->macrosByClass[$basename])) {
            $result = array_merge($this->macrosByClass[$basename], $result);
        }

        // Check bridges
        foreach ($this->getBridgesFor($clean) as $bridged) {
            if (!empty($this->macrosByClass[$bridged])) {
                $result = array_merge($this->macrosByClass[$bridged], $result);
            }
        }

        return $result;
    }

    public function getMacro(string $className, string $methodName): ?MacroSymbol
    {
        $macros = $this->getMacrosForClass($className);

        return $macros[$methodName] ?? null;
    }

    /**
     * @return array<int, string>
     */
    protected function getBridgesFor(string $className): array
    {
        $clean = ltrim($className, '\\');

        return $this->bridges[$clean] ?? [];
    }

    public function ensureIndexed(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->indexed = true;
        $this->discoverWorkspaceMacros();
    }

    protected function discoverWorkspaceMacros(): void
    {
        if ($this->project === null) {
            return;
        }

        $basePath = rtrim($this->project->path(), '/\\');
        $searchDirs = [
            $basePath . '/app/Providers',
            $basePath . '/app/Macros',
            $basePath . '/app/Mixins',
            $basePath . '/app',
        ];

        $scannedFiles = [];

        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                        continue;
                    }

                    $pathname = $file->getPathname();
                    if (isset($scannedFiles[$pathname])) {
                        continue;
                    }
                    $scannedFiles[$pathname] = true;

                    $code = (string) file_get_contents($pathname);
                    if (!str_contains($code, 'macro') && !str_contains($code, 'mixin')) {
                        continue;
                    }

                    $symbols = $this->astAnalyzer->extractFromCode($code, $pathname);
                    foreach ($symbols as $sym) {
                        $this->registerMacro($sym);
                    }
                }
            } catch (Throwable) {}
        }
    }
}
