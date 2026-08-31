<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Document;
use App\Lsp\Features\AppBindings\AppBindingContainerTypeMap;
use App\Lsp\Features\Functions\GlobalFunctionRegistry;
use App\Lsp\Project;
use Illuminate\Container\Container;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

class FunctionTypeResolver
{
    protected GlobalFunctionRegistry $functionRegistry;

    protected DocBlockParser $docBlockParser;

    protected ?SemanticIndex $semanticIndex = null;

    /**
     * @var array<string, string|null>
     */
    protected array $cache = [];

    public function __construct(
        protected ?Project $project = null,
        ?GlobalFunctionRegistry $functionRegistry = null,
        ?DocBlockParser $docBlockParser = null,
        ?SemanticIndex $semanticIndex = null,
        ?DriverRegistry $driverRegistry = null,
    ) {
        $this->functionRegistry = $functionRegistry ?? new GlobalFunctionRegistry($this->project);
        $this->docBlockParser = $docBlockParser ?? new DocBlockParser();
        $this->semanticIndex = $semanticIndex ?? $this->resolveSemanticIndex();
        $this->driverRegistry = $driverRegistry ?? new DriverRegistry($project);
    }

    protected function resolveSemanticIndex(): ?SemanticIndex
    {
        if ($this->project !== null) {
            return new SemanticIndex($this->project);
        }

        $container = Container::getInstance();

        if ($container->bound(SemanticIndex::class)) {
            return $container->make(SemanticIndex::class);
        }

        return null;
    }


