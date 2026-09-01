<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Project;
use Illuminate\Support\Collection;

class DriverRegistry
{
    /**
     * Driver definitions mapping domain to config prefixes and default concrete types.
     */
    protected const DRIVER_CONFIGS = [
        'auth_guards' => [
            'prefix' => 'auth.guards.',
            'default_driver' => 'session',
            'fallback_type' => '\Illuminate\Contracts\Auth\Guard',
            'defaults' => [
                'web' => ['driver' => 'session', 'type' => '\Illuminate\Auth\SessionGuard'],
                'api' => ['driver' => 'token', 'type' => '\Illuminate\Auth\TokenGuard'],
                'sanctum' => ['driver' => 'sanctum', 'type' => '\Laravel\Sanctum\Guard'],
            ],
            'driver_type_map' => [
                'session' => '\Illuminate\Auth\SessionGuard',
                'token' => '\Illuminate\Auth\TokenGuard',
                'sanctum' => '\Laravel\Sanctum\Guard',
            ],
        ],
        'cache_stores' => [
            'prefix' => 'cache.stores.',
            'default_driver' => 'file',
            'fallback_type' => '\Illuminate\Contracts\Cache\Repository',
            'defaults' => [
                'array' => ['driver' => 'array', 'type' => '\Illuminate\Contracts\Cache\Repository'],
                'file' => ['driver' => 'file', 'type' => '\Illuminate\Contracts\Cache\Repository'],
                'redis' => ['driver' => 'redis', 'type' => '\Illuminate\Contracts\Cache\Repository'],
                'database' => ['driver' => 'database', 'type' => '\Illuminate\Contracts\Cache\Repository'],
                'memcached' => ['driver' => 'memcached', 'type' => '\Illuminate\Contracts\Cache\Repository'],
                'dynamodb' => ['driver' => 'dynamodb', 'type' => '\Illuminate\Contracts\Cache\Repository'],
                'octane' => ['driver' => 'octane', 'type' => '\Illuminate\Contracts\Cache\Repository'],
            ],
            'driver_type_map' => [
                'redis' => '\Illuminate\Contracts\Cache\Repository',
                'file' => '\Illuminate\Contracts\Cache\Repository',
                'array' => '\Illuminate\Contracts\Cache\Repository',
                'database' => '\Illuminate\Contracts\Cache\Repository',
            ],
        ],
        'database_connections' => [
            'prefix' => 'database.connections.',
            'default_driver' => 'mysql',
            'fallback_type' => '\Illuminate\Database\Connection',
            'defaults' => [
                'mysql' => ['driver' => 'mysql', 'type' => '\Illuminate\Database\MySqlConnection'],
                'sqlite' => ['driver' => 'sqlite', 'type' => '\Illuminate\Database\SQLiteConnection'],
                'pgsql' => ['driver' => 'pgsql', 'type' => '\Illuminate\Database\PostgresConnection'],
                'sqlsrv' => ['driver' => 'sqlsrv', 'type' => '\Illuminate\Database\SqlServerConnection'],
                'mariadb' => ['driver' => 'mariadb', 'type' => '\Illuminate\Database\MySqlConnection'],
            ],
            'driver_type_map' => [
                'mysql' => '\Illuminate\Database\MySqlConnection',
                'mariadb' => '\Illuminate\Database\MySqlConnection',
                'sqlite' => '\Illuminate\Database\SQLiteConnection',
                'pgsql' => '\Illuminate\Database\PostgresConnection',
                'postgres' => '\Illuminate\Database\PostgresConnection',
                'sqlsrv' => '\Illuminate\Database\SqlServerConnection',
                'sqlserver' => '\Illuminate\Database\SqlServerConnection',
            ],
        ],
        'filesystem_disks' => [
            'prefix' => 'filesystems.disks.',
            'default_driver' => 'local',
            'fallback_type' => '\Illuminate\Filesystem\FilesystemAdapter',
            'defaults' => [
                'local' => ['driver' => 'local', 'type' => '\Illuminate\Filesystem\FilesystemAdapter'],
                'public' => ['driver' => 'local', 'type' => '\Illuminate\Filesystem\FilesystemAdapter'],
                's3' => ['driver' => 's3', 'type' => '\Illuminate\Filesystem\FilesystemAdapter'],
                'scoped' => ['driver' => 'scoped', 'type' => '\Illuminate\Filesystem\FilesystemAdapter'],
            ],
            'driver_type_map' => [
                'local' => '\Illuminate\Filesystem\FilesystemAdapter',
                'public' => '\Illuminate\Filesystem\FilesystemAdapter',
                's3' => '\Illuminate\Filesystem\FilesystemAdapter',
                'scoped' => '\Illuminate\Filesystem\FilesystemAdapter',
                'ftp' => '\Illuminate\Filesystem\FilesystemAdapter',
                'sftp' => '\Illuminate\Filesystem\FilesystemAdapter',
            ],
        ],
        'queue_connections' => [
            'prefix' => 'queue.connections.',
            'default_driver' => 'sync',
            'fallback_type' => '\Illuminate\Contracts\Queue\Queue',
            'defaults' => [
                'sync' => ['driver' => 'sync', 'type' => '\Illuminate\Queue\SyncQueue'],
                'database' => ['driver' => 'database', 'type' => '\Illuminate\Queue\DatabaseQueue'],
                'redis' => ['driver' => 'redis', 'type' => '\Illuminate\Queue\RedisQueue'],
                'beanstalkd' => ['driver' => 'beanstalkd', 'type' => '\Illuminate\Queue\BeanstalkdQueue'],
                'sqs' => ['driver' => 'sqs', 'type' => '\Illuminate\Queue\SqsQueue'],
            ],
            'driver_type_map' => [
                'sync' => '\Illuminate\Queue\SyncQueue',
                'database' => '\Illuminate\Queue\DatabaseQueue',
                'redis' => '\Illuminate\Queue\RedisQueue',
                'sqs' => '\Illuminate\Queue\SqsQueue',
                'beanstalkd' => '\Illuminate\Queue\BeanstalkdQueue',
            ],
        ],
        'mailers' => [
            'prefix' => 'mail.mailers.',
            'default_driver' => 'smtp',
            'fallback_type' => '\Illuminate\Mail\Mailer',
            'defaults' => [
                'smtp' => ['driver' => 'smtp', 'type' => '\Illuminate\Mail\Mailer'],
                'sendmail' => ['driver' => 'sendmail', 'type' => '\Illuminate\Mail\Mailer'],
                'mailgun' => ['driver' => 'mailgun', 'type' => '\Illuminate\Mail\Mailer'],
                'ses' => ['driver' => 'ses', 'type' => '\Illuminate\Mail\Mailer'],
                'postmark' => ['driver' => 'postmark', 'type' => '\Illuminate\Mail\Mailer'],
                'log' => ['driver' => 'log', 'type' => '\Illuminate\Mail\Mailer'],
                'array' => ['driver' => 'array', 'type' => '\Illuminate\Mail\Mailer'],
                'failover' => ['driver' => 'failover', 'type' => '\Illuminate\Mail\Mailer'],
                'roundrobin' => ['driver' => 'roundrobin', 'type' => '\Illuminate\Mail\Mailer'],
            ],
            'driver_type_map' => [
                'smtp' => '\Illuminate\Mail\Mailer',
                'sendmail' => '\Illuminate\Mail\Mailer',
                'log' => '\Illuminate\Mail\Mailer',
                'array' => '\Illuminate\Mail\Mailer',
            ],
        ],
        'broadcasters' => [
            'prefix' => 'broadcasting.connections.',
            'default_driver' => 'log',
            'fallback_type' => '\Illuminate\Contracts\Broadcasting\Broadcaster',
            'defaults' => [
                'reverb' => ['driver' => 'reverb', 'type' => '\Illuminate\Contracts\Broadcasting\Broadcaster'],
                'pusher' => ['driver' => 'pusher', 'type' => '\Illuminate\Contracts\Broadcasting\Broadcaster'],
                'ably' => ['driver' => 'ably', 'type' => '\Illuminate\Contracts\Broadcasting\Broadcaster'],
                'redis' => ['driver' => 'redis', 'type' => '\Illuminate\Contracts\Broadcasting\Broadcaster'],
                'log' => ['driver' => 'log', 'type' => '\Illuminate\Contracts\Broadcasting\Broadcaster'],
                'null' => ['driver' => 'null', 'type' => '\Illuminate\Contracts\Broadcasting\Broadcaster'],
            ],
            'driver_type_map' => [],
        ],
        'redis_connections' => [
            'prefix' => 'database.redis.',
            'default_driver' => 'default',
            'fallback_type' => '\Illuminate\Redis\Connections\Connection',
            'defaults' => [
                'default' => ['driver' => 'default', 'type' => '\Illuminate\Redis\Connections\Connection'],
                'cache' => ['driver' => 'cache', 'type' => '\Illuminate\Redis\Connections\Connection'],
            ],
            'driver_type_map' => [],
        ],
        'session_drivers' => [
            'prefix' => 'session.driver',
            'default_driver' => 'file',
            'fallback_type' => '\Illuminate\Session\Store',
            'defaults' => [
                'file' => ['driver' => 'file', 'type' => '\Illuminate\Session\Store'],
                'cookie' => ['driver' => 'cookie', 'type' => '\Illuminate\Session\Store'],
                'database' => ['driver' => 'database', 'type' => '\Illuminate\Session\Store'],
                'redis' => ['driver' => 'redis', 'type' => '\Illuminate\Session\Store'],
                'array' => ['driver' => 'array', 'type' => '\Illuminate\Session\Store'],
            ],
            'driver_type_map' => [],
        ],
        'log_channels' => [
            'prefix' => 'logging.channels.',
            'default_driver' => 'stack',
            'fallback_type' => '\Psr\Log\LoggerInterface',
            'defaults' => [
                'stack' => ['driver' => 'stack', 'type' => '\Psr\Log\LoggerInterface'],
                'single' => ['driver' => 'single', 'type' => '\Psr\Log\LoggerInterface'],
                'daily' => ['driver' => 'daily', 'type' => '\Psr\Log\LoggerInterface'],
                'slack' => ['driver' => 'slack', 'type' => '\Psr\Log\LoggerInterface'],
                'stderr' => ['driver' => 'stderr', 'type' => '\Psr\Log\LoggerInterface'],
                'syslog' => ['driver' => 'syslog', 'type' => '\Psr\Log\LoggerInterface'],
                'errorlog' => ['driver' => 'errorlog', 'type' => '\Psr\Log\LoggerInterface'],
                'null' => ['driver' => 'null', 'type' => '\Psr\Log\LoggerInterface'],
            ],
            'driver_type_map' => [],
        ],
    ];

