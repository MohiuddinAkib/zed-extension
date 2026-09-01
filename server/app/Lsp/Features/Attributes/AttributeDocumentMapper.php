<?php

declare(strict_types=1);

namespace App\Lsp\Features\Attributes;

use App\Lsp\Analysis\AttributeIntelligenceRegistry;
use App\Lsp\Analysis\DriverRegistry;
use App\Lsp\Analysis\SemanticIndex;
use App\Lsp\Detection\DetectedArgument;
use App\Lsp\Detection\DetectedArguments;
use App\Lsp\Detection\Pattern;
use App\Lsp\Document;
use App\Lsp\Features\ClassIndex\ClassRegistry;
use App\Lsp\Features\Support\DocumentMapper;
use App\Lsp\Project;
use App\Lsp\Support\Position;
use Illuminate\Support\Collection;
use Throwable;

class AttributeDocumentMapper extends DocumentMapper
{
    protected AttributeIntelligenceRegistry $attrRegistry;

    protected DriverRegistry $driverRegistry;

    protected SemanticIndex $semanticIndex;

    protected ClassRegistry $classRegistry;

    public function __construct(protected Project $project)
    {
        $this->attrRegistry = new AttributeIntelligenceRegistry($project);
        $this->driverRegistry = $this->attrRegistry->driverRegistry();
        $this->semanticIndex = new SemanticIndex($project);
        $this->classRegistry = new ClassRegistry($project);
    }

    /**
     * Get attribute, helper, and facade driver detection patterns.
     *
     * @return array<int, Pattern>
     */
    protected function patterns(): array
    {
        $patterns = [];

        foreach ($this->attrRegistry->all() as $attribute) {
            $arguments = array_keys($attribute['arguments'] ?? []);
            if ($arguments === []) {
                continue;
            }

            $classes = $this->classAliases(array_merge(
                [$attribute['fqn'] ?? ''],
                $attribute['aliases'] ?? [],
            ));

            if ($classes !== []) {
                $patterns[] = Pattern::attribute($classes, $arguments);
            }
        }

        $patterns[] = Pattern::method(method: ['auth', 'cache', 'storage', 'db'], argument: 0);
        $patterns[] = Pattern::method(method: ['guard', 'user'], class: $this->facadeAliases('Auth'), argument: 0);
        $patterns[] = Pattern::method(method: ['disk', 'fake', 'persistentFake', 'forgetDisk'], class: $this->facadeAliases('Storage'), argument: 0);
        $patterns[] = Pattern::method(method: 'connection', class: array_merge($this->facadeAliases('DB'), $this->facadeAliases('Database')), argument: 0);
        $patterns[] = Pattern::method(method: ['store', 'driver'], class: $this->facadeAliases('Cache'), argument: 0);
        $patterns[] = Pattern::method(method: 'connection', class: $this->facadeAliases('Queue'), argument: 0);
        $patterns[] = Pattern::method(method: 'mailer', class: $this->facadeAliases('Mail'), argument: 0);
        $patterns[] = Pattern::method(method: 'connection', class: $this->facadeAliases('Broadcast'), argument: 0);
        $patterns[] = Pattern::method(method: 'connection', class: $this->facadeAliases('Redis'), argument: 0);
        $patterns[] = Pattern::method(method: ['channel', 'driver'], class: $this->facadeAliases('Log'), argument: 0);
        $patterns[] = Pattern::method(method: 'middleware', class: $this->facadeAliases('Route'), argument: 0);
        $patterns[] = Pattern::method(method: ['allows', 'denies', 'check', 'authorize', 'inspect'], class: $this->facadeAliases('Gate'), argument: 0);

        return $patterns;
    }

    /**
     * Get matched string and string-array arguments from the document.
     *
     * @return Collection<int, DetectedArgument>
     */
    public function arguments(Document $document): Collection
    {
        return DetectedArguments::in($document)
            ->matching($this->patterns())
            ->stringsAndArrays()
            ->filter(fn (DetectedArgument $argument): bool => $this->domainFor($argument) !== null)
            ->values();
    }

