<?php

declare(strict_types=1);

namespace App\Lsp\Features\AppBindings;

class AppBindingContainerTypeMap
{
    /**
     * Map of standard Laravel core container binding keys to their concrete/contract class names.
     *
     * @var array<string, string>
     */
    protected static array $bindings = [
        'app' => '\Illuminate\Foundation\Application',
        'auth' => '\Illuminate\Auth\AuthManager',
        'auth.driver' => '\Illuminate\Contracts\Auth\Guard',
        'blade.compiler' => '\Illuminate\View\Compilers\BladeCompiler',
        'bus' => '\Illuminate\Contracts\Bus\Dispatcher',
        'cache' => '\Illuminate\Cache\CacheManager',
        'cache.store' => '\Illuminate\Contracts\Cache\Repository',
        'config' => '\Illuminate\Config\Repository',
        'cookie' => '\Illuminate\Cookie\CookieJar',
        'db' => '\Illuminate\Database\DatabaseManager',
        'db.connection' => '\Illuminate\Database\ConnectionInterface',
        'db.schema' => '\Illuminate\Database\Schema\Builder',
        'db.factory' => '\Illuminate\Database\Connectors\ConnectionFactory',
        'encrypter' => '\Illuminate\Encryption\Encrypter',
        'events' => '\Illuminate\Events\Dispatcher',
        'files' => '\Illuminate\Filesystem\Filesystem',
        'filesystem' => '\Illuminate\Filesystem\FilesystemManager',
        'filesystem.disk' => '\Illuminate\Contracts\Filesystem\Filesystem',
        'filesystem.cloud' => '\Illuminate\Contracts\Filesystem\Cloud',
        'gate' => '\Illuminate\Contracts\Auth\Access\Gate',
        'hash' => '\Illuminate\Hashing\HashManager',
        'log' => '\Illuminate\Log\LogManager',
        'mail.manager' => '\Illuminate\Mail\MailManager',
        'mailer' => '\Illuminate\Mail\Mailer',
        'pipeline' => '\Illuminate\Pipeline\Pipeline',
        'queue' => '\Illuminate\Queue\QueueManager',
        'queue.connection' => '\Illuminate\Contracts\Queue\Queue',
        'redirect' => '\Illuminate\Routing\Redirector',
        'redis' => '\Illuminate\Redis\RedisManager',
        'request' => '\Illuminate\Http\Request',
        'router' => '\Illuminate\Routing\Router',
        'session' => '\Illuminate\Session\SessionManager',
        'session.store' => '\Illuminate\Contracts\Session\Session',
        'translation.loader' => '\Illuminate\Translation\FileLoader',
        'translator' => '\Illuminate\Translation\Translator',
        'url' => '\Illuminate\Routing\UrlGenerator',
        'validator' => '\Illuminate\Validation\Factory',
        'view' => '\Illuminate\View\Factory',
        'view.engine.resolver' => '\Illuminate\View\Engines\EngineResolver',
    ];

    /**
     * Resolve the class type from a container binding key or class string.
     */
    public static function resolveType(string $key): ?string
    {
        $clean = trim($key, " '\"\t\n\r\0\x0B");

        if (isset(self::$bindings[$clean])) {
            return self::$bindings[$clean];
        }

        // Handle Class::class string e.g. App\Services\PaymentService
        if (str_contains($clean, '\\') || class_exists($clean) || interface_exists($clean)) {
            return '\\' . ltrim($clean, '\\');
        }

        return null;
    }

    /**
     * Get all known default container bindings.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$bindings;
    }
}
