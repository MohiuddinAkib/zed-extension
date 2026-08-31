<?php

declare(strict_types=1);

namespace App\Lsp\Features\Facades;

class FacadeMap
{
    /**
     * Map of global Blade/Laravel aliases to their fully-qualified class names.
     *
     * @var array<string, string>
     */
    protected static array $facades = [
        'Js' => '\\Illuminate\\Support\\Js',
        'App' => '\\Illuminate\\Support\\Facades\\App',
        'Arr' => '\\Illuminate\\Support\\Arr',
        'Artisan' => '\\Illuminate\\Support\\Facades\\Artisan',
        'Auth' => '\\Illuminate\\Support\\Facades\\Auth',
        'Blade' => '\\Illuminate\\Support\\Facades\\Blade',
        'Broadcast' => '\\Illuminate\\Support\\Facades\\Broadcast',
        'Bus' => '\\Illuminate\\Support\\Facades\\Bus',
        'Cache' => '\\Illuminate\\Support\\Facades\\Cache',
        'Config' => '\\Illuminate\\Support\\Facades\\Config',
        'Context' => '\\Illuminate\\Support\\Facades\\Context',
        'Cookie' => '\\Illuminate\\Support\\Facades\\Cookie',
        'Crypt' => '\\Illuminate\\Support\\Facades\\Crypt',
        'Date' => '\\Illuminate\\Support\\Facades\\Date',
        'DB' => '\\Illuminate\\Support\\Facades\\DB',
        'Event' => '\\Illuminate\\Support\\Facades\\Event',
        'Exceptions' => '\\Illuminate\\Support\\Facades\\Exceptions',
        'File' => '\\Illuminate\\Support\\Facades\\File',
        'Gate' => '\\Illuminate\\Support\\Facades\\Gate',
        'Hash' => '\\Illuminate\\Support\\Facades\\Hash',
        'Http' => '\\Illuminate\\Support\\Facades\\Http',
        'Lang' => '\\Illuminate\\Support\\Facades\\Lang',
        'Log' => '\\Illuminate\\Support\\Facades\\Log',
        'Mail' => '\\Illuminate\\Support\\Facades\\Mail',
        'Notification' => '\\Illuminate\\Support\\Facades\\Notification',
        'Number' => '\\Illuminate\\Support\\Number',
        'ParallelTesting' => '\\Illuminate\\Support\\Facades\\ParallelTesting',
        'Password' => '\\Illuminate\\Support\\Facades\\Password',
        'Pipeline' => '\\Illuminate\\Support\\Facades\\Pipeline',
        'Process' => '\\Illuminate\\Support\\Facades\\Process',
        'Queue' => '\\Illuminate\\Support\\Facades\\Queue',
        'RateLimiter' => '\\Illuminate\\Support\\Facades\\RateLimiter',
        'Redirect' => '\\Illuminate\\Support\\Facades\\Redirect',
        'Redis' => '\\Illuminate\\Support\\Facades\\Redis',
        'Request' => '\\Illuminate\\Support\\Facades\\Request',
        'Response' => '\\Illuminate\\Support\\Facades\\Response',
        'Route' => '\\Illuminate\\Support\\Facades\\Route',
        'Schedule' => '\\Illuminate\\Support\\Facades\\Schedule',
        'Schema' => '\\Illuminate\\Support\\Facades\\Schema',
        'Session' => '\\Illuminate\\Support\\Facades\\Session',
        'Storage' => '\\Illuminate\\Support\\Facades\\Storage',
        'Str' => '\\Illuminate\\Support\\Str',
        'URL' => '\\Illuminate\\Support\\Facades\\URL',
        'Validator' => '\\Illuminate\\Support\\Facades\\Validator',
        'View' => '\\Illuminate\\Support\\Facades\\View',
        'Vite' => '\\Illuminate\\Support\\Facades\\Vite',
    ];

