<?php

declare(strict_types=1);

namespace App\Lsp\Features\ClassIndex;

use App\Lsp\Features\Facades\FacadeMap;
use App\Lsp\Project;
use SplFileInfo;
use Throwable;

class ClassRegistry
{
    /**
     * Cache of discovered classes per project path.
     *
     * @var array<string, array<string, array{class: string, name: string, kind: string, detail: string, path?: string}>>
     */
    protected static array $cache = [];

    /**
     * Cache timestamp per project path.
     *
     * @var array<string, int>
     */
    protected static array $cacheTime = [];

    /**
     * Builtin common Laravel Framework classes.
     *
     * @var array<string, array{name: string, kind: string, detail: string}>
     */
    protected const BUILTIN_CLASSES = [
        'Illuminate\Support\Str' => ['name' => 'Str', 'kind' => 'Class / Helper', 'detail' => 'Laravel String Manipulation'],
        'Illuminate\Support\Arr' => ['name' => 'Arr', 'kind' => 'Class / Helper', 'detail' => 'Laravel Array Manipulation'],
        'Illuminate\Support\Collection' => ['name' => 'Collection', 'kind' => 'Class', 'detail' => 'Laravel Collection'],
        'Illuminate\Support\LazyCollection' => ['name' => 'LazyCollection', 'kind' => 'Class', 'detail' => 'Laravel Lazy Collection'],
        'Illuminate\Support\Carbon' => ['name' => 'Carbon', 'kind' => 'Class', 'detail' => 'Carbon DateTime wrapper'],
        'Illuminate\Support\Number' => ['name' => 'Number', 'kind' => 'Class / Helper', 'detail' => 'Laravel Number Formatting'],
        'Illuminate\Support\Js' => ['name' => 'Js', 'kind' => 'Class / Helper', 'detail' => 'JavaScript JSON encoder'],
        'Illuminate\Support\Sleep' => ['name' => 'Sleep', 'kind' => 'Class / Helper', 'detail' => 'Laravel Sleep Utilities'],
        'Illuminate\Support\Benchmark' => ['name' => 'Benchmark', 'kind' => 'Class / Helper', 'detail' => 'Laravel Benchmark utility'],
        'Illuminate\Support\Uri' => ['name' => 'Uri', 'kind' => 'Class', 'detail' => 'Laravel URI Parser'],
        'Illuminate\Support\Process\ProcessResult' => ['name' => 'ProcessResult', 'kind' => 'Class', 'detail' => 'Process Result'],
        'Illuminate\Database\Eloquent\Model' => ['name' => 'Model', 'kind' => 'Class', 'detail' => 'Eloquent Base Model'],
        'Illuminate\Database\Eloquent\Builder' => ['name' => 'Builder', 'kind' => 'Class', 'detail' => 'Eloquent Query Builder'],
        'Illuminate\Database\Query\Builder' => ['name' => 'Builder', 'kind' => 'Class', 'detail' => 'Database Query Builder'],
        'Illuminate\Http\Request' => ['name' => 'Request', 'kind' => 'Class', 'detail' => 'HTTP Request'],
        'Illuminate\Http\Response' => ['name' => 'Response', 'kind' => 'Class', 'detail' => 'HTTP Response'],
        'Illuminate\Http\JsonResponse' => ['name' => 'JsonResponse', 'kind' => 'Class', 'detail' => 'HTTP JSON Response'],
        'Illuminate\Http\RedirectResponse' => ['name' => 'RedirectResponse', 'kind' => 'Class', 'detail' => 'HTTP Redirect Response'],
        'Illuminate\Pagination\LengthAwarePaginator' => ['name' => 'LengthAwarePaginator', 'kind' => 'Class', 'detail' => 'Length Aware Paginator'],
        'Illuminate\Pagination\Paginator' => ['name' => 'Paginator', 'kind' => 'Class', 'detail' => 'Simple Paginator'],
        'Illuminate\Validation\ValidationException' => ['name' => 'ValidationException', 'kind' => 'Class / Exception', 'detail' => 'Validation Exception'],
        'Illuminate\Auth\AuthenticationException' => ['name' => 'AuthenticationException', 'kind' => 'Class / Exception', 'detail' => 'Authentication Exception'],
        'Illuminate\Auth\Access\AuthorizationException' => ['name' => 'AuthorizationException', 'kind' => 'Class / Exception', 'detail' => 'Authorization Exception'],
        'Illuminate\Database\Eloquent\ModelNotFoundException' => ['name' => 'ModelNotFoundException', 'kind' => 'Class / Exception', 'detail' => 'Model Not Found Exception'],
        'Illuminate\Contracts\Auth\Guard' => ['name' => 'Guard', 'kind' => 'Interface', 'detail' => 'Authentication Guard Contract'],
        'Illuminate\Contracts\Auth\StatefulGuard' => ['name' => 'StatefulGuard', 'kind' => 'Interface', 'detail' => 'Stateful Auth Guard Contract'],
        'Illuminate\Contracts\Auth\Access\Gate' => ['name' => 'Gate', 'kind' => 'Interface', 'detail' => 'Access Gate Contract'],
        'Illuminate\Contracts\Bus\Dispatcher' => ['name' => 'Dispatcher', 'kind' => 'Interface', 'detail' => 'Bus Dispatcher Contract'],
        'Illuminate\Contracts\Cache\Repository' => ['name' => 'Repository', 'kind' => 'Interface', 'detail' => 'Cache Repository Contract'],
        'Illuminate\Contracts\Config\Repository' => ['name' => 'Repository', 'kind' => 'Interface', 'detail' => 'Config Repository Contract'],
        'Illuminate\Contracts\Container\Container' => ['name' => 'Container', 'kind' => 'Interface', 'detail' => 'DI Container Contract'],
        'Illuminate\Contracts\Database\Eloquent\Builder' => ['name' => 'Builder', 'kind' => 'Interface', 'detail' => 'Eloquent Builder Contract'],
        'Illuminate\Contracts\Events\Dispatcher' => ['name' => 'Dispatcher', 'kind' => 'Interface', 'detail' => 'Events Dispatcher Contract'],
        'Illuminate\Contracts\Filesystem\Filesystem' => ['name' => 'Filesystem', 'kind' => 'Interface', 'detail' => 'Filesystem Contract'],
        'Illuminate\Contracts\Foundation\Application' => ['name' => 'Application', 'kind' => 'Interface', 'detail' => 'Application Contract'],
        'Illuminate\Contracts\Mail\Mailer' => ['name' => 'Mailer', 'kind' => 'Interface', 'detail' => 'Mailer Contract'],
        'Illuminate\Contracts\Pagination\LengthAwarePaginator' => ['name' => 'LengthAwarePaginator', 'kind' => 'Interface', 'detail' => 'Paginator Contract'],
        'Illuminate\Contracts\Queue\Queue' => ['name' => 'Queue', 'kind' => 'Interface', 'detail' => 'Queue Contract'],
        'Illuminate\Contracts\Routing\ResponseFactory' => ['name' => 'ResponseFactory', 'kind' => 'Interface', 'detail' => 'Response Factory Contract'],
        'Illuminate\Contracts\Session\Session' => ['name' => 'Session', 'kind' => 'Interface', 'detail' => 'Session Contract'],
        'Illuminate\Contracts\Support\Arrayable' => ['name' => 'Arrayable', 'kind' => 'Interface', 'detail' => 'Arrayable Contract'],
        'Illuminate\Contracts\Support\Jsonable' => ['name' => 'Jsonable', 'kind' => 'Interface', 'detail' => 'Jsonable Contract'],
        'Illuminate\Contracts\Support\Renderable' => ['name' => 'Renderable', 'kind' => 'Interface', 'detail' => 'Renderable Contract'],
        'Illuminate\Contracts\Support\Responsable' => ['name' => 'Responsable', 'kind' => 'Interface', 'detail' => 'Responsable Contract'],
        'Illuminate\Contracts\Translation\Translator' => ['name' => 'Translator', 'kind' => 'Interface', 'detail' => 'Translator Contract'],
        'Illuminate\Contracts\Validation\Validator' => ['name' => 'Validator', 'kind' => 'Interface', 'detail' => 'Validator Contract'],
        'Illuminate\Contracts\View\View' => ['name' => 'View', 'kind' => 'Interface', 'detail' => 'View Contract'],
        'Illuminate\Contracts\View\Factory' => ['name' => 'Factory', 'kind' => 'Interface', 'detail' => 'View Factory Contract'],
    ];

