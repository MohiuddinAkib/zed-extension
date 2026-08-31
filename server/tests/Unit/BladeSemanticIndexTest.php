<?php

declare(strict_types=1);

use App\Lsp\Analysis\SemanticIndex;
use App\Lsp\Data\Models;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\Functions\GlobalFunctionRegistry;
use App\Lsp\Features\Validation\ValidationCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

function semanticIndexProject(array $models = [], array $builderMethods = [], array $appBindings = [], array $helpers = [], array $validationRules = []): Project
{
    $index = Mockery::mock(ProjectIndex::class);
    $index->shouldReceive('models')->byDefault()->andReturn($models);
    $index->shouldReceive('builderMethods')->byDefault()->andReturn($builderMethods);
    $index->shouldReceive('appBindings')->byDefault()->andReturn(collect($appBindings));
    $index->shouldReceive('helpers')->byDefault()->andReturn($helpers);
    $index->shouldReceive('validationRules')->byDefault()->andReturn($validationRules);
    $index->shouldReceive('viewVariables')->byDefault()->andReturn(['views' => [], 'globals' => []]);
    $index->shouldReceive('views')->byDefault()->andReturn(collect([]));

    return new Project(
        FileUri::of(sys_get_temp_dir()),
        [],
        $index,
        new ScriptRunner(sys_get_temp_dir(), ['php']),
    );
}

test('models provider preserves reflected builder methods beside model metadata', function () {
    $provider = new Models(semanticIndexProject());

    $metadata = $provider->parse([
        'builderMethods' => [
            [
                'name'       => 'whereFutureFrameworkMethod',
                'parameters' => [],
                'return'     => 'static',
            ],
        ],
        'models' => [
            'App\\Models\\Post' => [
                'attributes' => [],
                'relations'  => [],
            ],
        ],
    ]);

    expect($metadata['models'])->toHaveKey('App\\Models\\Post');
    expect($metadata['builderMethods'])->toHaveCount(1);
    expect($metadata['builderMethods'][0]['name'])->toBe('whereFutureFrameworkMethod');
});

test('semantic index prefers reflected eloquent builder methods over fallback overlay', function () {
    $project = semanticIndexProject(
        models: [
            'App\\Models\\Post' => [
                'attributes' => [],
                'relations'  => [],
                'scopes'     => [],
            ],
        ],
        builderMethods: [
            [
                'name'       => 'where',
                'parameters' => [],
                'return'     => 'static',
            ],
            [
                'name'       => 'whereFutureFrameworkMethod',
                'parameters' => [
                    [
                        'name'                => 'column',
                        'type'                => 'string',
                        'hasDefault'          => false,
                        'isVariadic'          => false,
                        'isPassedByReference' => false,
                    ],
                ],
                'return' => 'static',
            ],
        ],
    );

    $members = (new SemanticIndex($project))->eloquentMembersForModel('App\\Models\\Post');

    expect($members)->toHaveKey('whereFutureFrameworkMethod');
    expect($members['whereFutureFrameworkMethod']['paramSignature'])->toBe('(string $column)');
    expect($members['whereFutureFrameworkMethod']['returnType'])->toBe('\\Illuminate\\Database\\Eloquent\\Builder<\\App\\Models\\Post>');
    expect($members['where']['documentation'])->toContain('Indexed Eloquent Builder');
});

