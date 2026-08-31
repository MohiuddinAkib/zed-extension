<?php

declare(strict_types=1);

namespace App\Lsp\Features\Functions;

use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use ReflectionFunction;
use Throwable;

class GlobalFunctionRegistry
{
    /**
     * Cache of scanned user-defined functions: name => [name, signature, snippet, returnType, doc, source, line]
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $userFunctionsCache = null;

    /**
     * Standard Laravel global helper functions catalog.
     *
     * @var array<string, array{signature: string, returnType: string, doc: string, snippet?: string}>
     */
    protected static array $laravelHelpers = [
        'route' => [
            'signature' => 'route(string $name, mixed $parameters = [], bool $absolute = true): string',
            'returnType' => 'string',
            'doc' => "Generate the URL to a named route.\n\n```php\nroute('users.show', ['user' => 1])\n```",
            'snippet' => "route('\${1:name}')",
        ],
        'view' => [
            'signature' => 'view(?string $view = null, array $data = [], array $mergeData = []): \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory',
            'returnType' => '\Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory',
            'doc' => "Get the evaluated view contents for the given view.\n\n```php\nview('users.profile', ['user' => \$user])\n```",
            'snippet' => "view('\${1:view}')",
        ],
        'asset' => [
            'signature' => 'asset(string $path, ?bool $secure = null): string',
            'returnType' => 'string',
            'doc' => "Generate a URL for an asset using the current scheme of the request.\n\n```php\nasset('img/photo.jpg')\n```",
            'snippet' => "asset('\${1:path}')",
        ],
        'config' => [
            'signature' => 'config(array|string|null $key = null, mixed $default = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Get / set the specified configuration value.\n\n```php\nconfig('app.name')\n```",
            'snippet' => "config('\${1:key}')",
        ],
        'trans' => [
            'signature' => 'trans(?string $key = null, array $replace = [], ?string $locale = null): string|array|null',
            'returnType' => 'string|array|null',
            'doc' => "Translate the given message.\n\n```php\ntrans('messages.welcome')\n```",
            'snippet' => "trans('\${1:key}')",
        ],
        '__' => [
            'signature' => '__(?string $key = null, array $replace = [], ?string $locale = null): string|array|null',
            'returnType' => 'string|array|null',
            'doc' => "Translate the given message.\n\n```php\n__('Welcome to our application')\n```",
            'snippet' => "__('\${1:key}')",
        ],
        'auth' => [
            'signature' => 'auth(?string $guard = null): \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard|\Illuminate\Auth\AuthManager',
            'returnType' => '\Illuminate\Auth\AuthManager',
            'doc' => "Get the available auth instance or authenticated user.\n\n```php\nauth()->user()\n```",
            'snippet' => "auth()\${1:->user()}",
        ],
        'session' => [
            'signature' => 'session(array|string|null $key = null, mixed $default = null): mixed|\Illuminate\Session\SessionManager',
            'returnType' => 'mixed|\Illuminate\Session\SessionManager',
            'doc' => "Get / set the specified session value or session manager.\n\n```php\nsession('status')\n```",
            'snippet' => "session('\${1:key}')",
        ],
        'request' => [
            'signature' => 'request(?string $key = null, mixed $default = null): mixed|\Illuminate\Http\Request',
            'returnType' => 'mixed|\Illuminate\Http\Request',
            'doc' => "Get an instance of the current HTTP request or an input item from the request.\n\n```php\nrequest('search')\n```",
            'snippet' => "request('\${1:key}')",
        ],
        'old' => [
            'signature' => 'old(?string $key = null, mixed $default = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Retrieve an old input item from flash data.\n\n```php\nold('username')\n```",
            'snippet' => "old('\${1:key}')",
        ],
        'csrf_token' => [
            'signature' => 'csrf_token(): string',
            'returnType' => 'string',
            'doc' => "Get the CSRF token value.",
            'snippet' => "csrf_token()",
        ],
        'csrf_field' => [
            'signature' => 'csrf_field(): \Illuminate\Support\HtmlString',
            'returnType' => '\Illuminate\Support\HtmlString',
            'doc' => "Generate a CSRF token form field HTML.",
            'snippet' => "csrf_field()",
        ],
        'method_field' => [
            'signature' => 'method_field(string $method): \Illuminate\Support\HtmlString',
            'returnType' => '\Illuminate\Support\HtmlString',
            'doc' => "Generate a form field HTML spoofing HTTP verb (PUT, PATCH, DELETE).",
            'snippet' => "method_field('\${1:PUT}')",
        ],
        'collect' => [
            'signature' => 'collect(mixed $value = null): \Illuminate\Support\Collection',
            'returnType' => '\Illuminate\Support\Collection',
            'doc' => "Create a collection from the given value.\n\n```php\ncollect([1, 2, 3])->map(...)\n```",
            'snippet' => "collect(\${1:\$items})",
        ],
        'now' => [
            'signature' => 'now(?\DateTimeZone|string $tz = null): \Illuminate\Support\Carbon',
            'returnType' => '\Illuminate\Support\Carbon',
            'doc' => "Create a new Carbon instance for the current time.",
            'snippet' => "now()",
        ],
        'today' => [
            'signature' => 'today(?\DateTimeZone|string $tz = null): \Illuminate\Support\Carbon',
            'returnType' => '\Illuminate\Support\Carbon',
            'doc' => "Create a new Carbon instance for the current date at midnight.",
            'snippet' => "today()",
        ],
        'app' => [
            'signature' => 'app(?string $abstract = null, array $parameters = []): mixed|\Illuminate\Foundation\Application',
            'returnType' => 'mixed|\Illuminate\Foundation\Application',
            'doc' => "Get the available container instance or resolve a binding.\n\n```php\napp('db')\n```",
            'snippet' => "app('\${1:binding}')",
        ],
        'resolve' => [
            'signature' => 'resolve(string $name, array $parameters = []): mixed',
            'returnType' => 'mixed',
            'doc' => "Resolve a service from the container.",
            'snippet' => "resolve('\${1:name}')",
        ],
        'redirect' => [
            'signature' => 'redirect(?string $to = null, int $status = 302, array $headers = [], ?bool $secure = null): \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse',
            'returnType' => '\Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse',
            'doc' => "Get an instance of the redirector or create a redirect response.",
            'snippet' => "redirect('\${1:to}')",
        ],
        'back' => [
            'signature' => 'back(int $status = 302, array $headers = [], mixed $fallback = false): \Illuminate\Http\RedirectResponse',
            'returnType' => '\Illuminate\Http\RedirectResponse',
            'doc' => "Create a new redirect response to the previous location.",
            'snippet' => "back()",
        ],
        'abort' => [
            'signature' => 'abort(int $code, string $message = "", array $headers = []): never',
            'returnType' => 'never',
            'doc' => "Throw an HttpException with the given HTTP status code.",
            'snippet' => "abort(\${1:404})",
        ],
        'abort_if' => [
            'signature' => 'abort_if(bool $boolean, int $code, string $message = "", array $headers = []): void',
            'returnType' => 'void',
            'doc' => "Throw an HttpException with the given status code if the condition is true.",
            'snippet' => "abort_if(\${1:\$condition}, \${2:403})",
        ],
        'abort_unless' => [
            'signature' => 'abort_unless(bool $boolean, int $code, string $message = "", array $headers = []): void',
            'returnType' => 'void',
            'doc' => "Throw an HttpException with the given status code unless the condition is true.",
            'snippet' => "abort_unless(\${1:\$condition}, \${2:403})",
        ],
        'dump' => [
            'signature' => 'dump(mixed ...$vars): void',
            'returnType' => 'void',
            'doc' => "Dump the given variables and continue execution.",
            'snippet' => "dump(\${1:\$var})",
        ],
        'dd' => [
            'signature' => 'dd(mixed ...$vars): never',
            'returnType' => 'never',
            'doc' => "Dump the given variables and end the execution of the script.",
            'snippet' => "dd(\${1:\$var})",
        ],
        'logger' => [
            'signature' => 'logger(?string $message = null, array $context = []): \Illuminate\Log\LogManager|void',
            'returnType' => '\Illuminate\Log\LogManager|void',
            'doc' => "Log a debug message to the logs or return the logger instance.",
            'snippet' => "logger('\${1:message}')",
        ],
        'info' => [
            'signature' => 'info(string $message, array $context = []): void',
            'returnType' => 'void',
            'doc' => "Write information to the log.",
            'snippet' => "info('\${1:message}')",
        ],
        'event' => [
            'signature' => 'event(mixed ...$args): array|null',
            'returnType' => 'array|null',
            'doc' => "Dispatch an event and call the listeners.",
            'snippet' => "event(\${1:\$event})",
        ],
        'dispatch' => [
            'signature' => 'dispatch(mixed $job): \Illuminate\Foundation\Bus\PendingDispatch',
            'returnType' => '\Illuminate\Foundation\Bus\PendingDispatch',
            'doc' => "Dispatch a job to its appropriate handler.",
            'snippet' => "dispatch(\${1:\$job})",
        ],
        'broadcast' => [
            'signature' => 'broadcast(mixed $event = null): \Illuminate\Broadcasting\PendingBroadcast',
            'returnType' => '\Illuminate\Broadcasting\PendingBroadcast',
            'doc' => "Begin broadcasting an event.",
            'snippet' => "broadcast(\${1:\$event})",
        ],
        'bcrypt' => [
            'signature' => 'bcrypt(string $value, array $options = []): string',
            'returnType' => 'string',
            'doc' => "Hash the given value using bcrypt.",
            'snippet' => "bcrypt('\${1:password}')",
        ],
        'cache' => [
            'signature' => 'cache(array|string|null $key = null, mixed $default = null): mixed|\Illuminate\Cache\CacheManager',
            'returnType' => 'mixed|\Illuminate\Cache\CacheManager',
            'doc' => "Get / set the specified cache value or cache manager.",
            'snippet' => "cache('\${1:key}')",
        ],
        'cookie' => [
            'signature' => 'cookie(?string $name = null, ?string $value = null, int $minutes = 0, ?string $path = null, ?string $domain = null, ?bool $secure = null, bool $httpOnly = true, bool $raw = false, ?string $sameSite = null): \Symfony\Component\HttpFoundation\Cookie|\Illuminate\Cookie\CookieJar',
            'returnType' => '\Symfony\Component\HttpFoundation\Cookie|\Illuminate\Cookie\CookieJar',
            'doc' => "Create a new cookie instance or cookie jar.",
            'snippet' => "cookie('\${1:name}', '\${2:value}')",
        ],
        'validator' => [
            'signature' => 'validator(array $data = [], array $rules = [], array $messages = [], array $customAttributes = []): \Illuminate\Contracts\Validation\Validator|\Illuminate\Contracts\Validation\Factory',
            'returnType' => '\Illuminate\Contracts\Validation\Validator|\Illuminate\Contracts\Validation\Factory',
            'doc' => "Create a new Validator instance.",
            'snippet' => "validator(\${1:\$data}, \${2:\$rules})",
        ],
        'data_get' => [
            'signature' => 'data_get(mixed $target, string|array|int|null $key, mixed $default = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Get an item from an array or object using 'dot' notation.\n\n```php\ndata_get(\$user, 'profile.address.city')\n```",
            'snippet' => "data_get(\${1:\$target}, '\${2:key}')",
        ],
        'data_set' => [
            'signature' => 'data_set(mixed &$target, string|array $key, mixed $value, bool $overwrite = true): mixed',
            'returnType' => 'mixed',
            'doc' => "Set an item on an array or object using 'dot' notation.",
            'snippet' => "data_set(\${1:\$target}, '\${2:key}', \${3:\$value})",
        ],
        'str' => [
            'signature' => 'str(?string $string = null): \Illuminate\Support\Stringable|mixed',
            'returnType' => '\Illuminate\Support\Stringable|mixed',
            'doc' => "Get a new Stringable object from the given string.\n\n```php\nstr('hello world')->headline()\n```",
            'snippet' => "str(\${1:\$string})",
        ],
        'url' => [
            'signature' => 'url(?string $path = null, array $parameters = [], ?bool $secure = null): \Illuminate\Contracts\Routing\UrlGenerator|string',
            'returnType' => '\Illuminate\Contracts\Routing\UrlGenerator|string',
            'doc' => "Generate a url for the application.",
            'snippet' => "url('\${1:path}')",
        ],
        'action' => [
            'signature' => 'action(string|array $action, array $parameters = [], bool $absolute = true): string',
            'returnType' => 'string',
            'doc' => "Generate the URL to a controller action.",
            'snippet' => "action([\${1:Controller}::class, '\${2:method}'])",
        ],
        'vite' => [
            'signature' => 'vite(string|array $entrypoints, ?string $buildDirectory = null): \Illuminate\Support\HtmlString',
            'returnType' => '\Illuminate\Support\HtmlString',
            'doc' => "Support for Vite asset bundling in Blade views.",
            'snippet' => "vite(['\${1:resources/css/app.css}', '\${2:resources/js/app.js}'])",
        ],
        'mix' => [
            'signature' => 'mix(string $path, string $manifestDirectory = ""): \Illuminate\Support\HtmlString|string',
            'returnType' => '\Illuminate\Support\HtmlString|string',
            'doc' => "Get the path to a versioned Mix file.",
            'snippet' => "mix('\${1:css/app.css}')",
        ],
        'blank' => [
            'signature' => 'blank(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Determine if the given value is \"blank\".",
            'snippet' => "blank(\${1:\$value})",
        ],
        'filled' => [
            'signature' => 'filled(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Determine if the given value is \"filled\".",
            'snippet' => "filled(\${1:\$value})",
        ],
        'optional' => [
            'signature' => 'optional(mixed $value = null, ?callable $callback = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Provide access to optional objects.\n\n```php\noptional(\$user)->name\n```",
            'snippet' => "optional(\${1:\$value})",
        ],
        'tap' => [
            'signature' => 'tap(mixed $value, ?callable $callback = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Call the given Closure with the given value then return the value.",
            'snippet' => "tap(\${1:\$value}, function (\$item) {\n\t\${2}\n})",
        ],
        'value' => [
            'signature' => 'value(mixed $value, mixed ...$args): mixed',
            'returnType' => 'mixed',
            'doc' => "Return the default value of the given value or result of closure.",
            'snippet' => "value(\${1:\$value})",
        ],
        'with' => [
            'signature' => 'with(mixed $value, ?callable $callback = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Return the given value, optionally passed to the given callback.",
            'snippet' => "with(\${1:\$value}, fn (\$v) => \${2})",
        ],
        'transform' => [
            'signature' => 'transform(mixed $value, callable $callback, mixed $default = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Transform the given value if it is present.",
            'snippet' => "transform(\${1:\$value}, fn (\$v) => \${2})",
        ],
        'rescue' => [
            'signature' => 'rescue(callable $callback, mixed $rescue = null, bool|callable $report = true): mixed',
            'returnType' => 'mixed',
            'doc' => "Catch a potential exception and return a default value.",
            'snippet' => "rescue(fn () => \${1})",
        ],
        'retry' => [
            'signature' => 'retry(int|array $times, callable $callback, int|\Closure $sleepMilliseconds = 0, ?callable $when = null): mixed',
            'returnType' => 'mixed',
            'doc' => "Retry an operation a given number of times.",
            'snippet' => "retry(\${1:3}, fn () => \${2})",
        ],
        'throw_if' => [
            'signature' => 'throw_if(mixed $boolean, Throwable|string $exception = "RuntimeException", mixed ...$parameters): mixed',
            'returnType' => 'mixed',
            'doc' => "Throw the given exception if the given condition is true.",
            'snippet' => "throw_if(\${1:\$condition}, \${2:Exception}::class)",
        ],
        'throw_unless' => [
            'signature' => 'throw_unless(mixed $boolean, Throwable|string $exception = "RuntimeException", mixed ...$parameters): mixed',
            'returnType' => 'mixed',
            'doc' => "Throw the given exception unless the given condition is true.",
            'snippet' => "throw_unless(\${1:\$condition}, \${2:Exception}::class)",
        ],
        'public_path' => [
            'signature' => 'public_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the public folder.",
            'snippet' => "public_path('\${1}')",
        ],
        'base_path' => [
            'signature' => 'base_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the base of the install.",
            'snippet' => "base_path('\${1}')",
        ],
        'app_path' => [
            'signature' => 'app_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the application folder.",
            'snippet' => "app_path('\${1}')",
        ],
        'config_path' => [
            'signature' => 'config_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the configuration folder.",
            'snippet' => "config_path('\${1}')",
        ],
        'database_path' => [
            'signature' => 'database_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the database folder.",
            'snippet' => "database_path('\${1}')",
        ],
        'resource_path' => [
            'signature' => 'resource_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the resources folder.",
            'snippet' => "resource_path('\${1}')",
        ],
        'storage_path' => [
            'signature' => 'storage_path(string $path = ""): string',
            'returnType' => 'string',
            'doc' => "Get the path to the storage folder.",
            'snippet' => "storage_path('\${1}')",
        ],
        'class_basename' => [
            'signature' => 'class_basename(string|object $class): string',
            'returnType' => 'string',
            'doc' => "Get the class \"basename\" of the given object / class.",
            'snippet' => "class_basename(\${1:\$class})",
        ],
    ];