    public function __construct(protected Project $project) {}

    /**
     * Search classes matching a query string.
     *
     * @return array<int, array{class: string, name: string, kind: string, detail: string, path?: string}>
     */
    public function search(string $query = '', int $limit = 50): array
    {
        $all = $this->all();
        $cleanQuery = ltrim($query, '\\');
        $lowQuery = strtolower($cleanQuery);

        if ($cleanQuery === '') {
            return array_slice(array_values($all), 0, $limit);
        }

        $exact = [];
        $prefix = [];
        $basePrefix = [];
        $contains = [];

        foreach ($all as $class => $info) {
            $lowClass = strtolower($class);
            $lowBase = strtolower($info['name']);

            if ($lowClass === $lowQuery || $lowBase === $lowQuery) {
                $exact[] = $info;
            } elseif (str_starts_with($lowClass, $lowQuery)) {
                $prefix[] = $info;
            } elseif (str_starts_with($lowBase, $lowQuery)) {
                $basePrefix[] = $info;
            } elseif (str_contains($lowClass, $lowQuery)) {
                $contains[] = $info;
            }
        }

        $results = array_merge($exact, $prefix, $basePrefix, $contains);

        return array_slice($results, 0, $limit);
    }

    /**
     * Get all available classes for the project.
     *
     * @return array<string, array{class: string, name: string, kind: string, detail: string, path?: string}>
     */
    public function all(): array
    {
        $basePath = $this->project->uri->path();

        if (isset(self::$cache[$basePath]) && (time() - (self::$cacheTime[$basePath] ?? 0) < 15)) {
            return self::$cache[$basePath];
        }

        $classes = [];

        // 1. Built-in common Laravel Framework classes
        foreach (self::BUILTIN_CLASSES as $class => $meta) {
            $classes[$class] = [
                'class' => $class,
                'name' => $meta['name'],
                'kind' => $meta['kind'],
                'detail' => $meta['detail'],
            ];
        }

        // 2. Global Facades
        foreach (FacadeMap::all() as $alias => $fqcn) {
            $cleanFqcn = ltrim($fqcn, '\\');
            $classes[$cleanFqcn] = [
                'class' => $cleanFqcn,
                'name' => class_basename($cleanFqcn),
                'kind' => 'Facade',
                'detail' => FacadeMap::description($alias) ?: "Laravel {$alias} Facade",
            ];
        }

        // 3. Project Eloquent Models from Index
        try {
            $models = $this->project->index->models();
            foreach ($models as $key => $m) {
                $class = is_array($m) ? ($m['class'] ?? ($m['name'] ?? (is_string($key) ? $key : ''))) : (string) $m;
                if ($class !== '') {
                    $clean = ltrim($class, '\\');
                    $classes[$clean] = [
                        'class' => $clean,
                        'name' => class_basename($clean),
                        'kind' => 'Eloquent Model',
                        'detail' => 'Eloquent Model',
                        'path' => is_array($m) ? ($m['path'] ?? null) : null,
                    ];
                }
            }
        } catch (Throwable) {}

        // 4. Scan application source directories (app/, database/, etc.)
        $this->scanApplicationDirectories($basePath, $classes);

        // 5. Vendor Composer Autoload Classmap (if available)
        $this->loadComposerClassmap($basePath, $classes);

        // 6. Vendor Composer PSR-4 namespaces
        $this->loadComposerPsr4($basePath, $classes);

        self::$cache[$basePath] = $classes;
        self::$cacheTime[$basePath] = time();

        return $classes;
    }