    /**
     * Convert the given argument to document links.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toLinks(DetectedArgument $argument): array
    {
        $domain = $this->domainFor($argument);
        if ($domain === null) {
            return [];
        }

        return collect($argument->stringValues())
            ->map(fn (array $value): ?array => $this->linkForValue($domain, $value['value'], $value['range']))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Convert the given argument to hover.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    protected function toHover(DetectedArgument $argument, array $position): ?array
    {
        $domain = $this->domainFor($argument);
        if ($domain === null) {
            return null;
        }

        foreach ($argument->stringValues() as $value) {
            if (!Position::inRange($value['range'], $position)) {
                continue;
            }

            $source = $this->sourceForValue($domain, $value['value']);
            if ($source === null) {
                return null;
            }

            $path = $this->projectPath((string) $source['path']);
            $line = is_numeric($source['line'] ?? null) ? (int) $source['line'] : null;
            $target = $this->project->target($path, $line);
            $lines = [
                "**{$this->domainLabel($domain)}**: `{$value['value']}`",
            ];

            if (isset($source['detail']) && is_string($source['detail']) && $source['detail'] !== '') {
                $lines[] = $source['detail'];
            }

            if (isset($source['key']) && is_string($source['key']) && $source['key'] !== '' && $source['key'] !== $value['value']) {
                $lines[] = "`{$source['key']}`";
            }

            $lines[] = "[{$path}]({$target})";

            return [
                'range'    => $value['range'],
                'contents' => [
                    'kind'  => 'markdown',
                    'value' => implode("\n\n", $lines),
                ],
            ];
        }

        return null;
    }

    protected function domainFor(DetectedArgument $argument): ?string
    {
        $item = $argument->item();
        $argumentIndex = $argument->argumentIndex();

        if (($item['type'] ?? null) === 'object') {
            $className = $item['className'] ?? null;

            return is_string($className)
                ? $this->attrRegistry->getAttributeArgumentDomain($className, $argumentIndex)
                : null;
        }

        if (($item['type'] ?? null) !== 'methodCall') {
            return null;
        }

        $method = $item['methodName'] ?? null;
        if (!is_string($method)) {
            return null;
        }

        $class = $item['className'] ?? null;
        if (is_string($class) && $class !== '') {
            return $this->attrRegistry->getFacadeMethodArgumentDomain($class, $method, $argumentIndex);
        }

        return $this->attrRegistry->getHelperArgumentDomain($method, $argumentIndex);
    }

    protected function linkForValue(string $domain, string $value, array $range): ?array
    {
        $source = $this->sourceForValue($domain, $value);
        if ($source === null || !is_string($source['path'] ?? null) || $source['path'] === '') {
            return null;
        }

        return $this->project->link(
            $range,
            $this->projectPath((string) $source['path']),
            is_numeric($source['line'] ?? null) ? (int) $source['line'] : null,
        );
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function sourceForValue(string $domain, string $value): ?array
    {
        if (str_starts_with($domain, 'driver:')) {
            $kind = substr($domain, 7);
            $source = $this->driverRegistry->sourceForDriver($kind, $value);
            if ($source === null) {
                return null;
            }

            $driver = $this->driverRegistry->getDrivers($kind)[trim($value, '\'"')] ?? [];
            $driverName = is_array($driver) ? (string) ($driver['configuredDriver'] ?? '') : '';
            $type = is_array($driver) ? (string) ($driver['resolvedType'] ?? '') : '';

            return [
                'path'   => $source['file'],
                'line'   => $source['line'],
                'key'    => $source['key'],
                'detail' => trim("Driver: `{$driverName}`  \nType: `{$type}`"),
            ];
        }

        return match ($domain) {
            'config_keys' => $this->configSource($value),
            'views' => $this->viewSource($value),
            'routes' => $this->routeSource($value),
            'middleware' => $this->middlewareSource($value),
            'policies' => $this->policySource($value),
            'bindings' => $this->bindingSource($value),
            'models', 'scopes', 'observers' => $this->classOrModelSource($value),
            default => null,
        };
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function configSource(string $key): ?array
    {
        $config = $this->configs()->firstWhere('name', $key);
        if (!is_array($config) || !is_string($config['file'] ?? null)) {
            return null;
        }

        return [
            'path' => $config['file'],
            'line' => is_numeric($config['line'] ?? null) ? (int) $config['line'] : null,
            'key'  => $key,
        ];
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function viewSource(string $key): ?array
    {
        $view = $this->views()->firstWhere('key', $key);
        if (!is_array($view) || !is_string($view['path'] ?? null)) {
            return null;
        }

        return [
            'path' => $view['path'],
            'line' => is_numeric($view['line'] ?? null) ? (int) $view['line'] : null,
            'key'  => $key,
        ];
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function routeSource(string $name): ?array
    {
        $route = $this->routes()->firstWhere('name', $name);
        if (!is_array($route) || !is_string($route['filename'] ?? null)) {
            return null;
        }

        return [
            'path'   => $route['filename'],
            'line'   => is_numeric($route['line'] ?? null) ? (int) $route['line'] : null,
            'key'    => $name,
            'detail' => (string) ($route['action'] ?? ''),
        ];
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function middlewareSource(string $value): ?array
    {
        $name = explode(':', $value)[0];

        foreach ($this->middleware() as $key => $middleware) {
            if (!is_array($middleware)) {
                continue;
            }

            $middlewareKey = (string) ($middleware['key'] ?? $middleware['name'] ?? (is_string($key) ? $key : ''));
            if ($middlewareKey !== $name) {
                continue;
            }

            if (is_string($middleware['path'] ?? null)) {
                return [
                    'path'   => $middleware['path'],
                    'line'   => is_numeric($middleware['line'] ?? null) ? (int) $middleware['line'] : null,
                    'key'    => $name,
                    'detail' => (string) ($middleware['class'] ?? ''),
                ];
            }

            foreach (($middleware['groups'] ?? []) as $group) {
                if (is_array($group) && is_string($group['path'] ?? null)) {
                    return [
                        'path'   => $group['path'],
                        'line'   => is_numeric($group['line'] ?? null) ? (int) $group['line'] : null,
                        'key'    => $name,
                        'detail' => (string) ($group['class'] ?? $middleware['class'] ?? ''),
                    ];
                }
            }

            if (is_string($middleware['class'] ?? null)) {
                return $this->classSource((string) $middleware['class'], $name);
            }
        }

        return null;
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function policySource(string $ability): ?array
    {
        try {
            $policies = $this->project->index->auth()['policies'][$ability] ?? [];
        } catch (Throwable) {
            return null;
        }

        foreach ($policies as $policy) {
            if (!is_array($policy) || !is_string($policy['uri'] ?? null)) {
                continue;
            }

            return [
                'path'   => $policy['uri'],
                'line'   => is_numeric($policy['line'] ?? null) ? (int) $policy['line'] : null,
                'key'    => $ability,
                'detail' => (string) ($policy['policy'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function bindingSource(string $key): ?array
    {
        try {
            $bindings = $this->project->index->appBindings();
        } catch (Throwable) {
            $bindings = [];
        }

        foreach ($bindings as $bindingKey => $binding) {
            if (!is_array($binding) || (string) $bindingKey !== $key) {
                continue;
            }

            if (is_string($binding['path'] ?? null)) {
                return [
                    'path'   => $binding['path'],
                    'line'   => is_numeric($binding['line'] ?? null) ? (int) $binding['line'] : null,
                    'key'    => $key,
                    'detail' => (string) ($binding['class'] ?? $binding['concrete'] ?? ''),
                ];
            }

            $class = $binding['class'] ?? $binding['concrete'] ?? $binding['resolvedType'] ?? null;
            if (is_string($class)) {
                return $this->classSource($class, $key);
            }
        }

        $binding = $this->semanticIndex->containerBindings()[$key] ?? null;
        if (is_array($binding) && is_string($binding['class'] ?? null)) {
            return $this->classSource($binding['class'], $key);
        }

        return null;
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function classOrModelSource(string $class): ?array
    {
        $clean = $this->cleanClassValue($class);

        foreach ($this->semanticIndex->models() as $indexedClass => $model) {
            if (!is_array($model)) {
                continue;
            }

            $modelClass = ltrim((string) ($model['class'] ?? $indexedClass), '\\');
            if ($modelClass !== ltrim($clean, '\\') && class_basename($modelClass) !== class_basename($clean)) {
                continue;
            }

            if (is_string($model['path'] ?? null)) {
                return [
                    'path'   => $model['path'],
                    'line'   => is_numeric($model['line'] ?? null) ? (int) $model['line'] : null,
                    'key'    => $class,
                    'detail' => '\\' . $modelClass,
                ];
            }
        }

        return $this->classSource($clean, $class);
    }

    /**
     * @return array{path?: string|null, line?: int|null, key?: string, detail?: string}|null
     */
    protected function classSource(string $class, ?string $key = null): ?array
    {
        $clean = $this->cleanClassValue($class);

        foreach ($this->classRegistry->search($clean, 5) as $candidate) {
            $candidateClass = ltrim((string) ($candidate['class'] ?? ''), '\\');
            if ($candidateClass === '' || ($candidateClass !== ltrim($clean, '\\') && class_basename($candidateClass) !== class_basename($clean))) {
                continue;
            }

            if (!is_string($candidate['path'] ?? null) || $candidate['path'] === '') {
                continue;
            }

            return [
                'path'   => $candidate['path'],
                'line'   => is_numeric($candidate['line'] ?? null) ? (int) $candidate['line'] : null,
                'key'    => $key ?? $class,
                'detail' => '\\' . $candidateClass,
            ];
        }

        return null;
    }