test('semantic index exposes local model scopes as builder methods', function () {
    $project = semanticIndexProject(
        models: [
            'App\\Models\\Post' => [
                'attributes' => [],
                'relations'  => [],
                'scopes'     => [
                    [
                        'name'       => 'published',
                        'method'     => 'scopePublished',
                        'parameters' => [
                            [
                                'name'                => 'query',
                                'type'                => '\\Illuminate\\Database\\Eloquent\\Builder',
                                'hasDefault'          => false,
                                'isVariadic'          => false,
                                'isPassedByReference' => false,
                            ],
                            [
                                'name'                => 'featured',
                                'type'                => 'bool',
                                'hasDefault'          => true,
                                'default'             => 'false',
                                'isVariadic'          => false,
                                'isPassedByReference' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    );

    $members = (new SemanticIndex($project))->eloquentMembersForModel('App\\Models\\Post');

    expect($members)->toHaveKey('published');
    expect($members['published']['paramSignature'])->toBe('(bool $featured = false)');
    expect($members['published']['requiredParams'])->toBe(0);
    expect($members['published']['documentation'])->toContain('Eloquent Local Scope');
});

test('semantic index resolves custom container bindings before core overlay', function () {
    $project = semanticIndexProject(
        appBindings: [
            'billing.gateway' => [
                'class' => 'App\\Services\\BillingGateway',
            ],
        ],
    );

    $semanticIndex = new SemanticIndex($project);
    $bindings = $semanticIndex->containerBindings();

    expect($semanticIndex->containerBindingType('billing.gateway'))->toBe('\\App\\Services\\BillingGateway');
    expect($bindings)->toHaveKey('billing.gateway');
    expect($bindings['billing.gateway']['origin'])->toBe('Indexed Container Binding');
});

test('blade static member completion consumes semantic indexed builder methods', function () {
    $project = semanticIndexProject(
        models: [
            'App\\Models\\Post' => [
                'attributes' => [],
                'relations'  => [],
                'scopes'     => [],
            ],
        ],
        builderMethods: [
            [
                'name'       => 'whereFutureFrameworkMethod',
                'parameters' => [],
                'return'     => 'static',
            ],
        ],
    );

    $semanticIndex = new SemanticIndex($project);
    $provider = new BladeMemberCompletionProvider($project, $semanticIndex);
    $document = new Document('file:///tmp/indexed-builder.blade.php', <<<'BLADE'
@use('App\Models\Post')
{{ Post::whereFuture }}
BLADE);

    $items = $provider->getStaticMemberCompletions($document, 'Post', 'whereFuture', 1, 21);
    $labels = collect($items)->pluck('label')->all();

    expect($labels)->toContain('whereFutureFrameworkMethod');
    expect(collect($items)->firstWhere('label', 'whereFutureFrameworkMethod')['documentation']['value'])->toContain('Indexed Eloquent Builder');
});

test('global function registry prefers indexed helper metadata over fallback helper overlay', function () {
    $project = semanticIndexProject(
        helpers: [
            'route' => [
                'name'       => 'route',
                'signature'  => 'route(string $name): \\App\\Routing\\SignedRoute',
                'returnType' => '\\App\\Routing\\SignedRoute',
                'doc'        => 'Project-specific route helper metadata.',
                'snippet'    => "route('\${1:name}')",
                'origin'     => 'Composer Autoload Helper',
            ],
            'project_link' => [
                'name'       => 'project_link',
                'signature'  => 'project_link(string $path): string',
                'returnType' => 'string',
                'doc'        => 'Project helper discovered from Composer autoload files.',
                'snippet'    => "project_link('\${1:path}')",
                'origin'     => 'Composer Autoload Helper',
            ],
        ],
    );

    $registry = new GlobalFunctionRegistry($project);

    expect($registry->get('route')['returnType'])->toBe('\\App\\Routing\\SignedRoute');
    expect($registry->get('route')['origin'])->toBe('Composer Autoload Helper');
    expect($registry->get('project_link')['signature'])->toBe('project_link(string $path): string');
    expect(collect($registry->completions('project_'))->pluck('label')->all())->toContain('project_link()');
});

test('validation completion provider uses indexed rules before fallback snippets', function () {
    $project = semanticIndexProject(
        validationRules: [
            'accepted' => [
                'name'      => 'accepted',
                'origin'    => 'Laravel Validator Reflection',
                'hasParams' => false,
            ],
            'future_rule' => [
                'name'      => 'future_rule',
                'origin'    => 'Custom Validator Extension',
                'hasParams' => true,
            ],
        ],
    );

    $provider = new ValidationCompletionProvider($project);
    $rules = (fn (): array => $this->rules())->call($provider);

    expect($rules)->toHaveKey('future_rule');
    expect($rules['future_rule'])->toBe('future_rule:${1}');
    expect($rules['accepted'])->toBe('accepted');
});