    /**
     * Scan app/ directory and database/ directory for classes, traits, enums, interfaces.
     *
     * @param array<string, array{class: string, name: string, kind: string, detail: string, path?: string}> $classes
     */
    protected function scanApplicationDirectories(string $basePath, array &$classes): void
    {
        $appPath = $basePath . '/app';
        if (is_dir($appPath)) {
            $this->scanPhpFiles($appPath, 'App', $basePath, $classes);
        }

        $dbPath = $basePath . '/database';
        if (is_dir($dbPath)) {
            $this->scanPhpFiles($dbPath . '/factories', 'Database\\Factories', $basePath, $classes);
            $this->scanPhpFiles($dbPath . '/seeders', 'Database\\Seeders', $basePath, $classes);
        }
    }

    /**
     * Scan a directory recursively for PHP files and extract namespaces/classes.
     *
     * @param array<string, array{class: string, name: string, kind: string, detail: string, path?: string}> $classes
     */
    protected function scanPhpFiles(string $dir, string $baseNamespace, string $basePath, array &$classes): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $filePath = $file->getPathname();
                $relToDir = substr($filePath, strlen($dir) + 1);
                $relToDir = str_replace(['/', '\\'], '\\', substr($relToDir, 0, -4)); // remove .php

                $fqcn = rtrim($baseNamespace, '\\') . '\\' . $relToDir;
                $basename = class_basename($fqcn);

                // Quick inspect file contents to determine kind (class, interface, enum, trait)
                $kind = 'Class';
                $relPath = substr($filePath, strlen($basePath) + 1);

                $head = file_get_contents($filePath, false, null, 0, 1024) ?: '';
                if (preg_match('/\b(enum|interface|trait|class)\s+([a-zA-Z0-9_]+)/i', $head, $m)) {
                    $type = strtolower($m[1]);
                    $kind = match ($type) {
                        'interface' => 'Interface',
                        'enum' => 'Enum',
                        'trait' => 'Trait',
                        default => 'Class',
                    };
                }

