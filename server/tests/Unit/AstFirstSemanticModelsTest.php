<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\ViewScope;

test('view scope merges variables with provenance instead of overwriting by scan order', function () {
    $scope = new ViewScope('users.show');

    $scope->addLegacyVariables([
        'user' => [
            'name' => 'user',
            'type' => '\\App\\Models\\User',
            'origin' => 'Controller',
            'source' => 'app/Http/Controllers/UserController.php',
            'line' => 20,
        ],
    ], ['app/Http/Controllers/UserController.php']);

    $scope->addLegacyVariables([
        'user' => [
            'name' => 'user',
            'type' => '\\App\\Models\\Admin',
            'origin' => 'Route Closure',
            'source' => 'routes/web.php',
            'line' => 12,
        ],
        'posts' => [
            'name' => 'posts',
            'type' => '\\Illuminate\\Support\\Collection<int, \\App\\Models\\Post>',
            'origin' => 'Controller',
        ],
    ], ['routes/web.php']);

    $legacy = $scope->toLegacyArray();

    expect($legacy['sources'])->toBe([
        'app/Http/Controllers/UserController.php',
        'routes/web.php',
    ]);

    expect($legacy['variables'])->toHaveKeys(['user', 'posts']);
    expect($legacy['variables']['user']['type'])->toBe('\\App\\Models\\User|\\App\\Models\\Admin');
    expect($legacy['variables']['user']['optional'])->toBeTrue();
    expect($legacy['variables']['user']['origins'])->toHaveCount(2);
});

test('type refs keep generic arguments as structured child types', function () {
    $type = TypeRef::fromString('\\Illuminate\\Support\\Collection<int, array{id: int, name: string}>');

    expect($type->kind)->toBe('generic');
    expect((string) $type)->toBe('\\Illuminate\\Support\\Collection<int, array{id: int, name: string}>');
    expect($type->children)->toHaveCount(2);
    expect($type->children[0]->displayName)->toBe('int');
    expect($type->children[1]->displayName)->toBe('array{id: int, name: string}');

    $nestedUnion = TypeRef::fromString('\\Illuminate\\Support\\Collection<int, \\App\\Models\\User|\\App\\Models\\Admin>');
    expect($nestedUnion->kind)->toBe('generic');
    expect($nestedUnion->children[1]->kind)->toBe('union');

    $classString = TypeRef::fromString('class-string<\\App\\Models\\User>');
    expect($classString->kind)->toBe('semantic');
    expect($classString->children[0]->displayName)->toBe('\\App\\Models\\User');
});

test('blade ast analyzer parses complex props and php directives through ast nodes', function () {
    $analyzer = new BladeAstAnalyzer();

    $blade = <<<'BLADE'
@props([
    'title',
    'variant' => 'info,warning',
    'options' => ['size' => 'lg', 'dismissible' => true],
    'formatter' => fn ($value) => strtoupper($value),
    'state' => match ($variant) {
        'danger' => 'error',
        default => 'ready',
    },
])

@inject('metrics', 'App\Services\MetricsService')
@php($featuredPost = new \App\Models\Post())
@php
    $fallbackFormatter = fn ($value) => trim($value);
@endphp
BLADE;

    $vars = $analyzer->extractTemplateVariables($blade, 'resources/views/components/alert.blade.php');

    expect($vars)->toHaveKeys([
        'title',
        'variant',
        'options',
        'formatter',
        'state',
        'metrics',
        'featuredPost',
        'fallbackFormatter',
    ]);

    expect($vars['title']['type'])->toBe('mixed');
    expect($vars['title']['metadata']['required'])->toBeTrue();

    expect($vars['variant']['type'])->toBe('string');
    expect($vars['variant']['default'])->toBe("'info,warning'");
    expect($vars['variant']['type'])->not->toContain('default');

    expect($vars['options']['type'])->toBe('array');
    expect($vars['formatter']['type'])->toBe('\\Closure');
    expect($vars['state']['type'])->toBe('string');
    expect($vars['metrics']['type'])->toBe('\\App\\Services\\MetricsService');
    expect($vars['featuredPost']['type'])->toBe('\\App\\Models\\Post');
    expect($vars['fallbackFormatter']['type'])->toBe('\\Closure');
});

test('type ref preserves array shape and object shape members with exact nested types', function () {
    $shapeType = TypeRef::fromString('array{ip: string, user_agent?: string, count: int, meta: array{nested_id: int}}');

    expect($shapeType->isShape())->toBeTrue();
    expect($shapeType->kind)->toBe('array-shape');
    expect($shapeType->shapeMembers())->toHaveKeys(['ip', 'user_agent', 'count', 'meta']);

    expect($shapeType->getShapeMember('ip')->displayName)->toBe('string');
    expect($shapeType->getShapeMember('user_agent')->displayName)->toBe('string');
    expect($shapeType->getShapeMember('user_agent')->nullable)->toBeTrue();
    expect($shapeType->getShapeMember('count')->displayName)->toBe('int');

    $meta = $shapeType->getShapeMember('meta');
    expect($meta->isShape())->toBeTrue();
    expect($meta->getShapeMember('nested_id')->displayName)->toBe('int');
});

test('component and slot symbols represent props, slots, and kebab-camel mapping', function () {
    $prop = new \App\Lsp\Semantics\ComponentPropSymbol(
        name: 'userId',
        type: TypeRef::fromString('int'),
        required: true,
    );

    expect($prop->kebabName())->toBe('user-id');
    expect($prop->camelName())->toBe('userId');

    $comp = new \App\Lsp\Semantics\ComponentSymbol(
        name: 'userCard',
        tagName: 'x-user-card',
        props: [
            'userId' => $prop,
        ],
        slots: [
            'header' => new \App\Lsp\Semantics\SlotSymbol('header'),
        ],
    );

    expect($comp->getProp('user-id'))->not->toBeNull();
    expect($comp->getProp('userId'))->not->toBeNull();
    expect($comp->getSlot('header'))->not->toBeNull();
});

test('blade scope resolver does not leak component-only globals into ordinary views', function () {
    $mockIndex = Mockery::mock(\App\Lsp\ProjectIndex::class);
    $project = new \App\Lsp\Project(\App\Lsp\Support\FileUri::of('/tmp'), [], $mockIndex, new \App\Lsp\ScriptRunner('/tmp', ['php']));
    $viewVars = new \App\Lsp\Data\ViewVariables($project);

    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [],
        'globals' => $viewVars->get()['globals'],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));

    $resolver = new \App\Lsp\Analysis\BladeScopeResolver($project);

    $normalDoc = new \App\Lsp\Document('file:///tmp/resources/views/posts/show.blade.php', '<h1>{{ $post->title }}</h1>');
    $normalScope = $resolver->resolve($normalDoc);

    expect($normalScope->variables)->toHaveKeys(['__env', 'app', 'errors']);
    expect($normalScope->variables)->not->toHaveKey('attributes');
    expect($normalScope->variables)->not->toHaveKey('slot');

    $compDoc = new \App\Lsp\Document('file:///tmp/resources/views/components/alert.blade.php', '<div>{{ $slot }}</div>');
    $compScope = $resolver->resolve($compDoc);

    expect($compScope->variables)->toHaveKeys(['__env', 'app', 'errors', 'attributes', 'slot']);
});