    /**
     * Standard PHP built-in functions commonly used in templates.
     *
     * @var array<string, array{signature: string, returnType: string, doc: string, snippet?: string}>
     */
    protected static array $phpBuiltinFunctions = [
        'count' => [
            'signature' => 'count(Countable|array $value, int $mode = COUNT_NORMAL): int',
            'returnType' => 'int',
            'doc' => "Count all elements in an array or countable object.",
            'snippet' => "count(\${1:\$items})",
        ],
        'empty' => [
            'signature' => 'empty(mixed $var): bool',
            'returnType' => 'bool',
            'doc' => "Determine whether a variable is empty.",
            'snippet' => "empty(\${1:\$var})",
        ],
        'isset' => [
            'signature' => 'isset(mixed ...$vars): bool',
            'returnType' => 'bool',
            'doc' => "Determine if a variable is declared and is different than null.",
            'snippet' => "isset(\${1:\$var})",
        ],
        'in_array' => [
            'signature' => 'in_array(mixed $needle, array $haystack, bool $strict = false): bool',
            'returnType' => 'bool',
            'doc' => "Checks if a value exists in an array.",
            'snippet' => "in_array(\${1:\$needle}, \${2:\$haystack})",
        ],
        'is_array' => [
            'signature' => 'is_array(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Finds whether a variable is an array.",
            'snippet' => "is_array(\${1:\$value})",
        ],
        'is_null' => [
            'signature' => 'is_null(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Finds whether a variable is null.",
            'snippet' => "is_null(\${1:\$value})",
        ],
        'is_numeric' => [
            'signature' => 'is_numeric(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Finds whether a variable is a number or a numeric string.",
            'snippet' => "is_numeric(\${1:\$value})",
        ],
        'is_string' => [
            'signature' => 'is_string(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Finds whether the type of a variable is string.",
            'snippet' => "is_string(\${1:\$value})",
        ],
        'is_bool' => [
            'signature' => 'is_bool(mixed $value): bool',
            'returnType' => 'bool',
            'doc' => "Finds out whether a variable is a boolean.",
            'snippet' => "is_bool(\${1:\$value})",
        ],
        'array_key_exists' => [
            'signature' => 'array_key_exists(string|int $key, array $array): bool',
            'returnType' => 'bool',
            'doc' => "Checks if the given key or index exists in the array.",
            'snippet' => "array_key_exists('\${1:key}', \${2:\$array})",
        ],
        'array_keys' => [
            'signature' => 'array_keys(array $array): array',
            'returnType' => 'array',
            'doc' => "Return all the keys or a subset of the keys of an array.",
            'snippet' => "array_keys(\${1:\$array})",
        ],
        'array_values' => [
            'signature' => 'array_values(array $array): array',
            'returnType' => 'array',
            'doc' => "Return all the values of an array.",
            'snippet' => "array_values(\${1:\$array})",
        ],
        'array_merge' => [
            'signature' => 'array_merge(array ...$arrays): array',
            'returnType' => 'array',
            'doc' => "Merge one or more arrays.",
            'snippet' => "array_merge(\${1:\$array1}, \${2:\$array2})",
        ],
        'array_map' => [
            'signature' => 'array_map(?callable $callback, array $array, array ...$arrays): array',
            'returnType' => 'array',
            'doc' => "Applies the callback to the elements of the given arrays.",
            'snippet' => "array_map(fn (\$item) => \${2}, \${1:\$array})",
        ],
        'array_filter' => [
            'signature' => 'array_filter(array $array, ?callable $callback = null, int $mode = 0): array',
            'returnType' => 'array',
            'doc' => "Filters elements of an array using a callback function.",
            'snippet' => "array_filter(\${1:\$array})",
        ],
        'array_column' => [
            'signature' => 'array_column(array $array, int|string|null $column_key, int|string|null $index_key = null): array',
            'returnType' => 'array',
            'doc' => "Return the values from a single column in the input array.",
            'snippet' => "array_column(\${1:\$array}, '\${2:column}')",
        ],
        'array_unique' => [
            'signature' => 'array_unique(array $array, int $flags = SORT_STRING): array',
            'returnType' => 'array',
            'doc' => "Removes duplicate values from an array.",
            'snippet' => "array_unique(\${1:\$array})",
        ],
        'explode' => [
            'signature' => 'explode(string $separator, string $string, int $limit = PHP_INT_MAX): array',
            'returnType' => 'array',
            'doc' => "Split a string by a string.",
            'snippet' => "explode('\${1:,}', \${2:\$string})",
        ],
        'implode' => [
            'signature' => 'implode(string|array $separator, ?array $array = null): string',
            'returnType' => 'string',
            'doc' => "Join array elements with a string.",
            'snippet' => "implode('\${1:, }', \${2:\$array})",
        ],
        'str_contains' => [
            'signature' => 'str_contains(string $haystack, string $needle): bool',
            'returnType' => 'bool',
            'doc' => "Determine if a string contains a given substring.",
            'snippet' => "str_contains(\${1:\$haystack}, '\${2:needle}')",
        ],
        'str_starts_with' => [
            'signature' => 'str_starts_with(string $haystack, string $needle): bool',
            'returnType' => 'bool',
            'doc' => "Checks if a string starts with a given substring.",
            'snippet' => "str_starts_with(\${1:\$haystack}, '\${2:needle}')",
        ],
        'str_ends_with' => [
            'signature' => 'str_ends_with(string $haystack, string $needle): bool',
            'returnType' => 'bool',
            'doc' => "Checks if a string ends with a given substring.",
            'snippet' => "str_ends_with(\${1:\$haystack}, '\${2:needle}')",
        ],
        'strlen' => [
            'signature' => 'strlen(string $string): int',
            'returnType' => 'int',
            'doc' => "Get string length.",
            'snippet' => "strlen(\${1:\$string})",
        ],
        'substr' => [
            'signature' => 'substr(string $string, int $offset, ?int $length = null): string',
            'returnType' => 'string',
            'doc' => "Return part of a string.",
            'snippet' => "substr(\${1:\$string}, \${2:0}, \${3:10})",
        ],
        'str_replace' => [
            'signature' => 'str_replace(string|array $search, string|array $replace, string|array $subject, int &$count = null): string|array',
            'returnType' => 'string|array',
            'doc' => "Replace all occurrences of the search string with the replacement string.",
            'snippet' => "str_replace('\${1:search}', '\${2:replace}', \${3:\$subject})",
        ],
        'strtolower' => [
            'signature' => 'strtolower(string $string): string',
            'returnType' => 'string',
            'doc' => "Make a string lowercase.",
            'snippet' => "strtolower(\${1:\$string})",
        ],
        'strtoupper' => [
            'signature' => 'strtoupper(string $string): string',
            'returnType' => 'string',
            'doc' => "Make a string uppercase.",
            'snippet' => "strtoupper(\${1:\$string})",
        ],
        'ucfirst' => [
            'signature' => 'ucfirst(string $string): string',
            'returnType' => 'string',
            'doc' => "Make a string's first character uppercase.",
            'snippet' => "ucfirst(\${1:\$string})",
        ],
        'trim' => [
            'signature' => 'trim(string $string, string $characters = " \\n\\r\\t\\v\\x00"): string',
            'returnType' => 'string',
            'doc' => "Strip whitespace (or other characters) from the beginning and end of a string.",
            'snippet' => "trim(\${1:\$string})",
        ],
        'ltrim' => [
            'signature' => 'ltrim(string $string, string $characters = " \\n\\r\\t\\v\\x00"): string',
            'returnType' => 'string',
            'doc' => "Strip whitespace (or other characters) from the beginning of a string.",
            'snippet' => "ltrim(\${1:\$string})",
        ],
        'rtrim' => [
            'signature' => 'rtrim(string $string, string $characters = " \\n\\r\\t\\v\\x00"): string',
            'returnType' => 'string',
            'doc' => "Strip whitespace (or other characters) from the end of a string.",
            'snippet' => "rtrim(\${1:\$string})",
        ],
        'sprintf' => [
            'signature' => 'sprintf(string $format, mixed ...$values): string',
            'returnType' => 'string',
            'doc' => "Return a formatted string.",
            'snippet' => "sprintf('\${1:%s}', \${2:\$value})",
        ],
        'number_format' => [
            'signature' => 'number_format(float $num, int $decimals = 0, ?string $decimal_separator = ".", ?string $thousands_separator = ","): string',
            'returnType' => 'string',
            'doc' => "Format a number with grouped thousands.",
            'snippet' => "number_format(\${1:\$num}, \${2:2})",
        ],
        'round' => [
            'signature' => 'round(int|float $num, int $precision = 0, int $mode = PHP_ROUND_HALF_UP): float',
            'returnType' => 'float',
            'doc' => "Returns the rounded value of num to specified precision.",
            'snippet' => "round(\${1:\$num}, \${2:2})",
        ],
        'ceil' => [
            'signature' => 'ceil(int|float $num): float',
            'returnType' => 'float',
            'doc' => "Round fractions up.",
            'snippet' => "ceil(\${1:\$num})",
        ],
        'floor' => [
            'signature' => 'floor(int|float $num): float',
            'returnType' => 'float',
            'doc' => "Round fractions down.",
            'snippet' => "floor(\${1:\$num})",
        ],
        'abs' => [
            'signature' => 'abs(int|float $num): int|float',
            'returnType' => 'int|float',
            'doc' => "Absolute value.",
            'snippet' => "abs(\${1:\$num})",
        ],
        'min' => [
            'signature' => 'min(mixed ...$values): mixed',
            'returnType' => 'mixed',
            'doc' => "Find lowest value.",
            'snippet' => "min(\${1:\$values})",
        ],
        'max' => [
            'signature' => 'max(mixed ...$values): mixed',
            'returnType' => 'mixed',
            'doc' => "Find highest value.",
            'snippet' => "max(\${1:\$values})",
        ],
        'date' => [
            'signature' => 'date(string $format, ?int $timestamp = null): string',
            'returnType' => 'string',
            'doc' => "Format a Unix timestamp.",
            'snippet' => "date('\${1:Y-m-d H:i:s}')",
        ],
        'time' => [
            'signature' => 'time(): int',
            'returnType' => 'int',
            'doc' => "Return current Unix timestamp.",
            'snippet' => "time()",
        ],
        'strtotime' => [
            'signature' => 'strtotime(string $datetime, ?int $baseTimestamp = null): int|false',
            'returnType' => 'int|false',
            'doc' => "Parse about any English textual datetime description into a Unix timestamp.",
            'snippet' => "strtotime('\${1:+1 day}')",
        ],
        'json_encode' => [
            'signature' => 'json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false',
            'returnType' => 'string|false',
            'doc' => "Returns the JSON representation of a value.",
            'snippet' => "json_encode(\${1:\$value})",
        ],
        'json_decode' => [
            'signature' => 'json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed',
            'returnType' => 'mixed',
            'doc' => "Takes a JSON encoded string and converts it into a PHP variable.",
            'snippet' => "json_decode(\${1:\$json}, true)",
        ],
        'compact' => [
            'signature' => 'compact(array|string ...$var_names): array',
            'returnType' => 'array',
            'doc' => "Create array containing variables and their values.",
            'snippet' => "compact('\${1:var}')",
        ],
        'var_dump' => [
            'signature' => 'var_dump(mixed ...$vars): void',
            'returnType' => 'void',
            'doc' => "Dumps information about a variable.",
            'snippet' => "var_dump(\${1:\$var})",
        ],
    ];