                if (str_contains($fqcn, 'Factory')) {
                    $kind = ($kind === 'Class') ? 'Factory' : $kind;
                } elseif (str_contains($fqcn, 'Service')) {
                    $kind = ($kind === 'Class') ? 'Service' : $kind;
                } elseif (str_contains($fqcn, 'Controller')) {
                    $kind = ($kind === 'Class') ? 'Controller' : $kind;
                } elseif (str_contains($fqcn, 'Provider')) {
                    $kind = ($kind === 'Class') ? 'Service Provider' : $kind;
                } elseif (str_contains($fqcn, 'Mail\\') || str_contains($fqcn, 'Mailable')) {
                    $kind = ($kind === 'Class') ? 'Mailable' : $kind;
                } elseif (str_contains($fqcn, 'Job')) {
                    $kind = ($kind === 'Class') ? 'Job' : $kind;
                } elseif (str_contains($fqcn, 'Event')) {
                    $kind = ($kind === 'Class') ? 'Event' : $kind;
                } elseif (str_contains($fqcn, 'Listener')) {
                    $kind = ($kind === 'Class') ? 'Listener' : $kind;
                } elseif (str_contains($fqcn, 'Rule')) {
                    $kind = ($kind === 'Class') ? 'Validation Rule' : $kind;
                }

                $classes[$fqcn] = [
                    'class' => $fqcn,
                    'name' => $basename,
                    'kind' => $kind,
                    'detail' => $kind . ' (' . $fqcn . ')',
                    'path' => $relPath,
                ];
            }
        } catch (Throwable) {}
    }

    /**
     * Load classes from vendor/composer/autoload_classmap.php if it exists.
     *
     * @param array<string, array{class: string, name: string, kind: string, detail: string, path?: string}> $classes
     */
    protected function loadComposerClassmap(string $basePath, array &$classes): void
    {
        $classmapFile = $basePath . '/vendor/composer/autoload_classmap.php';
        if (!file_exists($classmapFile)) {
            return;
        }

        try {
            $map = include $classmapFile;
            if (is_array($map)) {
                foreach ($map as $className => $file) {
                    $clean = ltrim((string) $className, '\\');
                    if (isset($classes[$clean])) {
                        continue;
                    }

                    $base = class_basename($clean);
                    $kind = 'Class';
                    if (str_contains($clean, 'Contracts\\') || str_ends_with($base, 'Interface') || str_ends_with($base, 'Contract')) {
                        $kind = 'Interface';
                    } elseif (str_contains($clean, 'Concerns\\') || str_ends_with($base, 'Trait') || str_starts_with($base, 'Has') || str_starts_with($base, 'InteractsWith')) {
                        $kind = 'Trait';
                    } elseif (str_contains($clean, 'Enums\\') || str_ends_with($base, 'Enum') || str_ends_with($base, 'Status')) {
                        $kind = 'Enum';
                    } elseif (str_contains($clean, 'Facades\\') || str_starts_with($clean, 'Illuminate\Support\Facades\\')) {
                        $kind = 'Facade';
                    }

                    $relPath = is_string($file) ? substr($file, strlen($basePath) + 1) : null;

                    $classes[$clean] = [
                        'class' => $clean,
                        'name' => $base,
                        'kind' => $kind,
                        'detail' => $kind,
                        'path' => $relPath,
                    ];
                }
            }
        } catch (Throwable) {}
    }

    /**
     * Load package namespaces from vendor/composer/autoload_psr4.php.
     *
     * @param array<string, array{class: string, name: string, kind: string, detail: string, path?: string}> $classes
     */
    protected function loadComposerPsr4(string $basePath, array &$classes): void
    {
        $psr4File = $basePath . '/vendor/composer/autoload_psr4.php';
        if (!file_exists($psr4File)) {
            return;
        }

        try {
            $map = include $psr4File;
            if (is_array($map)) {
                foreach ($map as $namespace => $dirs) {
                    $cleanNamespace = rtrim((string) $namespace, '\\');
                    $dirList = is_array($dirs) ? $dirs : [$dirs];

                    foreach ($dirList as $d) {
                        if (!is_string($d) || !is_dir($d)) continue;
                        // Scan top level of prominent package directories
                        if (str_contains($d, 'laravel/') || str_contains($d, 'livewire/') || str_contains($d, 'filament/')) {
                            $this->scanPhpFiles($d, $cleanNamespace, $basePath, $classes);
                        }
                    }
                }
            }
        } catch (Throwable) {}
    }
}