    protected function cleanClassValue(string $class): string
    {
        $clean = trim($class, " \t\n\r\0\x0B'\"");
        $clean = preg_replace('/::class\s*$/i', '', $clean) ?? $clean;

        return ltrim($clean, '\\');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function configs(): Collection
    {
        try {
            $configs = $this->project->index->configs()['configs'] ?? [];

            return $configs instanceof Collection ? $configs : collect($configs);
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function views(): Collection
    {
        try {
            $views = $this->project->index->views();

            return $views instanceof Collection ? $views : collect($views);
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function routes(): Collection
    {
        try {
            $routes = $this->project->index->routes();

            return $routes instanceof Collection ? $routes : collect($routes);
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int|string, array<string, mixed>>
     */
    protected function middleware(): Collection
    {
        try {
            $middleware = $this->project->index->middleware();

            return $middleware instanceof Collection ? $middleware : collect($middleware);
        } catch (Throwable) {
            return collect();
        }
    }

    protected function domainLabel(string $domain): string
    {
        if (str_starts_with($domain, 'driver:')) {
            return 'Laravel Driver';
        }

        return match ($domain) {
            'config_keys' => 'Laravel Config',
            'views' => 'Laravel View',
            'routes' => 'Laravel Route',
            'middleware' => 'Laravel Middleware',
            'policies' => 'Laravel Gate Ability',
            'bindings' => 'Laravel Container Binding',
            'models' => 'Eloquent Model',
            'scopes' => 'Eloquent Scope',
            'observers' => 'Eloquent Observer',
            default => 'Laravel Symbol',
        };
    }

    protected function projectPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $this->project->uri()->relativePath($path);
        }

        return $path;
    }

    protected function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(?:/|[A-Za-z]:[\\\\/])#', $path) === 1;
    }

    /**
     * @param  array<int, string>  $classes
     * @return array<int, string>
     */
    protected function classAliases(array $classes): array
    {
        $aliases = [];

        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }

            $clean = ltrim($class, '\\');
            $aliases[] = $clean;
            $aliases[] = '\\' . $clean;
            $aliases[] = class_basename($clean);
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @return array<int, string>
     */
    protected function facadeAliases(string $facade): array
    {
        return $this->classAliases([
            $facade,
            "Illuminate\\Support\\Facades\\{$facade}",
        ]);
    }
}