    public function __construct(
        protected ?Project $project = null,
    ) {}

    /**
     * Get all known functions (Laravel helpers, PHP builtins, and project user functions).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $functions = self::$laravelHelpers;

        foreach (self::$phpBuiltinFunctions as $name => $info) {
            if (!isset($functions[$name])) {
                $functions[$name] = $info;
            }
        }

        foreach ($this->getUserDefinedFunctions() as $name => $info) {
            $functions[$name] = $info;
        }

        return $functions;
    }

    /**
     * Check if a function is defined / known.
     */
    public function has(string $name): bool
    {
        $clean = strtolower(ltrim($name, '\\'));
        return isset(self::$laravelHelpers[$clean])
            || isset(self::$phpBuiltinFunctions[$clean])
            || isset($this->getUserDefinedFunctions()[$clean])
            || function_exists($clean);
    }

    /**
     * Resolve function details for hover or definition.
     *
     * @return array{name: string, signature: string, returnType: string, doc: string, snippet: string, source?: string, line?: int}|null
     */
    public function get(string $name): ?array
    {
        $clean = strtolower(ltrim($name, '\\'));

        if (isset(self::$laravelHelpers[$clean])) {
            $info = self::$laravelHelpers[$clean];
            return [
                'name' => $clean,
                'signature' => $info['signature'],
                'returnType' => $info['returnType'],
                'doc' => $info['doc'],
                'snippet' => $info['snippet'] ?? "{$clean}(\${1})",
            ];
        }

        if (isset(self::$phpBuiltinFunctions[$clean])) {
            $info = self::$phpBuiltinFunctions[$clean];
            return [
                'name' => $clean,
                'signature' => $info['signature'],
                'returnType' => $info['returnType'],
                'doc' => $info['doc'],
                'snippet' => $info['snippet'] ?? "{$clean}(\${1})",
            ];
        }

        $userFuncs = $this->getUserDefinedFunctions();
        if (isset($userFuncs[$clean])) {
            return $userFuncs[$clean];
        }

        if (function_exists($clean)) {
            try {
                $ref = new ReflectionFunction($clean);
                $sig = $this->formatReflectionFunctionSignature($ref);
                $ret = $ref->hasReturnType() ? (string) $ref->getReturnType() : 'mixed';
                $fn = $ref->getFileName() ?: null;
                $line = $ref->getStartLine() ?: 1;

                return [
                    'name' => $clean,
                    'signature' => "{$clean}{$sig}: {$ret}",
                    'returnType' => $ret,
                    'doc' => "User defined function `{$clean}`",
                    'snippet' => "{$clean}(\${1})",
                    'source' => $fn,
                    'line' => $line,
                ];
            } catch (Throwable) {}
        }

        return null;
    }

