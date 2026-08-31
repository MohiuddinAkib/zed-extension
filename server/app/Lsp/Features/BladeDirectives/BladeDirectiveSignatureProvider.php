<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeDirectives;

use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Semantics\DirectiveSignature;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Support\Utf16Position;

class BladeDirectiveSignatureProvider
{
    /**
     * @var array<string, DirectiveSignature>
     */
    protected array $signatures = [];

    public function __construct(protected Project $project)
    {
        $this->registerBuiltinSignatures();
    }

    public function get(Document $document, array $position): ?array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $line = explode("\n", $document->content)[$lineNumber] ?? '';
        $textBeforeCursor = Utf16Position::substr($line, 0, $character);

        // 1. Blade Directive invocation: @directiveName(...)
        if (preg_match('/@([a-zA-Z0-9_]+)\s*\(([^)]*)$/', $textBeforeCursor, $m)) {
            $directiveName = strtolower($m[1]);
            $argsText = $m[2];

            if (isset($this->signatures[$directiveName])) {
                $sig = $this->signatures[$directiveName];
                $activeParam = $this->calculateActiveParameterIndex($argsText);

                $params = [];
                foreach ($sig->parameters as $p) {
                    $opt = !empty($p['optional']) ? ' = ...' : '';
                    $params[] = [
                        'label' => "\${$p['name']}{$opt}",
                        'documentation' => [
                            'kind' => 'markdown',
                            'value' => $p['documentation'] ?? "Parameter \${$p['name']}",
                        ],
                    ];
                }

                return [
                    'signatures' => [
                        [
                            'label' => $sig->formatSignature(),
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => $sig->documentation,
                            ],
                            'parameters' => $params,
                        ],
                    ],
                    'activeSignature' => 0,
                    'activeParameter' => min($activeParam, max(0, count($params) - 1)),
                ];
            }
        }

        // 2. Helper function invocation: route(...), view(...), config(...), trans(...), __(...), etc.
        if (preg_match('/(?:^|[^a-zA-Z0-9_$->:])(route|view|config|trans|__|auth|request|session|now|today|app|resolve|asset|url|mix|vite)\s*\(([^)]*)$/', $textBeforeCursor, $m)) {
            $helperName = $m[1];
            $argsText = $m[2];

            if (isset($this->signatures[$helperName])) {
                $sig = $this->signatures[$helperName];
                $activeParam = $this->calculateActiveParameterIndex($argsText);

                $params = [];
                foreach ($sig->parameters as $p) {
                    $opt = !empty($p['optional']) ? ' = ...' : '';
                    $params[] = [
                        'label' => "\${$p['name']}{$opt}",
                        'documentation' => [
                            'kind' => 'markdown',
                            'value' => $p['documentation'] ?? "Parameter \${$p['name']}",
                        ],
                    ];
                }

                return [
                    'signatures' => [
                        [
                            'label' => "function {$helperName}(" . implode(', ', array_column($params, 'label')) . ')',
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => $sig->documentation,
                            ],
                            'parameters' => $params,
                        ],
                    ],
                    'activeSignature' => 0,
                    'activeParameter' => min($activeParam, max(0, count($params) - 1)),
                ];
            }
        }

        return null;
    }

    protected function calculateActiveParameterIndex(string $argsText): int
    {
        $activeParam = 0;
        $depth = 0;
        $quote = null;
        $len = strlen($argsText);

        for ($i = 0; $i < $len; $i++) {
            $ch = $argsText[$i];

            if ($quote !== null) {
                if ($ch === $quote && ($i === 0 || $argsText[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $activeParam++;
            }
        }

        return $activeParam;
    }

    protected function registerBuiltinSignatures(): void
    {
        $this->signatures = [
            'include' => new DirectiveSignature('include', [
                ['name' => 'view', 'type' => TypeRef::fromString('string'), 'documentation' => 'The Blade view name to include.'],
                ['name' => 'data', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Array of data to pass to the view.'],
                ['name' => 'mergeData', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Array of data to merge with existing data.'],
            ], 'Include a Blade view template.'),

            'includeif' => new DirectiveSignature('includeIf', [
                ['name' => 'view', 'type' => TypeRef::fromString('string'), 'documentation' => 'The Blade view name to include if it exists.'],
                ['name' => 'data', 'type' => TypeRef::fromString('array'), 'optional' => true],
            ], 'Include a Blade view if it exists.'),

            'includewhen' => new DirectiveSignature('includeWhen', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Condition expression.'],
                ['name' => 'view', 'type' => TypeRef::fromString('string'), 'documentation' => 'The Blade view name.'],
                ['name' => 'data', 'type' => TypeRef::fromString('array'), 'optional' => true],
            ], 'Include a Blade view when condition is true.'),

            'includeunless' => new DirectiveSignature('includeUnless', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Condition expression.'],
                ['name' => 'view', 'type' => TypeRef::fromString('string'), 'documentation' => 'The Blade view name.'],
                ['name' => 'data', 'type' => TypeRef::fromString('array'), 'optional' => true],
            ], 'Include a Blade view unless condition is true.'),

            'includefirst' => new DirectiveSignature('includeFirst', [
                ['name' => 'views', 'type' => TypeRef::fromString('array<string>'), 'documentation' => 'Array of view names to check.'],
                ['name' => 'data', 'type' => TypeRef::fromString('array'), 'optional' => true],
            ], 'Include the first existing Blade view from an array.'),

            'extends' => new DirectiveSignature('extends', [
                ['name' => 'layout', 'type' => TypeRef::fromString('string'), 'documentation' => 'The parent layout view name.'],
            ], 'Extend a parent Blade layout template.'),

            'section' => new DirectiveSignature('section', [
                ['name' => 'name', 'type' => TypeRef::fromString('string'), 'documentation' => 'Section name.'],
                ['name' => 'content', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Inline section content (optional).'],
            ], 'Define a template section.'),

            'yield' => new DirectiveSignature('yield', [
                ['name' => 'section', 'type' => TypeRef::fromString('string'), 'documentation' => 'Section name to display.'],
                ['name' => 'default', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Default fallback content.'],
            ], 'Display contents of a given section.'),

            'each' => new DirectiveSignature('each', [
                ['name' => 'view', 'type' => TypeRef::fromString('string'), 'documentation' => 'View template to render for each item.'],
                ['name' => 'collection', 'type' => TypeRef::fromString('iterable'), 'documentation' => 'Collection or array to iterate over.'],
                ['name' => 'as', 'type' => TypeRef::fromString('string'), 'documentation' => 'Variable name in the partial view.'],
                ['name' => 'empty', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'View to render when collection is empty.'],
            ], 'Render a view for each element in an array or collection.'),

            'error' => new DirectiveSignature('error', [
                ['name' => 'key', 'type' => TypeRef::fromString('string'), 'documentation' => 'Validation field key.'],
                ['name' => 'bag', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Error bag name (default: "default").'],
            ], 'Check for validation error message for a specific key.'),

            'push' => new DirectiveSignature('push', [
                ['name' => 'name', 'type' => TypeRef::fromString('string'), 'documentation' => 'Stack name.'],
            ], 'Push content onto a named stack.'),

            'stack' => new DirectiveSignature('stack', [
                ['name' => 'name', 'type' => TypeRef::fromString('string'), 'documentation' => 'Stack name to render.'],
            ], 'Render contents of a named stack.'),

            'can' => new DirectiveSignature('can', [
                ['name' => 'ability', 'type' => TypeRef::fromString('string'), 'documentation' => 'Gate/Policy ability name.'],
                ['name' => 'arguments', 'type' => TypeRef::fromString('mixed'), 'optional' => true, 'documentation' => 'Arguments/models to pass to the gate check.'],
            ], 'Check authorization ability.'),

            'cannot' => new DirectiveSignature('cannot', [
                ['name' => 'ability', 'type' => TypeRef::fromString('string'), 'documentation' => 'Gate/Policy ability name.'],
                ['name' => 'arguments', 'type' => TypeRef::fromString('mixed'), 'optional' => true],
            ], 'Check if authorization ability is denied.'),

            'checked' => new DirectiveSignature('checked', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Boolean condition to output checked="checked".'],
            ], 'Conditionally add checked attribute.'),

            'selected' => new DirectiveSignature('selected', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Boolean condition to output selected="selected".'],
            ], 'Conditionally add selected attribute.'),

            'disabled' => new DirectiveSignature('disabled', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Boolean condition to output disabled.'],
            ], 'Conditionally add disabled attribute.'),

            'readonly' => new DirectiveSignature('readonly', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Boolean condition to output readonly.'],
            ], 'Conditionally add readonly attribute.'),

            'required' => new DirectiveSignature('required', [
                ['name' => 'condition', 'type' => TypeRef::fromString('bool'), 'documentation' => 'Boolean condition to output required.'],
            ], 'Conditionally add required attribute.'),

            'style' => new DirectiveSignature('style', [
                ['name' => 'style', 'type' => TypeRef::fromString('array|string'), 'documentation' => 'Array or string of CSS styles.'],
            ], 'Conditionally compile CSS style attributes.'),

            'class' => new DirectiveSignature('class', [
                ['name' => 'classes', 'type' => TypeRef::fromString('array|string'), 'documentation' => 'Conditional array of CSS class names.'],
            ], 'Conditionally compile CSS class names.'),

            'env' => new DirectiveSignature('env', [
                ['name' => 'environments', 'type' => TypeRef::fromString('string|array'), 'documentation' => 'Environment name(s).'],
            ], 'Check current application environment.'),

            'auth' => new DirectiveSignature('auth', [
                ['name' => 'guard', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Authentication guard name.'],
            ], 'Check if user is authenticated.'),

            'guest' => new DirectiveSignature('guest', [
                ['name' => 'guard', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Authentication guard name.'],
            ], 'Check if user is a guest.'),

            'use' => new DirectiveSignature('use', [
                ['name' => 'class', 'type' => TypeRef::fromString('string'), 'documentation' => 'Fully qualified class name.'],
                ['name' => 'as', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Class alias name.'],
            ], 'Import PHP class or alias in Blade template.'),

            'json' => new DirectiveSignature('json', [
                ['name' => 'data', 'type' => TypeRef::fromString('mixed'), 'documentation' => 'Data to encode as JSON.'],
                ['name' => 'options', 'type' => TypeRef::fromString('int'), 'optional' => true, 'documentation' => 'JSON encoding options.'],
                ['name' => 'depth', 'type' => TypeRef::fromString('int'), 'optional' => true, 'documentation' => 'Maximum depth.'],
            ], 'Encode data to JSON safe for JavaScript.'),

            'vite' => new DirectiveSignature('vite', [
                ['name' => 'entrypoints', 'type' => TypeRef::fromString('string|array'), 'documentation' => 'Vite asset entrypoint(s).'],
                ['name' => 'buildDirectory', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Custom build directory.'],
            ], 'Render Vite scripts and styles.'),

            'livewire' => new DirectiveSignature('livewire', [
                ['name' => 'component', 'type' => TypeRef::fromString('string'), 'documentation' => 'Livewire component name or class.'],
                ['name' => 'params', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Component initial parameters.'],
                ['name' => 'key', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Unique component key.'],
            ], 'Render Livewire component.'),

            'props' => new DirectiveSignature('props', [
                ['name' => 'props', 'type' => TypeRef::fromString('array'), 'documentation' => 'Array of component props with default values.'],
            ], 'Define component props.'),

            'aware' => new DirectiveSignature('aware', [
                ['name' => 'props', 'type' => TypeRef::fromString('array'), 'documentation' => 'Array of parent component props to inherit.'],
            ], 'Access props from parent components.'),

            // Helper signatures
            'route' => new DirectiveSignature('route', [
                ['name' => 'name', 'type' => TypeRef::fromString('string'), 'documentation' => 'Named route identifier.'],
                ['name' => 'parameters', 'type' => TypeRef::fromString('mixed'), 'optional' => true, 'documentation' => 'Route parameters.'],
                ['name' => 'absolute', 'type' => TypeRef::fromString('bool'), 'optional' => true, 'documentation' => 'Whether to generate an absolute URL.'],
            ], 'Generate the URL to a named route.'),

            'view' => new DirectiveSignature('view', [
                ['name' => 'view', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'View name.'],
                ['name' => 'data', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Data to pass to the view.'],
                ['name' => 'mergeData', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Data to merge with existing data.'],
            ], 'Get the evaluated view contents.'),

            'config' => new DirectiveSignature('config', [
                ['name' => 'key', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Configuration key name.'],
                ['name' => 'default', 'type' => TypeRef::fromString('mixed'), 'optional' => true, 'documentation' => 'Default fallback value.'],
            ], 'Get / set the specified configuration value.'),

            'trans' => new DirectiveSignature('trans', [
                ['name' => 'key', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Translation key.'],
                ['name' => 'replace', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Replacement placeholders.'],
                ['name' => 'locale', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Locale code.'],
            ], 'Translate the given message.'),

            '__' => new DirectiveSignature('__', [
                ['name' => 'key', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Translation key.'],
                ['name' => 'replace', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Replacement placeholders.'],
                ['name' => 'locale', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Locale code.'],
            ], 'Translate the given message.'),

            'request' => new DirectiveSignature('request', [
                ['name' => 'key', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Input key name.'],
                ['name' => 'default', 'type' => TypeRef::fromString('mixed'), 'optional' => true, 'documentation' => 'Default fallback value.'],
            ], 'Get an instance of the current request or an input item.'),

            'session' => new DirectiveSignature('session', [
                ['name' => 'key', 'type' => TypeRef::fromString('string|array'), 'optional' => true, 'documentation' => 'Session key.'],
                ['name' => 'default', 'type' => TypeRef::fromString('mixed'), 'optional' => true, 'documentation' => 'Default value.'],
            ], 'Get / set the specified session value.'),

            'now' => new DirectiveSignature('now', [
                ['name' => 'tz', 'type' => TypeRef::fromString('DateTimeZone|string'), 'optional' => true, 'documentation' => 'Timezone.'],
            ], 'Create a new Carbon instance for the current date and time.'),

            'today' => new DirectiveSignature('today', [
                ['name' => 'tz', 'type' => TypeRef::fromString('DateTimeZone|string'), 'optional' => true, 'documentation' => 'Timezone.'],
            ], 'Create a new Carbon instance for the current date.'),

            'app' => new DirectiveSignature('app', [
                ['name' => 'abstract', 'type' => TypeRef::fromString('string'), 'optional' => true, 'documentation' => 'Container service or binding.'],
                ['name' => 'parameters', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Constructor parameters.'],
            ], 'Get the available container instance or resolve service.'),

            'resolve' => new DirectiveSignature('resolve', [
                ['name' => 'name', 'type' => TypeRef::fromString('string'), 'documentation' => 'Service or class name to resolve.'],
                ['name' => 'parameters', 'type' => TypeRef::fromString('array'), 'optional' => true, 'documentation' => 'Constructor parameters.'],
            ], 'Resolve a service from the container.'),

            'asset' => new DirectiveSignature('asset', [
                ['name' => 'path', 'type' => TypeRef::fromString('string'), 'documentation' => 'Asset path.'],
                ['name' => 'secure', 'type' => TypeRef::fromString('bool'), 'optional' => true, 'documentation' => 'Whether URL should be HTTPS.'],
            ], 'Generate an asset path for the application.'),
        ];
    }
}