    /**
     * Resolve the return type of a global helper function call.
     *
     * @param  string  $functionName Name of function (e.g. 'app', 'config', 'auth')
     * @param  string|null  $firstArg First argument literal (e.g. "'db'", "User::class")
     * @param  Document|null  $document Optional document context
     * @param  int|null  $argumentCount Number of arguments passed to function (e.g. 0 for config(), 1 for config('key'))
     * @return string|null Resolved return type string or null
     */
    public function resolve(string $functionName, ?string $firstArg = null, ?Document $document = null, ?int $argumentCount = null): ?string
    {
        $clean = ltrim(strtolower($functionName), '\\');
        $cacheKey = $clean . '|' . ($firstArg ?? '') . '|' . ($argumentCount ?? '');

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // 0. Config helper: config() with 0 arguments -> \Illuminate\Config\Repository
        if ($clean === 'config') {
            $isZeroArgs = $argumentCount === 0 || ($argumentCount === null && ($firstArg === null || trim($firstArg) === '' || trim($firstArg) === '()'));
            if ($isZeroArgs) {
                return $this->cache[$cacheKey] = '\Illuminate\Config\Repository';
            }
            return $this->cache[$cacheKey] = null;
        }

        // 0b. Driver helpers: auth(), cache(), storage(), db()
        if ($clean === 'auth') {
            if ($firstArg !== null && trim($firstArg) !== '' && trim($firstArg) !== '()') {
                $guard = trim($firstArg, '\'"');
                return $this->cache[$cacheKey] = $this->driverRegistry->resolveDriverType('auth_guards', $guard);
            }
            return $this->cache[$cacheKey] = '\Illuminate\Auth\AuthManager';
        }

        if ($clean === 'cache') {
            if ($firstArg !== null && trim($firstArg) !== '') {
                $store = trim($firstArg, '\'"');
                return $this->cache[$cacheKey] = $this->driverRegistry->resolveDriverType('cache_stores', $store);
            }
            return $this->cache[$cacheKey] = '\Illuminate\Contracts\Cache\Repository';
        }

        if ($clean === 'storage') {
            if ($firstArg !== null && trim($firstArg) !== '') {
                $disk = trim($firstArg, '\'"');
                return $this->cache[$cacheKey] = $this->driverRegistry->resolveDriverType('filesystem_disks', $disk);
            }
            return $this->cache[$cacheKey] = '\Illuminate\Filesystem\FilesystemAdapter';
        }

        if ($clean === 'db') {
            if ($firstArg !== null && trim($firstArg) !== '') {
                $conn = trim($firstArg, '\'"');
                return $this->cache[$cacheKey] = $this->driverRegistry->resolveDriverType('database_connections', $conn);
            }
            return $this->cache[$cacheKey] = '\Illuminate\Database\Connection';
        }

        // 2. Container binding resolution: app('db') or resolve(PaymentService::class)
        if (in_array($clean, ['app', 'resolve'], true)) {
            if ($firstArg !== null && trim($firstArg) !== '') {
                $rawBinding = trim($firstArg, " \t\n\r\0\x0B'\"");
                if (str_ends_with($rawBinding, '::class')) {
                    $rawBinding = substr($rawBinding, 0, -7);
                }

                if ($this->semanticIndex !== null) {
                    $type = $this->semanticIndex->containerBindingType($rawBinding);
                    if ($type) {
                        return $this->cache[$cacheKey] = $type;
                    }
                }

                $type = AppBindingContainerTypeMap::resolveType($rawBinding);
                if ($type) {
                    return $this->cache[$cacheKey] = $type;
                }

                if (str_contains($rawBinding, '\\') || class_exists($rawBinding) || interface_exists($rawBinding)) {
                    return $this->cache[$cacheKey] = '\\' . ltrim($rawBinding, '\\');
                }
            }

            return $this->cache[$cacheKey] = '\Illuminate\Foundation\Application';
        }

        // 2. Tap helper: tap($var) -> $var or tap(new Class()) -> Class
        if ($clean === 'tap' && $firstArg !== null && trim($firstArg) !== '') {
            $arg = trim($firstArg);
            if (preg_match('/^new\s+([a-zA-Z0-9_\\\\]+)/', $arg, $m)) {
                return $this->cache[$cacheKey] = '\\' . ltrim($m[1], '\\');
            }
        }

        // 3. Stringable, Collection & Fluent helpers
        if ($clean === 'str') {
            return $this->cache[$cacheKey] = '\Illuminate\Support\Stringable';
        }
        if ($clean === 'collect') {
            return $this->cache[$cacheKey] = '\Illuminate\Support\Collection';
        }
        if ($clean === 'fluent') {
            return $this->cache[$cacheKey] = '\Illuminate\Support\Fluent';
        }

        // 4. Query GlobalFunctionRegistry (indexed helpers, user helpers, laravel/builtin catalog)
        $info = $this->functionRegistry->get($clean);
        if ($info && !empty($info['returnType']) && $info['returnType'] !== 'mixed') {
            $extracted = $this->extractChainedClassType($info['returnType']);
            if ($extracted !== null) {
                return $this->cache[$cacheKey] = $extracted;
            }
        }

        // 5. Runtime Reflection fallback
        if (function_exists($clean)) {
            try {
                $ref = new ReflectionFunction($clean);
                if ($ref->hasReturnType()) {
                    $retType = $ref->getReturnType();
                    if ($retType instanceof ReflectionNamedType) {
                        $tName = $retType->getName();
                        if (!$retType->isBuiltin() && !in_array($tName, ['self', 'parent', 'static', 'mixed', 'void', 'null'], true)) {
                            return $this->cache[$cacheKey] = '\\' . ltrim($tName, '\\');
                        }
                    } elseif ($retType instanceof ReflectionUnionType) {
                        $unionTypes = array_map(fn ($t) => $t instanceof ReflectionNamedType && !$t->isBuiltin() ? ('\\' . ltrim($t->getName(), '\\')) : (string) $t, $retType->getTypes());
                        $extracted = $this->extractChainedClassType(implode('|', $unionTypes));
                        if ($extracted !== null) {
                            return $this->cache[$cacheKey] = $extracted;
                        }
                    }
                }

                if ($doc = $ref->getDocComment()) {
                    $docReturn = $this->docBlockParser->extractReturnTag($doc);
                    if ($docReturn !== null && $docReturn !== 'mixed') {
                        $extracted = $this->extractChainedClassType($docReturn);
                        if ($extracted !== null) {
                            return $this->cache[$cacheKey] = $extracted;
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        return $this->cache[$cacheKey] = null;
    }

    /**
     * Extract the primary object/class type suitable for chained method/property access from a return type string.
     */
    public function extractChainedClassType(string $typeString): ?string
    {
        if (str_contains($typeString, ' is ') || str_contains($typeString, '?')) {
            if (preg_match_all('/\\\\?([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\\\\[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)+)/', $typeString, $classMatches)) {
                $uniqueClasses = array_unique(array_map(fn ($c) => '\\' . ltrim($c, '\\'), $classMatches[0]));
                if (count($uniqueClasses) === 1) {
                    return $uniqueClasses[0];
                }
            }
        }

        $types = explode('|', $typeString);
        $candidates = [];

        foreach ($types as $type) {
            $t = trim($type);
            $clean = ltrim(preg_replace('/^\?/', '', $t), '\\');

            if ($clean === '' || in_array($clean, ['mixed', 'null', 'void', 'never', 'false', 'true', 'bool', 'int', 'float', 'string', 'array', 'iterable', 'callable', 'resource'], true)) {
                continue;
            }

            $candidates[] = str_starts_with($t, '\\') ? $t : ('\\' . ltrim($t, '\\'));
        }

        if (empty($candidates)) {
            return null;
        }

        // Prioritize concrete classes ending in Manager or Request or Carbon or Application
        foreach ($candidates as $cand) {
            if (str_ends_with($cand, 'Manager') || str_ends_with($cand, 'Request') || str_ends_with($cand, 'Carbon') || str_ends_with($cand, 'Application')) {
                return $cand;
            }
        }

        // If multiple classes remain, return the first candidate or union
        return count($candidates) === 1 ? $candidates[0] : implode('|', $candidates);
    }
}