    /**
     * Facade underlying accessor / implementation classes when not directly defined on the class.
     *
     * @var array<string, string>
     */
    protected static array $accessors = [
        '\\Illuminate\\Support\\Facades\\App' => '\\Illuminate\\Foundation\\Application',
        '\\Illuminate\\Support\\Facades\\Auth' => '\\Illuminate\\Auth\\AuthManager',
        '\\Illuminate\\Support\\Facades\\Blade' => '\\Illuminate\\View\\Compilers\\BladeCompiler',
        '\\Illuminate\\Support\\Facades\\Cache' => '\\Illuminate\\Cache\\CacheManager',
        '\\Illuminate\\Support\\Facades\\Config' => '\\Illuminate\\Config\\Repository',
        '\\Illuminate\\Support\\Facades\\Cookie' => '\\Illuminate\\Cookie\\CookieJar',
        '\\Illuminate\\Support\\Facades\\Crypt' => '\\Illuminate\\Encryption\\Encrypter',
        '\\Illuminate\\Support\\Facades\\DB' => '\\Illuminate\\Database\\DatabaseManager',
        '\\Illuminate\\Support\\Facades\\Event' => '\\Illuminate\\Events\\Dispatcher',
        '\\Illuminate\\Support\\Facades\\File' => '\\Illuminate\\Filesystem\\Filesystem',
        '\\Illuminate\\Support\\Facades\\Gate' => '\\Illuminate\\Contracts\\Auth\\Access\\Gate',
        '\\Illuminate\\Support\\Facades\\Hash' => '\\Illuminate\\Hashing\\HashManager',
        '\\Illuminate\\Support\\Facades\\Http' => '\\Illuminate\\Http\\Client\\Factory',
        '\\Illuminate\\Support\\Facades\\Lang' => '\\Illuminate\\Translation\\Translator',
        '\\Illuminate\\Support\\Facades\\Log' => '\\Illuminate\\Log\\LogManager',
        '\\Illuminate\\Support\\Facades\\Mail' => '\\Illuminate\\Mail\\MailManager',
        '\\Illuminate\\Support\\Facades\\Queue' => '\\Illuminate\\Queue\\QueueManager',
        '\\Illuminate\\Support\\Facades\\Redirect' => '\\Illuminate\\Routing\\Redirector',
        '\\Illuminate\\Support\\Facades\\Redis' => '\\Illuminate\\Redis\\RedisManager',
        '\\Illuminate\\Support\\Facades\\Request' => '\\Illuminate\\Http\\Request',
        '\\Illuminate\\Support\\Facades\\Route' => '\\Illuminate\\Routing\\Router',
        '\\Illuminate\\Support\\Facades\\Schema' => '\\Illuminate\\Database\\Schema\\Builder',
        '\\Illuminate\\Support\\Facades\\Session' => '\\Illuminate\\Session\\SessionManager',
        '\\Illuminate\\Support\\Facades\\Storage' => '\\Illuminate\\Filesystem\\FilesystemManager',
        '\\Illuminate\\Support\\Facades\\URL' => '\\Illuminate\\Routing\\UrlGenerator',
        '\\Illuminate\\Support\\Facades\\Validator' => '\\Illuminate\\Validation\\Factory',
        '\\Illuminate\\Support\\Facades\\View' => '\\Illuminate\\View\\Factory',
    ];