    /**
     * Get completion items matching a prefix.
     *
     * @return array<int, array<string, mixed>>
     */
    public function completions(string $prefix = '', ?array $range = null): array
    {
        $items = [];
        $lowPrefix = strtolower($prefix);

        foreach ($this->all() as $name => $info) {
            if ($lowPrefix !== '' && !str_starts_with(strtolower($name), $lowPrefix)) {
                continue;
            }

            $snippet = $info['snippet'] ?? "{$name}(\${1})";
            $sig = $info['signature'] ?? "{$name}()";
            $doc = $info['doc'] ?? '';
            $origin = isset($info['source']) ? 'User Helper' : (isset(self::$laravelHelpers[$name]) ? 'Laravel Helper' : 'PHP Builtin');

            $item = [
                'label' => "{$name}()",
                'kind' => 3, // Function
                'detail' => $sig,
                'documentation' => [
                    'kind' => 'markdown',
                    'value' => "### `{$sig}`\n\n{$doc}\n\n*Origin:* `{$origin}`",
                ],
                'insertText' => $snippet,
                'insertTextFormat' => 2, // Snippet
            ];

            if ($range !== null) {
                $item['textEdit'] = [
                    'range' => $range,
                    'newText' => $snippet,
                ];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Scan user project for custom helper functions.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getUserDefinedFunctions(): array
    {
        if ($this->userFunctionsCache !== null) {
            return $this->userFunctionsCache;
        }

        $this->userFunctionsCache = [];
        if ($this->project === null) {
            return $this->userFunctionsCache;
        }

        $basePath = rtrim($this->project->path(), '/\\');

        // 1. Scan composer.json for autoload files
        $helperFiles = [
            "{$basePath}/app/helpers.php",
            "{$basePath}/app/Helpers/helpers.php",
            "{$basePath}/app/Support/helpers.php",
        ];

        $composerJsonPath = "{$basePath}/composer.json";
        if (file_exists($composerJsonPath)) {
            try {
                $json = json_decode((string) file_get_contents($composerJsonPath), true);
                foreach (['autoload', 'autoload-dev'] as $section) {
                    if (!empty($json[$section]['files']) && is_array($json[$section]['files'])) {
                        foreach ($json[$section]['files'] as $f) {
                            $full = "{$basePath}/" . ltrim((string) $f, '/\\');
                            if (file_exists($full) && !in_array($full, $helperFiles, true)) {
                                $helperFiles[] = $full;
                            }
                        }
                    }
                }
            } catch (Throwable) {}
        }

        // Also check app/Helpers directory
        $helpersDir = "{$basePath}/app/Helpers";
        if (is_dir($helpersDir)) {
            $scanned = @scandir($helpersDir) ?: [];
            foreach ($scanned as $f) {
                if (str_ends_with($f, '.php')) {
                    $full = "{$helpersDir}/{$f}";
                    if (file_exists($full) && !in_array($full, $helperFiles, true)) {
                        $helperFiles[] = $full;
                    }
                }
            }
        }

        // 2. Parse functions from helper files
        foreach ($helperFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $content = @file_get_contents($file);
            if (!$content) {
                continue;
            }

            $lines = explode("\n", $content);
            $pattern = '/function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(([^)]*)\)(?:\s*:\s*([a-zA-Z0-9_\\\\?|&]+))?/i';

            foreach ($lines as $lineIndex => $lineText) {
                if (preg_match($pattern, $lineText, $m)) {
                    $fnName = $m[1];
                    $params = trim($m[2]);
                    $returnType = !empty($m[3]) ? trim($m[3]) : 'mixed';
                    $sig = "{$fnName}({$params}): {$returnType}";

                    // Extract docblock preceding the function
                    $doc = '';
                    $backIdx = $lineIndex - 1;
                    $docLines = [];
                    while ($backIdx >= 0 && (str_contains($lines[$backIdx], '*') || str_contains($lines[$backIdx], '/*'))) {
                        $docLines[] = trim($lines[$backIdx]);
                        if (str_contains($lines[$backIdx], '/**')) {
                            break;
                        }
                        $backIdx--;
                    }
                    if (!empty($docLines)) {
                        $doc = implode("\n", array_reverse($docLines));
                    }

                    $this->userFunctionsCache[strtolower($fnName)] = [
                        'name' => $fnName,
                        'signature' => $sig,
                        'returnType' => $returnType,
                        'doc' => $doc ?: "Custom helper function `{$fnName}`",
                        'snippet' => "{$fnName}(\${1})",
                        'source' => $file,
                        'line' => $lineIndex + 1,
                    ];
                }
            }
        }

        return $this->userFunctionsCache;
    }

    protected function formatReflectionFunctionSignature(ReflectionFunction $ref): string
    {
        $params = [];
        foreach ($ref->getParameters() as $p) {
            $pType = $p->getType() ? (string) $p->getType() . ' ' : '';
            $pStr = "{$pType}\${$p->getName()}";
            if ($p->isDefaultValueAvailable()) {
                $default = $p->getDefaultValue();
                $pStr .= ' = ' . (is_array($default) ? '[]' : (is_null($default) ? 'null' : (is_bool($default) ? ($default ? 'true' : 'false') : (is_string($default) ? "'{$default}'" : (string) $default))));
            }
            $params[] = $pStr;
        }

        return '(' . implode(', ', $params) . ')';
    }
}