    public function __construct(
        protected ?Project $project = null,
    ) {}

    /**
     * Get all discovered and default drivers for a given domain kind.
     *
     * @return array<string, array{name: string, kind: string, configuredDriver: ?string, resolvedType: string, metadata: array}>
     */
    public function getDrivers(string $kind): array
    {
        $schema = self::DRIVER_CONFIGS[$kind] ?? null;
        if ($schema === null) {
            return [];
        }

        $discovered = [];

        // 1. Discover from project indexed configs
        if ($this->project !== null) {
            try {
                $configs = $this->project->index->configs()['configs'] ?? collect([]);
                $prefix = $schema['prefix'];
                $matchedConfigs = $configs->filter(fn (array $c): bool => str_starts_with((string) ($c['name'] ?? ''), $prefix));

                foreach ($matchedConfigs as $c) {
                    $fullName = (string) ($c['name'] ?? '');
                    $sub = substr($fullName, strlen($prefix));
                    $parts = explode('.', $sub);
                    $name = $parts[0] ?? '';
                    if ($name !== '' && !isset($discovered[$name])) {
                        $configuredDriver = $this->findConfiguredDriverName($configs, $prefix . $name, $schema['default_driver']);
                        $resolvedType = $this->resolveTypeForDriver($schema, $name, $configuredDriver);
                        $discovered[$name] = [
                            'name'             => $name,
                            'kind'             => $kind,
                            'configuredDriver' => $configuredDriver,
                            'resolvedType'     => $resolvedType,
                            'metadata'         => $c,
                        ];
                    }
                }
            } catch (\Throwable) {}
        }

        // 2. Merge with standard defaults
        foreach ($schema['defaults'] as $name => $info) {
            if (!isset($discovered[$name])) {
                $discovered[$name] = [
                    'name'             => $name,
                    'kind'             => $kind,
                    'configuredDriver' => $info['driver'],
                    'resolvedType'     => $info['type'],
                    'metadata'         => [],
                ];
            }
        }

        return $discovered;
    }