    /**
     * Facade brief documentation for hover and completion descriptions.
     *
     * @var array<string, string>
     */
    protected static array $descriptions = [
        'Js' => 'Laravel JavaScript Helper (Js::from($data))',
        'App' => 'Laravel Application Container Facade',
        'Arr' => 'Laravel Array Helper',
        'Artisan' => 'Laravel Artisan Console Facade',
        'Auth' => 'Laravel Authentication Facade (Auth::user(), Auth::check())',
        'Blade' => 'Laravel Blade Compiler Facade',
        'Broadcast' => 'Laravel Event Broadcasting Facade',
        'Bus' => 'Laravel Command Bus Facade',
        'Cache' => 'Laravel Cache Facade (Cache::get(), Cache::put())',
        'Config' => 'Laravel Configuration Facade (Config::get())',
        'Context' => 'Laravel Context Facade',
        'Cookie' => 'Laravel Cookie Facade (Cookie::get())',
        'Crypt' => 'Laravel Encrypter Facade',
        'Date' => 'Laravel Date/Carbon Factory Facade',
        'DB' => 'Laravel Database Facade (DB::table(), DB::select())',
        'Event' => 'Laravel Event Dispatcher Facade',
        'Exceptions' => 'Laravel Exception Handler Facade',
        'File' => 'Laravel Filesystem Facade (File::get())',
        'Gate' => 'Laravel Authorization Gate Facade (Gate::allows(), Gate::denies())',
        'Hash' => 'Laravel Hashing Facade (Hash::make(), Hash::check())',
        'Http' => 'Laravel HTTP Client Facade (Http::get(), Http::post())',
        'Lang' => 'Laravel Localization/Translation Facade (Lang::get())',
        'Log' => 'Laravel Logging Facade (Log::info(), Log::error())',
        'Mail' => 'Laravel Mailer Facade (Mail::to(), Mail::send())',
        'Notification' => 'Laravel Notification Facade',
        'Number' => 'Laravel Number Helper (Number::format(), Number::currency())',
        'Password' => 'Laravel Password Broker Facade',
        'Pipeline' => 'Laravel Pipeline Facade',
        'Process' => 'Laravel Process Execution Facade',
        'Queue' => 'Laravel Queue Facade (Queue::push())',
        'RateLimiter' => 'Laravel Rate Limiter Facade',
        'Redirect' => 'Laravel Redirector Facade',
        'Redis' => 'Laravel Redis Client Facade',
        'Request' => 'Laravel HTTP Request Facade (Request::ip(), Request::is())',
        'Response' => 'Laravel Response Factory Facade',
        'Route' => 'Laravel Routing Facade (Route::has(), Route::currentRouteName())',
        'Schedule' => 'Laravel Task Scheduling Facade',
        'Schema' => 'Laravel Schema Builder Facade (Schema::hasTable())',
        'Session' => 'Laravel Session Facade (Session::get(), Session::has())',
        'Storage' => 'Laravel Storage / Filesystem Disks Facade (Storage::disk(), Storage::url())',
        'Str' => 'Laravel String Helper (Str::slug(), Str::limit(), Str::contains())',
        'URL' => 'Laravel URL Generator Facade (URL::to(), URL::route())',
        'Validator' => 'Laravel Validation Factory Facade (Validator::make())',
        'View' => 'Laravel View Factory Facade (View::make())',
        'Vite' => 'Laravel Vite Asset Bundle Facade (Vite::asset())',
    ];

    /**
     * Check whether a name is a registered facade or global alias.
     */
    public static function isFacadeOrAlias(string $name): bool
    {
        $clean = ltrim($name, '\\');
        return isset(self::$facades[$clean]);
    }

    /**
     * Resolve facade/alias name to its target class name.
     */
    public static function resolve(string $name): ?string
    {
        $clean = ltrim($name, '\\');
        if (isset(self::$facades[$clean])) {
            return self::$facades[$clean];
        }

        return null;
    }

    /**
     * Resolve the underlying accessor concrete or contract class for a facade.
     */
    public static function resolveAccessor(string $facadeNameOrClass): ?string
    {
        $clean = ltrim($facadeNameOrClass, '\\');
        $fqcn = self::$facades[$clean] ?? ('\\' . $clean);

        if (isset(self::$accessors[$fqcn])) {
            return self::$accessors[$fqcn];
        }

        return $fqcn;
    }

    /**
     * Get description for a facade or alias.
     */
    public static function description(string $name): string
    {
        $clean = ltrim($name, '\\');
        return self::$descriptions[$clean] ?? "Laravel {$clean} Facade";
    }

    /**
     * Get all registered facades.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$facades;
    }

    /**
     * Generate PHP use statements for all default facades and utilities.
     */
    public static function defaultUseStatements(): string
    {
        $lines = [];
        foreach (self::$facades as $alias => $fqcn) {
            $cleanFqcn = ltrim($fqcn, '\\');
            if (str_ends_with($cleanFqcn, "\\{$alias}")) {
                $lines[] = "use {$cleanFqcn};";
            } else {
                $lines[] = "use {$cleanFqcn} as {$alias};";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get completion items for all facades matching an optional prefix.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function completions(?string $prefix = null): array
    {
        $items = [];
        $lowPrefix = $prefix !== null ? strtolower($prefix) : '';

        foreach (self::$facades as $alias => $fqcn) {
            if ($lowPrefix !== '' && !str_starts_with(strtolower($alias), $lowPrefix)) {
                continue;
            }

            $desc = self::description($alias);
            $items[] = [
                'label' => $alias,
                'kind' => 7, // Class
                'detail' => ltrim($fqcn, '\\'),
                'documentation' => [
                    'kind' => 'markdown',
                    'value' => "**{$alias}** (`{$fqcn}`)\n\n{$desc}",
                ],
                'insertText' => $alias,
            ];
        }

        return $items;
    }
}
