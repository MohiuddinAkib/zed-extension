<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Project;

class AttributeIntelligenceRegistry
{
    /**
     * Map of attribute definitions.
     */
    protected const ATTRIBUTES = [
        // Container Attributes
        'Auth' => [
            'fqn' => 'Illuminate\Container\Attributes\Auth',
            'aliases' => ['Auth', 'Illuminate\Container\Attributes\Auth'],
            'arguments' => [
                0 => ['domain' => 'driver:auth_guards', 'description' => 'Auth Guard name (e.g. web, api, sanctum)'],
            ],
            'injected_type_resolver' => 'auth_guard',
            'feeds_chaining' => true,
        ],
        'CurrentUser' => [
            'fqn' => 'Illuminate\Container\Attributes\CurrentUser',
            'aliases' => ['CurrentUser', 'Illuminate\Container\Attributes\CurrentUser'],
            'arguments' => [
                0 => ['domain' => 'driver:auth_guards', 'description' => 'Auth Guard name (e.g. web, api)'],
            ],
            'injected_type_resolver' => 'current_user',
            'feeds_chaining' => true,
        ],
        'Cache' => [
            'fqn' => 'Illuminate\Container\Attributes\Cache',
            'aliases' => ['Cache', 'Illuminate\Container\Attributes\Cache'],
            'arguments' => [
                0 => ['domain' => 'driver:cache_stores', 'description' => 'Cache Store name (e.g. redis, file, array)'],
            ],
            'injected_type_resolver' => 'cache_store',
            'feeds_chaining' => true,
        ],
        'Config' => [
            'fqn' => 'Illuminate\Container\Attributes\Config',
            'aliases' => ['Config', 'Illuminate\Container\Attributes\Config'],
            'arguments' => [
                0 => ['domain' => 'config_keys', 'description' => 'Configuration key (e.g. app.name, database.default)'],
            ],
            'injected_type_resolver' => 'config_key',
            'feeds_chaining' => false,
        ],
        'Database' => [
            'fqn' => 'Illuminate\Container\Attributes\Database',
            'aliases' => ['Database', 'Illuminate\Container\Attributes\Database', 'DB', 'Illuminate\Container\Attributes\DB'],
            'arguments' => [
                0 => ['domain' => 'driver:database_connections', 'description' => 'Database connection name (e.g. mysql, pgsql, sqlite)'],
            ],
            'injected_type_resolver' => 'database_connection',
            'feeds_chaining' => true,
        ],
        'Storage' => [
            'fqn' => 'Illuminate\Container\Attributes\Storage',
            'aliases' => ['Storage', 'Illuminate\Container\Attributes\Storage'],
            'arguments' => [
                0 => ['domain' => 'driver:filesystem_disks', 'description' => 'Filesystem disk name (e.g. local, public, s3)'],
            ],
            'injected_type_resolver' => 'filesystem_disk',
            'feeds_chaining' => true,
        ],
        'Log' => [
            'fqn' => 'Illuminate\Container\Attributes\Log',
            'aliases' => ['Log', 'Illuminate\Container\Attributes\Log'],
            'arguments' => [
                0 => ['domain' => 'driver:log_channels', 'description' => 'Log channel name (e.g. stack, single, slack)'],
            ],
            'injected_type_resolver' => 'log_channel',
            'feeds_chaining' => true,
        ],
        'Tag' => [
            'fqn' => 'Illuminate\Container\Attributes\Tag',
            'aliases' => ['Tag', 'Illuminate\Container\Attributes\Tag'],
            'arguments' => [
                0 => ['domain' => 'tags', 'description' => 'Container tag name'],
            ],
            'injected_type_resolver' => 'tag',
            'feeds_chaining' => false,
        ],
        'Bind' => [
            'fqn' => 'Illuminate\Container\Attributes\Bind',
            'aliases' => ['Bind', 'Illuminate\Container\Attributes\Bind'],
            'arguments' => [
                0 => ['domain' => 'bindings', 'description' => 'Container binding or abstract class'],
            ],
            'injected_type_resolver' => 'binding',
            'feeds_chaining' => true,
        ],
        'Give' => [
            'fqn' => 'Illuminate\Container\Attributes\Give',
            'aliases' => ['Give', 'Illuminate\Container\Attributes\Give'],
            'arguments' => [
                0 => ['domain' => 'bindings', 'description' => 'Container binding or abstract class'],
            ],
            'injected_type_resolver' => 'binding',
            'feeds_chaining' => true,
        ],

        // Routing & Controller Attributes
        'Middleware' => [
            'fqn' => 'Illuminate\Routing\Controllers\Middleware',
            'aliases' => ['Middleware', 'Illuminate\Routing\Controllers\Middleware', 'Illuminate\Routing\Attributes\Controllers\Middleware'],
            'arguments' => [
                0 => ['domain' => 'middleware', 'description' => 'Middleware alias or class (e.g. auth, guest, verified)'],
            ],
            'feeds_chaining' => false,
        ],
        'Authorize' => [
            'fqn' => 'Illuminate\Routing\Attributes\Controllers\Authorize',
            'aliases' => ['Authorize', 'Illuminate\Routing\Attributes\Controllers\Authorize', 'Can', 'Illuminate\Auth\Access\Authorize'],
            'arguments' => [
                0 => ['domain' => 'policies', 'description' => 'Gate ability or policy method (e.g. view, update, delete)'],
                1 => ['domain' => 'models', 'description' => 'Target model class (e.g. Post::class)'],
            ],
            'feeds_chaining' => false,
        ],
        'RedirectToRoute' => [
            'fqn' => 'Illuminate\Foundation\Http\Attributes\RedirectToRoute',
            'aliases' => ['RedirectToRoute', 'Illuminate\Foundation\Http\Attributes\RedirectToRoute'],
            'arguments' => [
                0 => ['domain' => 'routes', 'description' => 'Named route (e.g. login, dashboard)'],
            ],
            'feeds_chaining' => false,
        ],

        // Eloquent & Model Attributes
        'Fillable' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\Fillable',
            'aliases' => ['Fillable', 'Illuminate\Database\Eloquent\Attributes\Fillable'],
            'arguments' => [
                0 => ['domain' => 'model_attributes', 'description' => 'Fillable column / attribute'],
            ],
            'feeds_chaining' => false,
        ],
        'Guarded' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\Guarded',
            'aliases' => ['Guarded', 'Illuminate\Database\Eloquent\Attributes\Guarded'],
            'arguments' => [
                0 => ['domain' => 'model_attributes', 'description' => 'Guarded column / attribute'],
            ],
            'feeds_chaining' => false,
        ],
        'Hidden' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\Hidden',
            'aliases' => ['Hidden', 'Illuminate\Database\Eloquent\Attributes\Hidden'],
            'arguments' => [
                0 => ['domain' => 'model_attributes', 'description' => 'Hidden attribute'],
            ],
            'feeds_chaining' => false,
        ],
        'Visible' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\Visible',
            'aliases' => ['Visible', 'Illuminate\Database\Eloquent\Attributes\Visible'],
            'arguments' => [
                0 => ['domain' => 'model_attributes', 'description' => 'Visible attribute'],
            ],
            'feeds_chaining' => false,
        ],
        'Appends' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\Appends',
            'aliases' => ['Appends', 'Illuminate\Database\Eloquent\Attributes\Appends'],
            'arguments' => [
                0 => ['domain' => 'model_attributes', 'description' => 'Appended accessor attribute'],
            ],
            'feeds_chaining' => false,
        ],
        'ScopedBy' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\ScopedBy',
            'aliases' => ['ScopedBy', 'Illuminate\Database\Eloquent\Attributes\ScopedBy'],
            'arguments' => [
                0 => ['domain' => 'scopes', 'description' => 'Eloquent Scope class'],
            ],
            'feeds_chaining' => false,
        ],
        'ObservedBy' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\ObservedBy',
            'aliases' => ['ObservedBy', 'Illuminate\Database\Eloquent\Attributes\ObservedBy'],
            'arguments' => [
                0 => ['domain' => 'observers', 'description' => 'Eloquent Observer class'],
            ],
            'feeds_chaining' => false,
        ],
        'UseEloquentModel' => [
            'fqn' => 'Illuminate\Database\Eloquent\Attributes\UseEloquentModel',
            'aliases' => ['UseEloquentModel', 'Illuminate\Database\Eloquent\Attributes\UseEloquentModel'],
            'arguments' => [
                0 => ['domain' => 'models', 'description' => 'Eloquent Model class'],
            ],
            'feeds_chaining' => false,
        ],

        // View Attributes
        'View' => [
            'fqn' => 'Illuminate\Routing\Attributes\View',
            'aliases' => ['View', 'Illuminate\Routing\Attributes\View', 'Illuminate\View\Attributes\View'],
            'arguments' => [
                0 => ['domain' => 'views', 'description' => 'Blade view name'],
            ],
            'feeds_chaining' => false,
        ],
    ];

    protected DriverRegistry $driverRegistry;
    protected ?SemanticIndex $semanticIndex = null;

    public function __construct(
        protected ?Project $project = null,
        ?DriverRegistry $driverRegistry = null,
    ) {
        $this->driverRegistry = $driverRegistry ?? new DriverRegistry($project);
        if ($project !== null) {
            $this->semanticIndex = new SemanticIndex($project);
        }
    }

    /**
     * Find attribute metadata by FQN or short name.
     *
     * @return array{fqn: string, aliases: array<string>, arguments: array<int, array{domain: string, description: string}>, injected_type_resolver?: string, feeds_chaining: bool}|null
     */
    public function findAttribute(string $name): ?array
    {
        $clean = ltrim($name, '\\');
        $shortName = basename(str_replace('\\', '/', $clean));

        foreach (self::ATTRIBUTES as $key => $attr) {
            if ($key === $clean || $key === $shortName) {
                return $attr;
            }
            foreach ($attr['aliases'] as $alias) {
                if (ltrim($alias, '\\') === $clean || basename(str_replace('\\', '/', $alias)) === $shortName) {
                    return $attr;
                }
            }
        }

        return null;
    }

    /**
     * Get the completion domain for a specific attribute argument index.
     */
    public function getAttributeArgumentDomain(string $name, int $argumentIndex = 0): ?string
    {
        $attr = $this->findAttribute($name);
        if ($attr === null) {
            return null;
        }

        return $attr['arguments'][$argumentIndex]['domain'] ?? null;
    }

    /**
     * Get the completion/link domain for driver-backed Laravel helper arguments.
     */
    public function getHelperArgumentDomain(string $helper, int $argumentIndex = 0): ?string
    {
        if ($argumentIndex !== 0) {
            return null;
        }

        return match (strtolower($helper)) {
            'auth' => 'driver:auth_guards',
            'cache' => 'driver:cache_stores',
            'storage' => 'driver:filesystem_disks',
            'db' => 'driver:database_connections',
            default => null,
        };
    }

    /**
     * Get the completion/link domain for driver-backed Laravel facade method arguments.
     */
    public function getFacadeMethodArgumentDomain(string $facade, string $method, int $argumentIndex = 0): ?string
    {
        if ($argumentIndex !== 0) {
            return null;
        }

        $shortFacade = class_basename(str_replace('/', '\\', ltrim($facade, '\\')));

        return match ($shortFacade) {
            'Auth' => match ($method) {
                'guard', 'user' => 'driver:auth_guards',
                default => null,
            },
            'Storage' => match ($method) {
                'disk', 'fake', 'persistentFake', 'forgetDisk' => 'driver:filesystem_disks',
                default => null,
            },
            'DB', 'Database' => match ($method) {
                'connection' => 'driver:database_connections',
                default => null,
            },
            'Cache' => match ($method) {
                'store', 'driver' => 'driver:cache_stores',
                default => null,
            },
            'Queue' => match ($method) {
                'connection' => 'driver:queue_connections',
                default => null,
            },
            'Mail' => match ($method) {
                'mailer' => 'driver:mailers',
                default => null,
            },
            'Broadcast' => match ($method) {
                'connection' => 'driver:broadcasters',
                default => null,
            },
            'Redis' => match ($method) {
                'connection' => 'driver:redis_connections',
                default => null,
            },
            'Log' => match ($method) {
                'channel', 'driver' => 'driver:log_channels',
                default => null,
            },
            'Route' => match ($method) {
                'middleware' => 'middleware',
                default => null,
            },
            'Gate' => match ($method) {
                'allows', 'denies', 'check', 'authorize', 'inspect' => 'policies',
                default => null,
            },
            default => null,
        };
    }

    /**
     * Resolve the concrete or contract injected type for an attribute.
     */
    public function resolveInjectedType(string $name, ?string $argumentValue = null): ?string
    {
        $attr = $this->findAttribute($name);
        if ($attr === null || empty($attr['injected_type_resolver'])) {
            return null;
        }

        $resolver = $attr['injected_type_resolver'];
        $cleanVal = $argumentValue !== null ? trim($argumentValue, '\'"') : null;

        return match ($resolver) {
            'auth_guard' => $this->driverRegistry->resolveDriverType('auth_guards', $cleanVal),
            'current_user' => '\App\Models\User',
            'cache_store' => $this->driverRegistry->resolveDriverType('cache_stores', $cleanVal),
            'database_connection' => $this->driverRegistry->resolveDriverType('database_connections', $cleanVal),
            'filesystem_disk' => $this->driverRegistry->resolveDriverType('filesystem_disks', $cleanVal),
            'log_channel' => $this->driverRegistry->resolveDriverType('log_channels', $cleanVal),
            'binding' => $this->semanticIndex !== null && $cleanVal !== null
                ? ($this->semanticIndex->containerBindingType($cleanVal) ?? 'mixed')
                : 'mixed',
            'tag' => 'array',
            'config_key' => $cleanVal !== null ? $this->resolveConfigValueType($cleanVal) : 'mixed',
            default => null,
        };
    }

    protected function resolveConfigValueType(string $key): string
    {
        if ($this->project === null) {
            return 'mixed';
        }

        try {
            $configs = $this->project->index->configs()['configs'] ?? collect([]);
            foreach ($configs as $config) {
                if ((string) ($config['name'] ?? '') !== $key) {
                    continue;
                }

                return $this->valueType($config['value'] ?? null);
            }
        } catch (\Throwable) {
        }

        return 'mixed';
    }

    protected function valueType(mixed $value): string
    {
        return match (true) {
            is_string($value) => class_exists($value) || interface_exists($value) ? '\\' . ltrim($value, '\\') : 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_array($value) => 'array',
            is_object($value) => '\\' . ltrim($value::class, '\\'),
            $value === null => 'null',
            default => 'mixed',
        };
    }

    /**
     * Determine if an attribute's result should feed chained member completion.
     */
    public function feedsChaining(string $name): bool
    {
        $attr = $this->findAttribute($name);

        return $attr !== null && ($attr['feeds_chaining'] ?? false);
    }

    public function driverRegistry(): DriverRegistry
    {
        return $this->driverRegistry;
    }

    /**
     * Get all registered attributes.
     */
    public function all(): array
    {
        return self::ATTRIBUTES;
    }
}
