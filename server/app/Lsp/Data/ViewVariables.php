<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\PhpAstViewAnalyzer;
use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use App\Lsp\Semantics\ViewScope;
use Symfony\Component\Finder\Finder;
use Throwable;

class ViewVariables implements DataProvider
{
    protected PhpAstViewAnalyzer $phpAnalyzer;
    protected BladeAstAnalyzer $bladeAnalyzer;

    /**
     * Incremental AST cache for PHP files.
     *
     * @var array<string, array{mtime: int, views: array<string, array{variables: array, sources: array}>}>
     */
    protected array $phpFileCache = [];

    /**
     * Incremental AST cache for Blade component @props.
     *
     * @var array<string, array{mtime: int, props: array, viewKey: ?string}>
     */
    protected array $bladePropsCache = [];

    public function __construct(protected Project $project)
    {
        $this->phpAnalyzer = new PhpAstViewAnalyzer();
        $this->bladeAnalyzer = new BladeAstAnalyzer();
    }

    /**
     * Get the view variables template to run in project context.
     */
    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/view-variables.php') ?: '';
    }

    /**
     * Parse the raw view variables data.
     *
     * @param  array<string, mixed>  $data
     */
    public function parse(array $data): array
    {
        /** @var array<string, ViewScope> $viewScopes */
        $viewScopes = [];
        $basePath = $this->project->path();

        // 1. Incremental AST Analysis for Controllers, Routes, Mailables, Components, Livewire
        $this->scanPhpFiles($basePath, $viewScopes);

        // 2. Incremental Scan for Anonymous Component @props in resources/views
        $this->scanAnonymousComponents($basePath, $viewScopes);

        // 3. Merge default globals + runtime shared
        $globals = $this->defaultGlobals();
        if (!empty($data['shared']) && is_array($data['shared'])) {
            $globals = array_merge($globals, $data['shared']);
        }

        return [
            'views' => array_map(fn (ViewScope $scope): array => $scope->toLegacyArray(), $viewScopes),
            'viewScopes' => array_map(fn (ViewScope $scope): array => $scope->toArray(), $viewScopes),
            'globals' => $globals,
        ];
    }

    /**
     * Get data.
     */
    public function get(): array
    {
        $data = [];
        try {
            $raw = $this->project->scripts->json($this->template());
            if (is_array($raw)) {
                $data = $raw;
            }
        } catch (Throwable) {
            // If runtime runner fails, continue with pure AST analysis
        }

        return $this->parse($data);
    }

    /**
     * Get view variables watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            '**/{app/Http/Controllers,routes,app/Providers,app/View,app/Mail,app/Notifications,app/Livewire,app/Http/Livewire,app/Http/Middleware,app/Filament,Modules}/**/*.php',
            '**/bootstrap/{app,providers}.php',
            '**/resources/views/**/*.blade.php',
        ];
    }

    public static function defaultGlobals(): array
    {
        return [
            [
                'name' => '__env',
                'type' => '\\Illuminate\\View\\Factory',
                'detail' => 'Laravel View Factory ($__env) instance',
                'origin' => 'Global',
            ],
            [
                'name' => 'errors',
                'type' => '\\Illuminate\\Support\\ViewErrorBag',
                'detail' => 'Session validation error bag ($errors)',
                'origin' => 'Global',
            ],
            [
                'name' => 'app',
                'type' => '\\Illuminate\\Foundation\\Application',
                'detail' => 'Laravel Application container ($app)',
                'origin' => 'Global',
            ],
            [
                'name' => 'request',
                'type' => '\\Illuminate\\Http\\Request',
                'detail' => 'Current HTTP Request ($request)',
                'origin' => 'Global',
            ],
        ];
    }

    public static function componentGlobals(): array
    {
        return [
            [
                'name' => 'attributes',
                'type' => '\\Illuminate\\View\\ComponentAttributeBag',
                'detail' => 'Component HTML attribute bag',
                'origin' => 'Component',
            ],
            [
                'name' => 'slot',
                'type' => '\\Illuminate\\Support\\HtmlString',
                'detail' => 'Default slot content',
                'origin' => 'Component',
            ],
        ];
    }

    protected function scanPhpFiles(string $basePath, array &$views): void
    {
        $searchDirs = [
            $basePath . '/app/Http/Controllers',
            $basePath . '/routes',
            $basePath . '/app/Providers',
            $basePath . '/app/View',
            $basePath . '/app/Mail',
            $basePath . '/app/Notifications',
            $basePath . '/app/Livewire',
            $basePath . '/app/Http/Livewire',
            $basePath . '/app/Http/Middleware',
            $basePath . '/app/Filament',
            $basePath . '/app/Filament/Resources',
            $basePath . '/app/Filament/Pages',
            $basePath . '/app/Filament/Widgets',
            $basePath . '/app/Filament/Clusters',
        ];

        if (is_dir($basePath . '/Modules')) {
            $searchDirs[] = $basePath . '/Modules';
        }

        $singleFiles = [
            $basePath . '/bootstrap/app.php',
            $basePath . '/bootstrap/providers.php',
        ];

        $seenFiles = [];
        $composerBindings = [];
        $composerClasses = [];

        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            try {
                $files = Finder::create()->files()->name('*.php')->in($dir);
                foreach ($files as $file) {
                    $realPath = $file->getRealPath();
                    $seenFiles[$realPath] = true;
                    $mtime = @filemtime($realPath) ?: 0;

                    // Reuse cached AST if file has not changed
                    if (isset($this->phpFileCache[$realPath]) && $this->phpFileCache[$realPath]['mtime'] === $mtime) {
                        $extractedViews = $this->phpFileCache[$realPath]['views'];
                    } else {
                        $code = @file_get_contents($realPath);
                        if (!$code) {
                            continue;
                        }

                        $relPath = $this->relativePath($realPath);
                        $extractedViews = $this->phpAnalyzer->analyze($code, $relPath, $composerBindings, $composerClasses);
                        $this->phpFileCache[$realPath] = [
                            'mtime' => $mtime,
                            'views' => $extractedViews,
                        ];
                    }

                    foreach ($extractedViews as $viewName => $viewData) {
                        $this->mergeViewData($views, $viewName, $viewData['variables'], $viewData['sources']);
                    }
                }
            } catch (Throwable) {}
        }

        foreach ($singleFiles as $singlePath) {
            if (!file_exists($singlePath)) {
                continue;
            }

            try {
                $realPath = realpath($singlePath) ?: $singlePath;
                $seenFiles[$realPath] = true;
                $mtime = @filemtime($realPath) ?: 0;

                if (isset($this->phpFileCache[$realPath]) && $this->phpFileCache[$realPath]['mtime'] === $mtime) {
                    $extractedViews = $this->phpFileCache[$realPath]['views'];
                } else {
                    $code = @file_get_contents($realPath);
                    if (!$code) {
                        continue;
                    }

                    $relPath = $this->relativePath($realPath);
                    $extractedViews = $this->phpAnalyzer->analyze($code, $relPath, $composerBindings, $composerClasses);
                    $this->phpFileCache[$realPath] = [
                        'mtime' => $mtime,
                        'views' => $extractedViews,
                    ];
                }

                foreach ($extractedViews as $viewName => $viewData) {
                    $this->mergeViewData($views, $viewName, $viewData['variables'], $viewData['sources']);
                }
            } catch (Throwable) {}
        }

        // Merge any cross-file composer class bindings
        foreach ($composerBindings as $class => $targetViews) {
            if (isset($composerClasses[$class])) {
                foreach ($targetViews as $targetView) {
                    $this->mergeViewData($views, $targetView, $composerClasses[$class]);
                }
            }
        }

        // Clean up deleted files from cache
        foreach (array_keys($this->phpFileCache) as $cachedPath) {
            if (!isset($seenFiles[$cachedPath]) && !file_exists($cachedPath)) {
                unset($this->phpFileCache[$cachedPath]);
            }
        }
    }

    protected function scanAnonymousComponents(string $basePath, array &$views): void
    {
        $componentsDir = $basePath . '/resources/views/components';
        $viewsDir = $basePath . '/resources/views';

        // Scan components dir if it exists, otherwise scan views dir
        $scanDir = is_dir($componentsDir) ? $componentsDir : (is_dir($viewsDir) ? $viewsDir : null);
        if (!$scanDir) {
            return;
        }

        $seenFiles = [];

        try {
            $files = Finder::create()->files()->name('*.blade.php')->in($scanDir);
            foreach ($files as $file) {
                $realPath = $file->getRealPath();
                $seenFiles[$realPath] = true;
                $mtime = @filemtime($realPath) ?: 0;

                // Check cache first
                if (isset($this->bladePropsCache[$realPath]) && $this->bladePropsCache[$realPath]['mtime'] === $mtime) {
                    $props = $this->bladePropsCache[$realPath]['props'];
                    $viewKey = $this->bladePropsCache[$realPath]['viewKey'];
                } else {
                    $code = @file_get_contents($realPath);
                    if (!$code || !str_contains($code, '@props')) {
                        $this->bladePropsCache[$realPath] = [
                            'mtime' => $mtime,
                            'props' => [],
                            'viewKey' => null,
                        ];
                        continue;
                    }

                    $relPath = $this->relativePath($realPath);
                    $props = $this->bladeAnalyzer->extractTemplateVariables($code);
                    $viewKey = null;

                    if (!empty($props)) {
                        $normPath = str_replace('\\', '/', $relPath);
                        if (preg_match('/resources\/views\/(.+)\.blade\.php$/', $normPath, $m)) {
                            $viewKey = str_replace('/', '.', $m[1]);
                        }
                    }

                    $this->bladePropsCache[$realPath] = [
                        'mtime' => $mtime,
                        'props' => $props,
                        'viewKey' => $viewKey,
                    ];
                }

                if (!empty($props) && $viewKey !== null) {
                    $relPath = $this->relativePath($realPath);
                    $this->mergeViewData($views, $viewKey, $props, [$relPath]);
                }
            }
        } catch (Throwable) {}

        // Clean up deleted files from cache
        foreach (array_keys($this->bladePropsCache) as $cachedPath) {
            if (!isset($seenFiles[$cachedPath]) && !file_exists($cachedPath)) {
                unset($this->bladePropsCache[$cachedPath]);
            }
        }
    }

    /**
     * @param  array<string, ViewScope>  $views
     * @param  array<string, array<string, mixed>>  $variables
     * @param  list<string>  $sources
     */
    protected function mergeViewData(array &$views, string $viewName, array $variables, array $sources = []): void
    {
        if (!isset($views[$viewName])) {
            $views[$viewName] = new ViewScope($viewName);
        }

        $views[$viewName]->addLegacyVariables($variables, $sources);
    }

    protected function relativePath(string $path): string
    {
        $base = rtrim($this->project->path(), '/\\');
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $base);

        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
    }
}
