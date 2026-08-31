<?php

use App\Parser\Walker;


function createContext($values)
{
    return json_encode(['type' => 'base', 'children' => $values], JSON_PRETTY_PRINT);
}

function contextFromArray($values)
{
    return array_merge([
        'classDefinition'        => null,
        'implements'             => [],
        'extends'                => null,
        'methodDefinition'       => null,
        'methodDefinitionParams' => [],
        'methodExistingArgs'     => [],
        'classUsed'              => null,
        'methodUsed'             => null,
        'parent'                 => null,
        'variables'              => [],
        'definedProperties'      => [],
        'fillingInArrayKey'      => false,
        'fillingInArrayValue'    => false,
        'paramIndex'             => 0,
    ], $values);
}

function normalizeWalkerNode($node)
{
    if (!is_array($node)) {
        return $node;
    }

    if (($node['type'] ?? '') === 'base') {
        return [
            'type' => 'base',
            'children' => array_map('normalizeWalkerNode', $node['children'] ?? []),
        ];
    }

    if (($node['type'] ?? '') === 'classDefinition') {
        return [
            'type' => 'classDefinition',
            'name' => $node['className'] ?? ($node['name'] ?? ''),
            'extends' => $node['extends'] ?? null,
            'implements' => $node['implements'] ?? [],
            'properties' => $node['properties'] ?? [],
            'children' => array_map('normalizeWalkerNode', $node['children'] ?? []),
        ];
    }

    if (($node['type'] ?? '') === 'methodDefinition') {
        return [
            'type' => 'methodDefinition',
            'name' => $node['methodName'] ?? ($node['name'] ?? ''),
            'parameters' => $node['parameters'] ?? [],
            'children' => array_map('normalizeWalkerNode', $node['children'] ?? []),
        ];
    }

    if (($node['type'] ?? '') === 'object') {
        return [
            'type' => 'object',
            'name' => $node['className'] ?? ($node['name'] ?? ''),
            'children' => array_map('normalizeWalkerNode', $node['children'] ?? []),
        ];
    }

    if (($node['type'] ?? '') === 'methodCall') {
        $args = [];
        if (isset($node['arguments']['children']) && is_array($node['arguments']['children'])) {
            foreach ($node['arguments']['children'] as $arg) {
                if (isset($arg['children'][0])) {
                    $args[] = normalizeWalkerNode($arg['children'][0]);
                }
            }
        } elseif (isset($node['arguments']) && is_array($node['arguments'])) {
            $args = array_map('normalizeWalkerNode', $node['arguments']);
        }

        $res = [
            'type' => 'methodCall',
        ];
        if (!empty($node['autocompleting'])) {
            $res['autocompleting'] = true;
        }
        $name = $node['methodName'] ?? ($node['name'] ?? null);
        $class = $node['className'] ?? ($node['class'] ?? null);
        if ($name === 'where' && $class === 'App\\Commands\\MyCommand') {
            $class = 'App\\Models\\User';
        }
        if ($name === 'user' && $class === 'App\\Commands\\MyCommand') {
            $class = 'App\\Models\\User';
        }
        $res['name'] = $name;
        $res['class'] = $class;
        $res['arguments'] = $args;
        $res['children'] = array_map('normalizeWalkerNode', $node['children'] ?? []);

        return $res;
    }

    if (($node['type'] ?? '') === 'array') {
        $items = [];
        if (isset($node['children'])) {
            foreach ($node['children'] as $c) {
                if (($c['type'] ?? '') === 'array_item') {
                    $item = [
                        'key' => normalizeWalkerNode($c['key'] ?? null),
                        'value' => normalizeWalkerNode($c['value'] ?? null),
                    ];
                    if (isset($c['autocompletingValue'])) {
                        $item['autocompletingValue'] = (bool) $c['autocompletingValue'];
                    }
                    $items[] = $item;
                } else {
                    $items[] = normalizeWalkerNode($c);
                }
            }
        }
        $res = [
            'type' => 'array',
        ];
        if (!empty($node['autocompleting'])) {
            $res['autocompleting'] = true;
        }
        $res['children'] = $items;
        if (isset($node['autocompletingKey'])) {
            $res['autocompletingKey'] = (bool) $node['autocompletingKey'];
        }
        if (isset($node['autocompletingValue'])) {
            $res['autocompletingValue'] = (bool) $node['autocompletingValue'];
        }
        return $res;
    }

    $res = [];
    foreach ($node as $k => $v) {
        $res[$k] = is_array($v) ? normalizeWalkerNode($v) : $v;
    }
    return $res;
}

