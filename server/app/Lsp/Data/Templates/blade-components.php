<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Symfony\Component\Finder\Finder;

$components = new class
{
    protected $autoloaded = [];

    protected $prefixes = [];

    public function __construct()
    {
        $this->autoloaded = require base_path('vendor/composer/autoload_psr4.php');
    }

    public function all()
    {
        $components = collect(array_merge(
            $this->getStandardClasses(),
            $this->getStandardViews(),
            $this->getNamespaced(),
            $this->getAnonymousNamespaced(),
            $this->getAnonymous(),
            $this->getAliases(),
            $this->getVendorComponents(),
        ))->groupBy('key')->pipe($this->setProps(...))->map(fn ($items) => [
            'isVendor' => $items->first()['isVendor'],
            'paths'    => $items->pluck('path')->unique()->values(),
            'props'    => $this->formatProps($items),
        ]);

        return [
            'components' => $components,
            'prefixes'   => $this->prefixes,
        ];
    }

    protected function formatProps($items)
    {
        $props = $items->pluck('props');

        if ($codeBlock = $props->firstWhere(fn ($prop) => is_string($prop))) {
            return $codeBlock;
        }

        return $props->values()
            ->filter()
            ->flatMap(fn ($i) => $i)
            ->map(fn ($i) => array_key_exists('default', $i)
                ? array_merge($i, ['default' => LspHelper::formatDefaultValue($i['default'])])
                : $i
            );
    }

    protected function getStandardViews()
    {
        $path = resource_path('views/components');

        return $this->findFiles($path, 'blade.php');
    }

    protected function findFiles($path, $extension, $keyCallback = null)
    {
        if (!is_dir($path)) {
            return [];
        }

        try {
            $files = Finder::create()
                ->files()
                ->name('*.' . $extension)
                ->in($path);
        } catch (Throwable) {
            return [];
        }

        $components = [];
        $pathRealPath = realpath($path) ?: $path;

        foreach ($files as $file) {
            try {
                $realPath = $file->getRealPath() ?: $file->getPathname();

                $key = str($realPath)
                    ->replace($pathRealPath, '')
                    ->ltrim('/\\')
                    ->replace('.' . $extension, '')
                    ->replace(['/', '\\'], '.')
                    ->pipe(fn ($str) => $this->handleIndexComponents($str));

                $res = $keyCallback ? $keyCallback($key) : $key;
                $keys = is_array($res) || $res instanceof \Illuminate\Support\Collection ? $res : [$res];

                foreach ($keys as $k) {
                    $components[] = [
                        'path'     => LspHelper::relativePath($realPath),
                        'isVendor' => LspHelper::isVendor($realPath),
                        'key'      => (string) $k,
                    ];
                }
            } catch (Throwable) {}
        }

        return $components;
    }

    protected function formatKeySegments($str): string
    {
        return collect(explode('.', (string) $str))
            ->map(fn ($p) => Str::kebab($p))
            ->implode('.');
    }

    protected function getStandardClasses()
    {
        $path = app_path('View/Components');

        $appNamespace = collect($this->autoloaded)
            ->filter(fn ($paths) => in_array(app_path(), $paths))
            ->keys()
            ->first() ?? '';

        return collect($this->findFiles(
            $path,
            'php',
            fn ($key) => $this->formatKeySegments($key),
        ))->map(function ($item) use ($appNamespace) {
            $class = str($item['path'])
                ->after('View/Components/')
                ->replace('.php', '')
                ->replace('/', '\\')
                ->prepend($appNamespace . 'View\\Components\\')
                ->toString();

            if (!class_exists($class)) {
                return $item;
            }

            $reflection = new ReflectionClass($class);
            $parameters = collect($reflection->getConstructor()?->getParameters() ?? [])
                ->filter(fn ($p) => $p->isPromoted())
                ->keyBy(fn ($p) => $p->getName());

            $props = collect($reflection->getProperties())
                ->filter(fn ($p) => $p->isPublic() && $p->getDeclaringClass()->getName() === $class)
                ->map(fn ($p) => [
                    'name' => Str::kebab($p->getName()),
                    'type' => (string) ($p->getType() ?? 'mixed'),
                    ...LspHelper::propertyDefault($p, $parameters->get($p->getName())),
                ]);

            [$except, $props] = $props->partition(fn ($p) => $p['name'] === 'except');

            if ($except->isNotEmpty()) {
                $except = $except->first()['default'];
                $props = $props->reject(fn ($p) => in_array($p['name'], $except));
            }

            return [
                ...$item,
                'props' => $props,
            ];
        })->all();
    }

    protected function getAliases()
    {
        $components = [];

        foreach (Blade::getClassComponentAliases() as $key => $class) {
            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);

                $components[] = [
                    'path'     => LspHelper::relativePath($reflection->getFileName()),
                    'isVendor' => LspHelper::isVendor($reflection->getFileName()),
                    'key'      => $key,
                ];
            }
        }

        return $components;
    }

    protected function getAnonymousNamespaced()
    {
        $components = [];

        foreach (Blade::getAnonymousComponentNamespaces() as $key => $dir) {
            $candidates = [
                $dir,
                resource_path('views/' . $dir),
                resource_path($dir),
                base_path($dir),
                resource_path('views/' . str_replace('.', '/', $dir)),
                resource_path(str_replace('.', '/', $dir)),
            ];
            $path = collect($candidates)->first(fn ($p) => is_string($p) && is_dir($p));

            if (!$path) {
                continue;
            }

            if (!in_array($key, $this->prefixes, true)) {
                $this->prefixes[] = $key;
            }

            array_push(
                $components,
                ...$this->findFiles(
                    $path,
                    'blade.php',
                    fn ($k) => $key . '::' . $this->formatKeySegments($k),
                )
            );
        }

        return $components;
    }

    protected function getAnonymous()
    {
        $components = [];

        foreach (Blade::getAnonymousComponentPaths() as $item) {
            $path = $item['path'] ?? null;
            if (!$path || !is_dir($path)) {
                continue;
            }

            $prefix = $item['prefix'] ?? '';
            if ($prefix !== '' && !in_array($prefix, $this->prefixes, true)) {
                $this->prefixes[] = $prefix;
            }

            array_push(
                $components,
                ...$this->findFiles(
                    $path,
                    'blade.php',
                    function (Stringable $key) use ($prefix) {
                        $keyFormatted = $this->formatKeySegments($key);
                        $keys = [];

                        if ($prefix !== '') {
                            $keys[] = "{$prefix}::{$keyFormatted}";

                            if ($prefix === 'flux') {
                                $keys[] = "flux:{$keyFormatted}";
                            }
                        } else {
                            $keys[] = $keyFormatted;
                        }

                        return $keys;
                    },
                )
            );
        }

        return $components;
    }

    protected function getVendorComponents(): array
    {
        $components = [];

        try {
            /** @var Factory $view */
            $view = App::make('view');

            /** @var FileViewFinder $finder */
            $finder = $view->getFinder();

            /** @var array<string, array<int, string>> $views */
            $views = $finder->getHints();
        } catch (Throwable) {
            return [];
        }

        foreach ($views as $key => $paths) {
            if (!in_array($key, $this->prefixes, true)) {
                $this->prefixes[] = $key;
            }

            foreach ((array) $paths as $path) {
                if (!is_string($path) || !is_dir($path)) {
                    continue;
                }

                $scannedSub = false;

                // Check /components subdirectory
                $compPath = rtrim($path, '/\\') . '/components';
                if (is_dir($compPath)) {
                    $scannedSub = true;
                    array_push(
                        $components,
                        ...$this->findFiles(
                            $compPath,
                            'blade.php',
                            fn (Stringable $k) => $key . '::' . $this->formatKeySegments($k),
                        )
                    );
                }

                // Check /html subdirectory (e.g. Mail markdown components)
                $htmlPath = rtrim($path, '/\\') . '/html';
                if (is_dir($htmlPath)) {
                    $scannedSub = true;
                    array_push(
                        $components,
                        ...$this->findFiles(
                            $htmlPath,
                            'blade.php',
                            fn (Stringable $k) => $key . '::' . $this->formatKeySegments($k),
                        )
                    );
                }

                // If neither /components nor /html exists, scan the hint root directory directly
                if (!$scannedSub) {
                    array_push(
                        $components,
                        ...$this->findFiles(
                            $path,
                            'blade.php',
                            fn (Stringable $k) => $key . '::' . $this->formatKeySegments($k),
                        )
                    );
                }
            }
        }

        return $components;
    }

    protected function handleIndexComponents($str)
    {
        if ($str->endsWith('.index')) {
            return $str->replaceLast('.index', '');
        }

        if (!$str->contains('.')) {
            return $str;
        }

        $parts = $str->explode('.');

        if ($parts->slice(-2)->unique()->count() === 1) {
            $parts->pop();

            return str($parts->implode('.'));
        }

        return $str;
    }

    protected function getNamespaced()
    {
        $namespaced = Blade::getClassComponentNamespaces();
        $components = [];

        foreach ($namespaced as $key => $classNamespace) {
            $path = $this->getNamespacePath($classNamespace);

            if (!$path) {
                continue;
            }

            array_push(
                $components,
                ...$this->findFiles(
                    $path,
                    'php',
                    fn ($k) => $k->kebab()->prepend($key . '::'),
                )
            );
        }

        return $components;
    }

    protected function getNamespacePath($classNamespace)
    {
        foreach ($this->autoloaded as $ns => $paths) {
            if (!str_starts_with($classNamespace, $ns)) {
                continue;
            }

            foreach ($paths as $p) {
                $dir = str($classNamespace)
                    ->replace($ns, '')
                    ->replace('\\', '/')
                    ->prepend($p . DIRECTORY_SEPARATOR)
                    ->toString();

                if (is_dir($dir)) {
                    return $dir;
                }
            }

            return null;
        }

        return null;
    }

    protected function setProps($groups)
    {
        try {
            $compiler = app('blade.compiler');
        } catch (Throwable $e) {
            return $groups;
        }

        return $groups->map(function ($group) use ($compiler) {
            return $group->transform(function ($component) use ($compiler) {
                if (isset($component['props'])) {
                    return $component;
                }

                if (!str($component['path'])->endsWith('.blade.php')) {
                    return $component;
                }

                if (!$props = $this->parseProps($compiler, $component)) {
                    return $component;
                }

                return array_merge($component, ['props' => $props]);
            });
        });
    }

    protected function parseProps($compiler, array $component): ?string
    {
        $content = file_get_contents(base_path($component['path']));

        $result = '';

        $compiler->directive('props', function ($expression) use (&$result) {
            return $result = $expression;
        });

        $compiler->compileString($content);

        if (empty($result)) {
            return null;
        }

        return '@props(' . $result . ')';
    }
};

echo json_encode($components->all());
