<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Features\AppBindings\AppBindingContainerTypeMap;
use App\Lsp\Features\BladeVariables\EloquentBuilderRegistry;
use App\Lsp\Project;
use Illuminate\Support\Collection;
use Throwable;

final class SemanticIndex
{
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Get indexed Eloquent model metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public function models(): array
    {
        try {
            return $this->project->index->models();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get reflected Eloquent builder methods.
     *
     * @return array<int, array<string, mixed>>
     */
    public function builderMethods(): array
    {
        try {
            if (!method_exists($this->project->index, 'builderMethods')) {
                return [];
            }

            return $this->project->index->builderMethods();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Resolve all Eloquent query members for the given model or builder type.
     *
     * @return array<string, array<string, mixed>>
     */
    public function eloquentMembersForModel(?string $modelClass = null, bool $isStatic = false): array
    {
        $members = EloquentBuilderRegistry::getMembersForModel(
            $modelClass,
            $isStatic,
            $this->builderMethods(),
        );

        foreach ($this->eloquentScopeMembersForModel($modelClass, $isStatic) as $name => $scopeMember) {
            $members[$name] = $scopeMember;
        }

        return $members;
    }

    /**
     * Resolve local model scopes as builder-callable methods.
     *
     * @return array<string, array<string, mixed>>
     */
    public function eloquentScopeMembersForModel(?string $modelClass = null, bool $isStatic = false): array
    {
        $modelData = $this->findModel($modelClass);

        if ($modelData === null) {
            return [];
        }

        $modelDisplay = $modelClass !== null ? class_basename($modelClass) : 'Model';
        $fullModel = $modelClass !== null ? '\\' . ltrim($modelClass, '\\') : 'Model';
        $returnType = "\\Illuminate\\Database\\Eloquent\\Builder<{$fullModel}>";
        $members = [];

        foreach ($modelData['scopes'] ?? [] as $scope) {
            if (!is_array($scope)) {
                continue;
            }

            $name = (string) ($scope['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $parameters = array_values(is_array($scope['parameters'] ?? null) ? $scope['parameters'] : []);
            if ($this->isScopeBuilderParameter($parameters[0] ?? null)) {
                array_shift($parameters);
            }

            [$paramSignature, $requiredParams] = $this->formatParameters($parameters);
            $scopeMethod = (string) ($scope['method'] ?? ('scope' . ucfirst($name)));
            $members[$name] = [
                'name'           => $name,
                'kind'           => 2,
                'detail'         => "{$paramSignature}: {$returnType}",
                'paramSignature' => $paramSignature,
                'returnType'     => $returnType,
                'requiredParams' => $requiredParams,
                'snippet'        => $requiredParams > 0 ? "{$name}(\${1})" : "{$name}()",
                'isMethod'       => true,
                'documentation'  => '**' . ($isStatic ? "{$modelDisplay}::{$name}" : "\${$modelDisplay}Query->{$name}") . "**\n\n```php\npublic function {$name}{$paramSignature}: {$returnType};\n```\n\nLocal Eloquent scope declared by `{$scopeMethod}`.\n\n*Origin:* `Eloquent Local Scope`",
            ];
        }

        return $members;
    }

    /**
     * Resolve the concrete class for an app()/resolve() binding key.
     */
    public function containerBindingType(string $key): ?string
    {
        $clean = trim($key, " '\"\t\n\r\0\x0B");

        foreach ($this->appBindings() as $bindingKey => $binding) {
            if ((string) $bindingKey !== $clean || !is_array($binding)) {
                continue;
            }

            $type = $this->bindingTypeFromData($binding);
            if ($type !== null) {
                return $type;
            }
        }

        return AppBindingContainerTypeMap::resolveType($clean);
    }

    /**
     * Get indexed container bindings merged with the core fallback overlay.
     *
     * @return array<string, array{key: string, class: string|null, origin: string}>
     */
    public function containerBindings(): array
    {
        $bindings = [];

        foreach ($this->appBindings() as $key => $binding) {
            if (!is_string($key) || !is_array($binding)) {
                continue;
            }

            $bindings[$key] = [
                'key'    => $key,
                'class'  => $this->bindingTypeFromData($binding),
                'origin' => 'Indexed Container Binding',
            ];
        }

        foreach (AppBindingContainerTypeMap::all() as $key => $class) {
            if (!isset($bindings[$key])) {
                $bindings[$key] = [
                    'key'    => $key,
                    'class'  => $class,
                    'origin' => 'Laravel Core Binding Overlay',
                ];
            }
        }

        return $bindings;
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    protected function appBindings(): iterable
    {
        try {
            $bindings = $this->project->index->appBindings();

            if ($bindings instanceof Collection) {
                return $bindings->all();
            }

            return is_array($bindings) ? $bindings : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    protected function bindingTypeFromData(array $binding): ?string
    {
        foreach (['resolvedType', 'concrete', 'class', 'abstract'] as $key) {
            $value = $binding[$key] ?? null;

            if (!is_string($value) || $value === '') {
                continue;
            }

            if ($this->looksLikeClassName($value)) {
                return '\\' . ltrim($value, '\\');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findModel(?string $modelClass): ?array
    {
        if ($modelClass === null || $modelClass === '') {
            return null;
        }

        $clean = ltrim($modelClass, '\\');
        $base = class_basename($clean);

        foreach ($this->models() as $indexedClass => $modelData) {
            if (!is_array($modelData)) {
                continue;
            }

            $indexedClean = ltrim((string) $indexedClass, '\\');
            if ($indexedClean === $clean || class_basename($indexedClean) === $base) {
                return $modelData;
            }
        }

        return null;
    }

    protected function isScopeBuilderParameter(mixed $parameter): bool
    {
        if (!is_array($parameter)) {
            return false;
        }

        $name = strtolower((string) ($parameter['name'] ?? ''));
        $type = ltrim((string) ($parameter['type'] ?? ''), '\\');

        return in_array($name, ['query', 'builder'], true)
            || str_contains($type, 'Illuminate\\Database\\Eloquent\\Builder')
            || str_contains($type, 'Illuminate\\Database\\Query\\Builder');
    }

    /**
     * @param  array<int, mixed>  $parameters
     * @return array{0: string, 1: int}
     */
    protected function formatParameters(array $parameters): array
    {
        $requiredParams = 0;
        $parts = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = ltrim((string) ($parameter['name'] ?? 'param'), '$');
            if ($name === '') {
                $name = 'param';
            }

            $type = trim((string) ($parameter['type'] ?? 'mixed'));
            $isVariadic = (bool) ($parameter['isVariadic'] ?? false);
            $isByReference = (bool) ($parameter['isPassedByReference'] ?? false);
            $hasDefault = (bool) ($parameter['hasDefault'] ?? $parameter['hasDefaultValue'] ?? $parameter['optional'] ?? false);

            if (!$hasDefault && !$isVariadic) {
                $requiredParams++;
            }

            $part = $type !== '' && $type !== 'mixed' ? "{$type} " : '';
            $part .= $isByReference ? '&' : '';
            $part .= $isVariadic ? '...' : '';
            $part .= '$' . $name;

            if ($hasDefault) {
                $part .= ' = ' . (string) ($parameter['default'] ?? $parameter['defaultValue'] ?? 'null');
            }

            $parts[] = $part;
        }

        return ['(' . implode(', ', $parts) . ')', $requiredParams];
    }

    protected function looksLikeClassName(string $value): bool
    {
        return str_contains($value, '\\') || class_exists($value) || interface_exists($value);
    }
}