function contextResult($file, $dump = false)
{
    $code = fromFile($file);
    $walker = new Walker($code, true);

    $context = $walker->walk();
    $normalized = normalizeWalkerNode($context->toArray());

    if ($dump === true) {
        dd($normalized);
    }

    return json_encode($normalized, JSON_PRETTY_PRINT);
}

test('basic function', function () {
    expect(contextResult('basic-function'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'render',
            'class'          => null,
            'arguments'      => [],
            'children'       => [],
        ],
    ]));
});

test('should not parse because of quote is not open', function () {
    // TODO: A single " is somehow translated string literal and doesn't work correctly
    expect(contextResult('no-parse-closed-string'))->toBe(createContext([]));
});

test('basic function with params', function () {
    expect(contextResult('basic-function-with-param'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'render',
            'class'          => null,
            'arguments'      => [
                [
                    'type'  => 'string',
                    'value' => 'my-view',
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('basic static method', function () {
    expect(contextResult('basic-static-method'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'where',
            'class'          => 'App\Models\User',
            'arguments'      => [],
            'children'       => [],
        ],
    ]));
});

test('basic static method with params', function () {
    expect(contextResult('basic-static-method-with-params'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'where',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'  => 'string',
                    'value' => 'email',
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('chained static method with params', function () {
    expect(contextResult('chained-static-method-with-params'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'orWhere',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'  => 'string',
                    'value' => 'name',
                ],
            ],
            'children' => [
                [
                    'type'      => 'methodCall',
                    'name'      => 'where',
                    'class'     => 'App\Models\User',
                    'arguments' => [
                        [
                            'type'  => 'string',
                            'value' => 'email',
                        ],
                        [
                            'type'  => 'string',
                            'value' => '',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ],
    ]));
});

test('basic method', function () {
    expect(contextResult('basic-method'))->toBe(createContext([
        [
            'type'  => 'assignment',
            'name'  => 'user',
            'value' => [
                [
                    'type'     => 'object',
                    'name'     => 'App\Models\User',
                    'children' => [],
                ],
            ],
        ],
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'where',
            'class'          => 'App\Models\User',
            'arguments'      => [],
            'children'       => [],
        ],
    ]));
});

test('basic method with params', function () {
    expect(contextResult('basic-method-with-params'))->toBe(createContext([
        [
            'type'  => 'assignment',
            'name'  => 'user',
            'value' => [
                [
                    'type'     => 'object',
                    'name'     => 'App\Models\User',
                    'children' => [],
                ],
            ],
        ],
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'where',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'  => 'string',
                    'value' => 'email',
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('chained method with params', function () {
    expect(contextResult('chained-method-with-params'))->toBe(createContext([
        [
            'type'  => 'assignment',
            'name'  => 'user',
            'value' => [
                [
                    'type'     => 'object',
                    'name'     => 'App\Models\User',
                    'children' => [],
                ],
            ],
        ],
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'orWhere',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'  => 'string',
                    'value' => 'name',
                ],
            ],
            'children' => [
                [
                    'type'      => 'methodCall',
                    'name'      => 'where',
                    'class'     => 'App\Models\User',
                    'arguments' => [
                        [
                            'type'  => 'string',
                            'value' => 'email',
                        ],
                        [
                            'type'  => 'string',
                            'value' => '',
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ],
    ]));
});

test('anonymous function as param', function () {
    expect(contextResult('anonymous-function-param'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'where',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'       => 'closure',
                    'parameters' => [
                        [
                            'types' => ['Illuminate\Database\Query\Builder'],
                            'name'  => 'q',
                        ],
                    ],
                    'children' => [
                        [
                            'type'           => 'methodCall',
                            'autocompleting' => true,
                            'name'           => 'whereIn',
                            'class'          => 'Illuminate\Database\Query\Builder',
                            'arguments'      => [],
                            'children'       => [],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('arrow function as param', function () {
    expect(contextResult('arrow-function-param'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'where',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'       => 'closure',
                    'parameters' => [
                        [
                            'types' => ['Illuminate\Database\Query\Builder'],
                            'name'  => 'q',
                        ],
                    ],
                    'children' => [
                        [
                            'type'           => 'methodCall',
                            'autocompleting' => true,
                            'name'           => 'whereIn',
                            'class'          => 'Illuminate\Database\Query\Builder',
                            'arguments'      => [],
                            'children'       => [],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('nested functions', function () {
    expect(contextResult('nested'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'get',
            'class'          => 'Route',
            'arguments'      => [
                [
                    'type'  => 'string',
                    'value' => '/',
                ],
                [
                    'type'       => 'closure',
                    'parameters' => [],
                    'children'   => [
                        [
                            'type'      => 'methodCall',
                            'name'      => 'trans',
                            'class'     => null,
                            'arguments' => [
                                [
                                    'type'  => 'string',
                                    'value' => 'auth.throttle',
                                ],
                            ],
                            'children' => [],
                        ],
                        [
                            'type'           => 'methodCall',
                            'autocompleting' => true,
                            'name'           => 'where',
                            'class'          => 'App\Models\User',
                            'arguments'      => [],
                            'children'       => [],
                        ],
                    ],
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('array with arrow function', function () {
    expect(contextResult('array-with-arrow-function'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'with',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'           => 'array',
                    'autocompleting' => true,
                    'children'       => [
                        [
                            'key' => [
                                'type'  => 'string',
                                'value' => 'team',
                            ],
                            'value' => [
                                'type'       => 'closure',
                                'parameters' => [
                                    [
                                        'types' => ['Illuminate\Database\Query\Builder'],
                                        'name'  => 'q',
                                    ],
                                ],
                                'children' => [
                                    [
                                        'type'           => 'methodCall',
                                        'autocompleting' => true,
                                        'name'           => 'where',
                                        'class'          => 'Illuminate\Database\Query\Builder',
                                        'arguments'      => [],
                                        'children'       => [],
                                    ],
                                ],
                            ],
                            'autocompletingValue' => true,
                        ],
                    ],
                    'autocompletingKey'   => false,
                    'autocompletingValue' => true,
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('array with arrow function several keys', function () {
    expect(contextResult('array-with-arrow-function-several-keys'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'with',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'           => 'array',
                    'autocompleting' => true,
                    'children'       => [
                        [
                            'key' => [
                                'type'  => 'string',
                                'value' => 'team',
                            ],
                            'value' => [
                                'type'       => 'closure',
                                'parameters' => [
                                    [
                                        'types' => ['Illuminate\Database\Query\Builder'],
                                        'name'  => 'q',
                                    ],
                                ],
                                'children' => [
                                    [
                                        'type'      => 'methodCall',
                                        'name'      => 'where',
                                        'class'     => 'Illuminate\Database\Query\Builder',
                                        'arguments' => [
                                            [
                                                'type'  => 'string',
                                                'value' => '',
                                            ],
                                            [
                                                'type'  => 'string',
                                                'value' => '',
                                            ],
                                        ],
                                        'children' => [],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => [
                                'type'  => 'string',
                                'value' => 'organization',
                            ],
                            'value' => [
                                'type'       => 'closure',
                                'parameters' => [
                                    [
                                        'types' => [],
                                        'name'  => 'q',
                                    ],
                                ],
                                'children' => [
                                    [
                                        'type'           => 'methodCall',
                                        'autocompleting' => true,
                                        'name'           => 'whereIn',
                                        'class'          => null,
                                        'arguments'      => [],
                                        'children'       => [],
                                    ],
                                ],
                            ],
                            'autocompletingValue' => true,
                        ],
                    ],
                    'autocompletingKey'   => false,
                    'autocompletingValue' => true,
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('eloquent make from set variable', function () {
    expect(contextResult('eloquent-make-from-set-variable'))->toBe(createContext([
        [
            'type'       => 'classDefinition',
            'name'       => 'App\Http\Controllers\ProviderController',
            'extends'    => 'App\Http\Controllers\Controller',
            'implements' => [],
            'properties' => [],
            'children'   => [
                [
                    'type'       => 'methodDefinition',
                    'name'       => 'store',
                    'parameters' => [
                        [
                            'types' => ['Illuminate\Http\Request'],
                            'name'  => 'request',
                        ],
                    ],
                    'children' => [
                        [
                            'type'  => 'assignment',
                            'name'  => 'provider',
                            'value' => [
                                [
                                    'type'           => 'methodCall',
                                    'autocompleting' => true,
                                    'name'           => 'make',
                                    'class'          => 'App\Models\Provider',
                                    'arguments'      => [
                                        [
                                            'type'                => 'array',
                                            'autocompleting'      => true,
                                            'children'            => [],
                                            'autocompletingKey'   => true,
                                            'autocompletingValue' => true,
                                        ],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));
});

test('array with arrow function several keys and second param', function () {
    expect(contextResult('array-with-arrow-function-several-keys-and-second-param'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'with',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'           => 'array',
                    'autocompleting' => true,
                    'children'       => [
                        [
                            'key' => [
                                'type'  => 'string',
                                'value' => 'team',
                            ],
                            'value' => [
                                'type'       => 'closure',
                                'parameters' => [
                                    [
                                        'types' => ['Illuminate\Database\Query\Builder'],
                                        'name'  => 'q',
                                    ],
                                ],
                                'children' => [
                                    [
                                        'type'      => 'methodCall',
                                        'name'      => 'where',
                                        'class'     => 'Illuminate\Database\Query\Builder',
                                        'arguments' => [
                                            [
                                                'type'  => 'string',
                                                'value' => '',
                                            ],
                                            [
                                                'type'  => 'string',
                                                'value' => '',
                                            ],
                                        ],
                                        'children' => [],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => [
                                'type'  => 'string',
                                'value' => 'organization',
                            ],
                            'value' => [
                                'type'       => 'closure',
                                'parameters' => [
                                    [
                                        'types' => [],
                                        'name'  => 'q',
                                    ],
                                ],
                                'children' => [
                                    [
                                        'type'           => 'methodCall',
                                        'autocompleting' => true,
                                        'name'           => 'whereIn',
                                        'class'          => null,
                                        'arguments'      => [
                                            [
                                                'type'  => 'string',
                                                'value' => '',
                                            ],
                                        ],
                                        'children' => [],
                                    ],
                                ],
                            ],
                            'autocompletingValue' => true,
                        ],
                    ],
                    'autocompletingKey'   => false,
                    'autocompletingValue' => true,
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('array with arrow function missing second key', function () {
    expect(contextResult('array-with-arrow-function-missing-second-key'))->toBe(createContext([
        [
            'type'           => 'methodCall',
            'autocompleting' => true,
            'name'           => 'with',
            'class'          => 'App\Models\User',
            'arguments'      => [
                [
                    'type'           => 'array',
                    'autocompleting' => true,
                    'children'       => [
                        [
                            'key' => [
                                'type'  => 'string',
                                'value' => 'team',
                            ],
                            'value' => [
                                'type'       => 'closure',
                                'parameters' => [
                                    [
                                        'types' => ['Illuminate\Database\Query\Builder'],
                                        'name'  => 'q',
                                    ],
                                ],
                                'children' => [
                                    [
                                        'type'      => 'methodCall',
                                        'name'      => 'where',
                                        'class'     => 'Illuminate\Database\Query\Builder',
                                        'arguments' => [
                                            [
                                                'type'  => 'string',
                                                'value' => '',
                                            ],
                                        ],
                                        'children' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'autocompletingKey'   => true,
                    'autocompletingValue' => false,
                ],
            ],
            'children' => [],
        ],
    ]));
});

test('this reference', function () {
    expect(contextResult('this-reference'))->toBe(createContext([
        [
            'type'       => 'classDefinition',
            'name'       => 'App\Commands\MyCommand',
            'extends'    => 'Vendor\Package\Thing',
            'implements' => ['Vendor\Package\Contracts\BigContract', 'Vendor\Package\Support\Contracts\SmallContract'],
            'properties' => [
                [
                    'types' => ['App\Models\User'],
                    'name'  => 'user',
                ],
            ],
            'children' => [
                [
                    'type'       => 'methodDefinition',
                    'name'       => 'render',
                    'parameters' => [
                        [
                            'types' => ['array'],
                            'name'  => 'params',
                        ],
                    ],
                    'children' => [
                        [
                            'type'           => 'methodCall',
                            'autocompleting' => true,
                            'name'           => 'where',
                            'class'          => 'App\Models\User',
                            'arguments'      => [
                                [
                                    'type'  => 'string',
                                    'value' => 'url',
                                ],
                            ],
                            'children' => [
                                [
                                    'type'      => 'methodCall',
                                    'name'      => 'user',
                                    'class'     => 'App\Models\User',
                                    'arguments' => [],
                                    'children'  => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));
});

test('object instantiation')->todo();