    /**
     * Resolve the concrete PHP class / interface type for a given driver name.
     */
    public function resolveDriverType(string $kind, ?string $driverName): string
    {
        $schema = self::DRIVER_CONFIGS[$kind] ?? null;
        if ($schema === null) {
            return 'mixed';
        }

        if ($driverName === null || trim($driverName) === '') {
            return $schema['fallback_type'];
        }

        $cleanName = trim($driverName, '\'"');
        $drivers = $this->getDrivers($kind);
        if (isset($drivers[$cleanName])) {
            return $drivers[$cleanName]['resolvedType'];
        }

        // Direct driver_type_map check
        if (isset($schema['driver_type_map'][$cleanName])) {
            return $schema['driver_type_map'][$cleanName];
        }

        return $schema['fallback_type'];
    }

    /**
     * Resolve the indexed config source for a configured driver, when available.
     *
     * @return array{key: string, file: string|null, line: int|null}|null
     */
    public function sourceForDriver(string $kind, string $driverName): ?array
    {
        $schema = self::DRIVER_CONFIGS[$kind] ?? null;
        if ($schema === null) {
            return null;
        }

        $cleanName = trim($driverName, '\'"');
        if ($cleanName === '') {
            return null;
        }

        $driver = $this->getDrivers($kind)[$cleanName] ?? null;
        if (!is_array($driver)) {
            return null;
        }

        $metadata = $driver['metadata'] ?? [];
        if (!is_array($metadata)) {
            return null;
        }

        $file = $metadata['file'] ?? null;
        if (!is_string($file) || $file === '') {
            return null;
        }

        return [
            'key'  => (string) ($metadata['name'] ?? ($schema['prefix'] . $cleanName)),
            'file' => $file,
            'line' => is_numeric($metadata['line'] ?? null) ? (int) $metadata['line'] : null,
        ];
    }

    /**
     * List all supported driver domain kinds.
     *
     * @return array<int, string>
     */
    public function allKinds(): array
    {
        return array_keys(self::DRIVER_CONFIGS);
    }

    protected function findConfiguredDriverName(mixed $configs, string $baseKey, string $defaultDriver): string
    {
        if ($configs instanceof Collection) {
            $driverConfig = $configs->firstWhere('name', $baseKey . '.driver');
            if ($driverConfig && is_string($driverConfig['value'] ?? null)) {
                return $driverConfig['value'];
            }
        }

        return $defaultDriver;
    }

    protected function resolveTypeForDriver(array $schema, string $name, ?string $configuredDriver): string
    {
        // 1. Check if name matches directly in defaults
        if (isset($schema['defaults'][$name]['type'])) {
            return $schema['defaults'][$name]['type'];
        }

        // 2. Check if name matches directly in driver_type_map
        if (isset($schema['driver_type_map'][$name])) {
            return $schema['driver_type_map'][$name];
        }

        // 3. Check configured driver
        if ($configuredDriver !== null && isset($schema['driver_type_map'][$configuredDriver])) {
            return $schema['driver_type_map'][$configuredDriver];
        }

        return $schema['fallback_type'];
    }
}
