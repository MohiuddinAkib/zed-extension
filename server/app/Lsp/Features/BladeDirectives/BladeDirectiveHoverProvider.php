<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeDirectives;

use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Features\AppBindings\AppBindingContainerTypeMap;
use App\Lsp\Features\ClassIndex\ClassRegistry;
use App\Lsp\Project;
use ReflectionClass;
use Throwable;

class BladeDirectiveHoverProvider implements HoverProvider
{
    /**
     * Directive metadata specifications.
     *
     * @var array<string, array{
     *     signature: string,
     *     summary: string,
     *     parameters: array<string, array{type: string, optional?: bool, default?: string, description: string}>,
     *     exceptions?: array<string, string>,
     *     notes?: string,
     *     example?: string
     * }>
     */
    protected const DIRECTIVE_METADATA = [
        'use' => [
            'signature' => '@use(string $class, ?string $as = null)',
            'summary' => 'Imports a PHP class, interface, enum, trait, or facade into the Blade template scope.',
            'parameters' => [
                'class' => ['type' => 'string|class-string', 'description' => 'Fully qualified class name or namespace to import (e.g. `App\Models\User`, `Illuminate\Support\Str`).'],
                'as' => ['type' => '?string', 'optional' => true, 'default' => 'null', 'description' => 'Optional alias name to use within this template (e.g. `UserAlias`). Defaults to class basename.'],
            ],
            'notes' => 'Available in Laravel 10.x and 11.x. Allows referencing static methods, constants, or types directly without full namespace.',
            'example' => "@use('App\Models\User')\n@use('App\Enums\OrderStatus', 'Status')\n\n{{ User::count() }}\n{{ Status::Pending->name }}",
        ],
        'inject' => [
            'signature' => '@inject(string $variable, string|class-string $service)',
            'summary' => 'Injects a service or dependency from the Laravel service container into the Blade template.',
            'parameters' => [
                'variable' => ['type' => 'string', 'description' => 'The local variable name to assign the resolved service to in template scope.'],
                'service' => ['type' => 'string|class-string', 'description' => 'Container service binding key (e.g. `\'db\'`, `\'auth\'`, `\'metrics\'`) or fully qualified class/contract name (e.g. `App\Services\MetricsService::class`).'],
            ],
            'exceptions' => [
                '\Illuminate\Contracts\Container\BindingResolutionException' => 'Thrown if the target service or class cannot be resolved from the container.',
                '\Illuminate\Contracts\Container\CircularDependencyException' => 'Thrown if resolving the service triggers a circular dependency chain.',
            ],
            'notes' => 'Resolved via `app($service)`. The injected variable is immediately accessible across the entire Blade template.',
            'example' => "@inject('metrics', 'App\Services\MetricsService')\n@inject('db', 'db')\n\n<div>Monthly Revenue: {{ \$metrics->monthlyRevenue() }}</div>",
        ],
        'include' => [
            'signature' => '@include(string $view, array $data = [], array $mergeData = [])',
            'summary' => 'Evaluates and includes another Blade view template into the current template.',
            'parameters' => [
                'view' => ['type' => 'string', 'description' => 'The name of the Blade view to include (e.g. `\'shared.errors\'`, `\'partials.header\'`).'],
                'data' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Associative array of additional variables to pass to the view.'],
                'mergeData' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Array of data to merge into current scope.'],
            ],
            'exceptions' => [
                '\InvalidArgumentException' => 'Thrown when the view does not exist. Use `@includeIf` or `@includeFirst` for optional views.',
            ],
            'notes' => 'All variables available to the parent view are automatically inherited by the included view unless overridden.',
            'example' => "@include('partials.alert', ['type' => 'danger', 'message' => \$error])",
        ],
        'includeif' => [
            'signature' => '@includeIf(string $view, array $data = [], array $mergeData = [])',
            'summary' => 'Includes a Blade view template if it exists, without throwing an exception if not found.',
            'parameters' => [
                'view' => ['type' => 'string', 'description' => 'The name of the Blade view to include.'],
                'data' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Variables to pass.'],
            ],
            'notes' => 'Silently skips rendering if the view template file does not exist.',
            'example' => "@includeIf('custom.header', ['title' => \$title])",
        ],
        'includewhen' => [
            'signature' => '@includeWhen(bool $condition, string $view, array $data = [], array $mergeData = [])',
            'summary' => 'Includes a Blade view template only when the given boolean condition evaluates to true.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
                'view' => ['type' => 'string', 'description' => 'The view name.'],
                'data' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Variables to pass.'],
            ],
            'example' => "@includeWhen(\$user->isAdmin(), 'admin.toolbar')",
        ],
        'includeunless' => [
            'signature' => '@includeUnless(bool $condition, string $view, array $data = [], array $mergeData = [])',
            'summary' => 'Includes a Blade view template unless the given boolean condition evaluates to true.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
                'view' => ['type' => 'string', 'description' => 'The view name.'],
                'data' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Variables to pass.'],
            ],
            'example' => "@includeUnless(\$user->isBanned(), 'comments.form')",
        ],
        'includefirst' => [
            'signature' => '@includeFirst(array<string> $views, array $data = [], array $mergeData = [])',
            'summary' => 'Includes the first view template from an array of view names that exists on disk.',
            'parameters' => [
                'views' => ['type' => 'array<string>', 'description' => 'Ordered list of view names to probe.'],
                'data' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Variables to pass.'],
            ],
            'exceptions' => [
                '\InvalidArgumentException' => 'Thrown if none of the specified view templates exist.',
            ],
            'example' => "@includeFirst(['custom.header', 'default.header'], ['title' => \$title])",
        ],
        'each' => [
            'signature' => '@each(string $view, iterable $collection, string $as, string $empty = \'raw|view\')',
            'summary' => 'Renders a view for each element in an iterable collection or array.',
            'parameters' => [
                'view' => ['type' => 'string', 'description' => 'Partial view template to render for each item.'],
                'collection' => ['type' => 'iterable|array|\Illuminate\Support\Collection', 'description' => 'The collection or array to iterate over.'],
                'as' => ['type' => 'string', 'description' => 'Variable name assigned to the current item in the partial view scope.'],
                'empty' => ['type' => 'string', 'optional' => true, 'default' => '\'raw|view\'', 'description' => 'Optional view to render if the collection is empty.'],
            ],
            'example' => "@each('users.row', \$users, 'user', 'users.empty')",
        ],
        'extends' => [
            'signature' => '@extends(string $layout)',
            'summary' => 'Specifies the parent layout template that this view inherits and extends.',
            'parameters' => [
                'layout' => ['type' => 'string', 'description' => 'The parent layout view name (e.g. `\'layouts.app\'`, `\'components.layout\'`).'],
            ],
            'exceptions' => [
                '\InvalidArgumentException' => 'Thrown if the extended layout view cannot be found.',
            ],
            'example' => "@extends('layouts.app')",
        ],
        'section' => [
            'signature' => '@section(string $name, ?string $content = null)',
            'summary' => 'Defines a template section block to be injected into the layout via `@yield` or parent section via `@parent`.',
            'parameters' => [
                'name' => ['type' => 'string', 'description' => 'The unique section name (e.g. `\'title\'`, `\'content\'`, `\'scripts\'`).'],
                'content' => ['type' => '?string', 'optional' => true, 'default' => 'null', 'description' => 'Short inline string content. If omitted, close the section with `@endsection` or `@show`.'],
            ],
            'example' => "@section('title', 'Page Title')\n\n@section('content')\n    <h1>Welcome</h1>\n@endsection",
        ],
        'yield' => [
            'signature' => '@yield(string $section, mixed $default = \'\')',
            'summary' => 'Outputs the content of a named section defined by a child view extending this layout.',
            'parameters' => [
                'section' => ['type' => 'string', 'description' => 'The section name to output.'],
                'default' => ['type' => 'mixed', 'optional' => true, 'default' => '\'\'', 'description' => 'Default fallback content to render if section is not defined.'],
            ],
            'example' => "<title>@yield('title', config('app.name'))</title>",
        ],
        'props' => [
            'signature' => '@props(array $props)',
            'summary' => 'Defines accepted component properties and their default values in a Blade component template.',
            'parameters' => [
                'props' => ['type' => 'array', 'description' => 'Associative array of prop names with default values (e.g. `[\'type\' => \'info\', \'dismissible\' => false]`).'],
            ],
            'notes' => 'Prop values passed to the component tag will populate these variables. Non-prop attributes remain in `$attributes`.',
            'example' => "@props(['type' => 'info', 'message' => ''])",
        ],
        'aware' => [
            'signature' => '@aware(array $props)',
            'summary' => 'Accesses props and state passed to a parent component from a nested child component.',
            'parameters' => [
                'props' => ['type' => 'array', 'description' => 'Array of prop names or defaults to inherit from parent.'],
            ],
            'example' => "@aware(['color' => 'gray'])",
        ],
        'error' => [
            'signature' => '@error(string $key, string $bag = \'default\')',
            'summary' => 'Directly checks if validation error messages exist for a specific form input key.',
            'parameters' => [
                'key' => ['type' => 'string', 'description' => 'The form input field name or validation error key (e.g. `\'email\'`, `\'password\'`).'],
                'bag' => ['type' => 'string', 'optional' => true, 'default' => '\'default\'', 'description' => 'Named error bag.'],
            ],
            'notes' => 'Inside the `@error` block, the `$message` variable is automatically bound to the error text.',
            'example' => "@error('email')\n    <div class=\"alert alert-danger\">{{ \$message }}</div>\n@enderror",
        ],
        'push' => [
            'signature' => '@push(string $name)',
            'summary' => 'Pushes content onto the end of a named stack to be rendered elsewhere via `@stack`.',
            'parameters' => [
                'name' => ['type' => 'string', 'description' => 'The stack name (e.g. `\'scripts\'`, `\'styles\'`, `\'modals\'`).'],
            ],
            'example' => "@push('scripts')\n    <script src=\"/example.js\"></script>\n@endpush",
        ],
        'stack' => [
            'signature' => '@stack(string $name)',
            'summary' => 'Renders the entire concatenated contents of a named stack.',
            'parameters' => [
                'name' => ['type' => 'string', 'description' => 'The stack name to render.'],
            ],
            'example' => "@stack('scripts')",
        ],
        'can' => [
            'signature' => '@can(string $ability, mixed $arguments = [])',
            'summary' => 'Conditionally renders content if the authenticated user is authorized for the given ability.',
            'parameters' => [
                'ability' => ['type' => 'string', 'description' => 'Gate or Policy ability name (e.g. `\'update\'`, `\'delete\'`, `\'manage-users\'`).'],
                'arguments' => ['type' => 'mixed', 'optional' => true, 'default' => '[]', 'description' => 'Model instance(s) or parameters to pass to the policy check.'],
            ],
            'example' => "@can('update', \$post)\n    <a href=\"/edit\">Edit Post</a>\n@endcan",
        ],
        'cannot' => [
            'signature' => '@cannot(string $ability, mixed $arguments = [])',
            'summary' => 'Conditionally renders content if the authenticated user is NOT authorized for the given ability.',
            'parameters' => [
                'ability' => ['type' => 'string', 'description' => 'Gate or Policy ability name.'],
                'arguments' => ['type' => 'mixed', 'optional' => true, 'default' => '[]', 'description' => 'Arguments/models to pass.'],
            ],
            'example' => "@cannot('update', \$post)\n    <span>Read-only</span>\n@endcannot",
        ],
        'checked' => [
            'signature' => '@checked(bool $condition)',
            'summary' => 'Conditionally outputs the HTML `checked="checked"` attribute if the condition is truthy.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
            ],
            'example' => '<input type="checkbox" name="active" @checked(old("active", $user->active)) />',
        ],
        'selected' => [
            'signature' => '@selected(bool $condition)',
            'summary' => 'Conditionally outputs the HTML `selected="selected"` attribute if the condition is truthy.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
            ],
            'example' => '<option value="admin" @selected($user->role === "admin")>Admin</option>',
        ],
        'disabled' => [
            'signature' => '@disabled(bool $condition)',
            'summary' => 'Conditionally outputs the HTML `disabled` attribute if the condition is truthy.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
            ],
            'example' => '<button type="submit" @disabled($errors->isNotEmpty())>Submit</button>',
        ],
        'readonly' => [
            'signature' => '@readonly(bool $condition)',
            'summary' => 'Conditionally outputs the HTML `readonly` attribute if the condition is truthy.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
            ],
            'example' => '<input type="text" name="email" value="{{ $email }}" @readonly($isLocked) />',
        ],
        'required' => [
            'signature' => '@required(bool $condition)',
            'summary' => 'Conditionally outputs the HTML `required` attribute if the condition is truthy.',
            'parameters' => [
                'condition' => ['type' => 'bool', 'description' => 'Boolean condition expression.'],
            ],
            'example' => '<input type="password" name="password" @required($mustChangePassword) />',
        ],
        'class' => [
            'signature' => '@class(array<string, bool>|string $classes)',
            'summary' => 'Conditionally compiles and outputs a string of CSS class names based on truthy array keys.',
            'parameters' => [
                'classes' => ['type' => 'array<string, bool>|string', 'description' => 'Associative array where key is the CSS class and value is a boolean condition.'],
            ],
            'example' => '<div @class(["p-4", "font-bold" => $isActive, "bg-red-500" => $hasError])></div>',
        ],
        'style' => [
            'signature' => '@style(array<string, bool>|string $styles)',
            'summary' => 'Conditionally compiles and outputs an inline CSS `style` attribute.',
            'parameters' => [
                'styles' => ['type' => 'array<string, bool>|string', 'description' => 'Associative array of CSS styles and conditions.'],
            ],
            'example' => '<div @style(["background-color: red" => $hasError, "font-weight: bold"])></div>',
        ],
        'json' => [
            'signature' => '@json(mixed $data, int $options = 0, int $depth = 512)',
            'summary' => 'Encodes data into a JSON string safely formatted for inline JavaScript tags or HTML attributes.',
            'parameters' => [
                'data' => ['type' => 'mixed', 'description' => 'Data or model to encode.'],
                'options' => ['type' => 'int', 'optional' => true, 'default' => '0', 'description' => 'Bitmask of `JSON_*` options.'],
                'depth' => ['type' => 'int', 'optional' => true, 'default' => '512', 'description' => 'Maximum depth.'],
            ],
            'exceptions' => [
                '\JsonException' => 'Thrown when JSON encoding fails if `JSON_THROW_ON_ERROR` is passed in options.',
            ],
            'example' => '<script>window.user = @json($user);</script>',
        ],
        'vite' => [
            'signature' => '@vite(string|array $entrypoints, ?string $buildDirectory = null)',
            'summary' => 'Generates HTML script and link tags for Vite asset entrypoints with Hot Module Replacement (HMR) in local development.',
            'parameters' => [
                'entrypoints' => ['type' => 'string|array', 'description' => 'Asset entrypoint path(s) (e.g. `[\'resources/css/app.css\', \'resources/js/app.js\']`).'],
                'buildDirectory' => ['type' => '?string', 'optional' => true, 'default' => 'null', 'description' => 'Custom build directory relative to public.'],
            ],
            'exceptions' => [
                '\Illuminate\Foundation\ViteManifestNotFoundException' => 'Thrown in production if the `manifest.json` build file is not found.',
            ],
            'example' => "@vite(['resources/css/app.css', 'resources/js/app.js'])",
        ],
        'livewire' => [
            'signature' => '@livewire(string $component, array $params = [], ?string $key = null)',
            'summary' => 'Renders a Livewire reactive component.',
            'parameters' => [
                'component' => ['type' => 'string', 'description' => 'Livewire component name or class (e.g. `\'counter\'`, `App\Livewire\Counter::class`).'],
                'params' => ['type' => 'array', 'optional' => true, 'default' => '[]', 'description' => 'Initial component mount parameters.'],
                'key' => ['type' => '?string', 'optional' => true, 'default' => 'null', 'description' => 'Optional unique key for component DOM tracking.'],
            ],
            'example' => "@livewire('user-profile', ['user' => \$user], key(\$user->id))",
        ],
        'auth' => [
            'signature' => '@auth(?string $guard = null)',
            'summary' => 'Determines if the current user is authenticated (optionally with a specific guard).',
            'parameters' => [
                'guard' => ['type' => '?string', 'optional' => true, 'default' => 'null', 'description' => 'Authentication guard name (e.g. `\'admin\'`, `\'api\'`, `\'web\'`).'],
            ],
            'example' => "@auth('admin')\n    <a href=\"/admin\">Dashboard</a>\n@endauth",
        ],
        'guest' => [
            'signature' => '@guest(?string $guard = null)',
            'summary' => 'Determines if the user is a guest (unauthenticated).',
            'parameters' => [
                'guard' => ['type' => '?string', 'optional' => true, 'default' => 'null', 'description' => 'Authentication guard name.'],
            ],
            'example' => "@guest\n    <a href=\"/login\">Login</a>\n@endguest",
        ],
        'env' => [
            'signature' => '@env(string|array $environments)',
            'summary' => 'Checks if the current application environment matches the specified environment(s).',
            'parameters' => [
                'environments' => ['type' => 'string|array', 'description' => 'Environment name or array of names (e.g. `\'production\'`, `[\'staging\', \'local\']`).'],
            ],
            'example' => "@env('local')\n    <div>Debug Bar Enabled</div>\n@endenv",
        ],
        'method' => [
            'signature' => '@method(string $method)',
            'summary' => 'Outputs a hidden HTML input `_method` to spoof HTTP verbs in HTML forms.',
            'parameters' => [
                'method' => ['type' => 'string', 'description' => 'The HTTP verb: `\'PUT\'`, `\'PATCH\'`, `\'DELETE\'`.'],
            ],
            'example' => '<form action="/posts/1" method="POST">@csrf @method("PUT") ...</form>',
        ],
        'csrf' => [
            'signature' => '@csrf',
            'summary' => 'Outputs a hidden HTML input `_token` containing the current CSRF token.',
            'parameters' => [],
            'example' => '<form method="POST">@csrf ...</form>',
        ],
        'dump' => [
            'signature' => '@dump(mixed ...$variables)',
            'summary' => 'Dumps the given variable(s) using Symfony VarDumper without halting execution.',
            'parameters' => [
                'variables' => ['type' => 'mixed', 'description' => 'Variable(s) to dump.'],
            ],
            'example' => '@dump($user, $orders)',
        ],
        'dd' => [
            'signature' => '@dd(mixed ...$variables)',
            'summary' => 'Dumps the given variable(s) using Symfony VarDumper and immediately terminates execution.',
            'parameters' => [
                'variables' => ['type' => 'mixed', 'description' => 'Variable(s) to dump and die.'],
            ],
            'example' => '@dd($user)',
        ],
    ];

    protected ClassRegistry $classRegistry;

    public function __construct(protected Project $project)
    {
        $this->classRegistry = new ClassRegistry($this->project);
    }

    /**
     * Provide hover information for Blade directives and their arguments.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function get(Document $document, array $position): ?array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return null;
        }

        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';

        // 1. Check if hovering on directive argument (e.g. '@use("App\Models\User")', '@inject("metrics", "App\Services\MetricsService")')
        $argHover = $this->findDirectiveArgumentHover($line, $character, $lineNumber);
        if ($argHover !== null) {
            return $argHover;
        }

        // 2. Check if hovering on directive name itself (e.g. '@use', '@inject', '@include', '@props', etc.)
        $dirHover = $this->findDirectiveHover($line, $character, $lineNumber);
        if ($dirHover !== null) {
            return $dirHover;
        }

        return null;
    }

    /**
     * Hover on directive arguments (classes, services, container bindings).
     */
    protected function findDirectiveArgumentHover(string $line, int $character, int $lineNumber): ?array
    {
        // 1. Hover on @use('App\Models\User', 'Alias') argument strings
        if (preg_match_all('/@use\s*\(\s*([\'"]([^\'"]+)[\'"]|[a-zA-Z0-9_\\\\]+::class)(?:\s*,\s*[\'"]([a-zA-Z0-9_]+)[\'"])?\s*\)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawTarget = $m[1][0];
                $targetStart = $m[1][1];
                $targetEnd = $targetStart + strlen($rawTarget);

                if ($character >= $targetStart && $character <= $targetEnd) {
                    $className = str_ends_with($rawTarget, '::class')
                        ? substr($rawTarget, 0, -7)
                        : trim($rawTarget, '\'"');
                    $alias = !empty($m[3][0]) ? $m[3][0] : class_basename($className);

                    return [
                        'contents' => [
                            'kind' => 'markdown',
                            'value' => $this->buildClassHoverMarkdown($className, $alias),
                        ],
                        'range' => [
                            'start' => ['line' => $lineNumber, 'character' => $targetStart],
                            'end' => ['line' => $lineNumber, 'character' => $targetEnd],
                        ],
                    ];
                }
            }
        }

        // 2. Hover on @inject('varName', 'App\Services\MetricsService') or @inject('db', 'db') argument strings
        if (preg_match_all('/@inject\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,\s*([\'"]([^\'"]+)[\'"]|[a-zA-Z0-9_\\\\]+::class)\s*\)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $varName = $m[1][0];
                $rawService = $m[2][0];
                $serviceStart = $m[2][1];
                $serviceEnd = $serviceStart + strlen($rawService);

                if ($character >= $serviceStart && $character <= $serviceEnd) {
                    $serviceKey = str_ends_with($rawService, '::class')
                        ? substr($rawService, 0, -7)
                        : trim($rawService, '\'"');

                    $boundType = AppBindingContainerTypeMap::resolveType($serviceKey) ?? $serviceKey;

                    return [
                        'contents' => [
                            'kind' => 'markdown',
                            'value' => $this->buildInjectServiceHoverMarkdown($varName, $serviceKey, $boundType),
                        ],
                        'range' => [
                            'start' => ['line' => $lineNumber, 'character' => $serviceStart],
                            'end' => ['line' => $lineNumber, 'character' => $serviceEnd],
                        ],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Hover on directive name token (@use, @inject, @include, etc.).
     */
    protected function findDirectiveHover(string $line, int $character, int $lineNumber): ?array
    {
        if (preg_match_all('/@([a-zA-Z0-9_]+)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $start = $match[1];
                $end = $start + strlen($match[0]);
                $dirName = strtolower($matches[1][$idx][0]);

                if ($character >= $start && $character <= $end) {
                    if (isset(self::DIRECTIVE_METADATA[$dirName])) {
                        $meta = self::DIRECTIVE_METADATA[$dirName];
                        return [
                            'contents' => [
                                'kind' => 'markdown',
                                'value' => $this->formatDirectiveMarkdown($dirName, $meta),
                            ],
                            'range' => [
                                'start' => ['line' => $lineNumber, 'character' => $start],
                                'end' => ['line' => $lineNumber, 'character' => $end],
                            ],
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Format directive metadata into rich GitHub Markdown.
     *
     * @param array{
     *     signature: string,
     *     summary: string,
     *     parameters: array<string, array{type: string, optional?: bool, default?: string, description: string}>,
     *     exceptions?: array<string, string>,
     *     notes?: string,
     *     example?: string
     * } $meta
     */
    protected function formatDirectiveMarkdown(string $name, array $meta): string
    {
        $md = "### `{$meta['signature']}`\n\n";
        $md .= "{$meta['summary']}\n\n";

        // Parameters breakdown
        if (!empty($meta['parameters'])) {
            $md .= "**Parameters (" . count($meta['parameters']) . "):**\n";
            foreach ($meta['parameters'] as $pName => $p) {
                $opt = !empty($p['optional']) ? " *(optional, default: `{$p['default']}`)*" : " *(required)*";
                $md .= "- `{$p['type']} \${$pName}`{$opt} — {$p['description']}\n";
            }
            $md .= "\n";
        }

        // Exceptions
        if (!empty($meta['exceptions'])) {
            $md .= "**Exceptions Thrown:**\n";
            foreach ($meta['exceptions'] as $exClass => $exDesc) {
                $md .= "- `{$exClass}` — {$exDesc}\n";
            }
            $md .= "\n";
        }

        // Notes
        if (!empty($meta['notes'])) {
            $md .= "> **Note**: {$meta['notes']}\n\n";
        }

        // Example
        if (!empty($meta['example'])) {
            $md .= "**Example:**\n```blade\n{$meta['example']}\n```\n";
        }

        return $md;
    }

    /**
     * Build rich hover markdown for an imported class in @use directive.
     */
    protected function buildClassHoverMarkdown(string $className, string $alias): string
    {
        $clean = ltrim($className, '\\');
        $baseName = class_basename($clean);

        $md = "### `{$clean}`" . ($alias !== $baseName ? " (as `{$alias}`)" : "") . "\n\n";
        $md .= "*Blade Class Import Directive*\n\n";

        if (class_exists($clean) || interface_exists($clean) || enum_exists($clean) || trait_exists($clean)) {
            try {
                $ref = new ReflectionClass($clean);
                $kind = $ref->isEnum() ? 'Enum' : ($ref->isInterface() ? 'Interface' : ($ref->isTrait() ? 'Trait' : 'Class'));

                $md .= "**Type:** `{$kind}`\n\n";

                if ($doc = $ref->getDocComment()) {
                    $cleanDoc = preg_replace('/^\s*\/\*\*|\s*\*\/|^\s*\* ?/m', '', $doc);
                    $cleanDoc = trim(preg_replace('/^@.*$/m', '', $cleanDoc));
                    if ($cleanDoc !== '') {
                        $md .= "{$cleanDoc}\n\n";
                    }
                }

                if ($file = $ref->getFileName()) {
                    $rel = $this->project->uri->path();
                    $relFile = str_starts_with($file, $rel) ? substr($file, strlen($rel) + 1) : $file;
                    $md .= "*File:* `{$relFile}`\n\n";
                }
            } catch (Throwable) {}
        } else {
            $md .= "```blade\n@use('{$clean}'" . ($alias !== $baseName ? ", '{$alias}'" : '') . ")\n```\n\n";
            $md .= "Imports `{$clean}` into template scope. Static methods, constants, and properties on `{$alias}` can be accessed directly.";
        }

        return $md;
    }

    /**
     * Build hover markdown for @inject service argument.
     */
    protected function buildInjectServiceHoverMarkdown(string $varName, string $serviceKey, string $boundType): string
    {
        $cleanType = ltrim($boundType, '\\');

        $md = "### Injected Service: `\${$varName}`\n\n";
        $md .= "- **Container Binding:** `{$serviceKey}`\n";
        $md .= "- **Resolved Type:** `\\{$cleanType}`\n\n";
        $md .= "```blade\n@inject('{$varName}', '{$serviceKey}')\n```\n\n";
        $md .= "Resolves `{$serviceKey}` via Laravel's Service Container (`app('{$serviceKey}')`) and assigns the instance to `\${$varName}` in the current view scope.\n";

        return $md;
    }
}
