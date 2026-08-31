<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\FunctionTypeResolver;
use App\Lsp\Analysis\SemanticIndex;
use App\Lsp\Document;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

test('new FunctionTypeResolver() without project never throws and resolves built-in helpers', function () {
    $resolver = new FunctionTypeResolver();

    expect($resolver->resolve('app'))->toBe('\Illuminate\Foundation\Application')
        ->and($resolver->resolve('collect'))->toBe('\Illuminate\Support\Collection')
        ->and($resolver->resolve('str'))->toBe('\Illuminate\Support\Stringable')
        ->and($resolver->resolve('auth'))->toBe('\Illuminate\Auth\AuthManager')
        ->and($resolver->resolve('request'))->toBe('\Illuminate\Http\Request')
        ->and($resolver->resolve('unknown_function_name'))->toBeNull();
});

test('new BladeAstAnalyzer() without project can analyze helper calls without TypeError', function () {
    $analyzer = new BladeAstAnalyzer();

    $content = <<<'BLADE'
    @php
        $appName = config('app.name');
        $app = app();
        $items = collect([1, 2, 3]);
        $str = str('hello');
        $unknown = custom_helper_func();
    @endphp
    BLADE;

    $symbols = $analyzer->extractTemplateSymbols($content);

    expect($symbols)->toHaveKey('appName')
        ->and($symbols)->toHaveKey('app')
        ->and($symbols)->toHaveKey('items')
        ->and($symbols)->toHaveKey('str')
        ->and($symbols)->toHaveKey('unknown');

    expect((string) $symbols['app']->type)->toBe('\Illuminate\Foundation\Application')
        ->and((string) $symbols['items']->type)->toBe('\Illuminate\Support\Collection')
        ->and((string) $symbols['str']->type)->toBe('\Illuminate\Support\Stringable');
});

test('project-backed resolver resolves indexed container bindings', function () {
    $tempDir = sys_get_temp_dir() . '/func_proj_test_' . uniqid();
    @mkdir($tempDir . '/app', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([
        'payment.gateway' => ['class' => 'App\\Services\\StripePaymentGateway'],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $resolver = new FunctionTypeResolver($project);

    expect($resolver->resolve('app', 'payment.gateway'))->toBe('\App\Services\StripePaymentGateway');

    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('container-bound resolver and semantic index are registered and reused by FeatureRegistry', function () {
    $tempDir = sys_get_temp_dir() . '/func_container_test_' . uniqid();
    @mkdir($tempDir . '/app', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([
        'order.processor' => ['class' => 'App\\Services\\OrderProcessor'],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $container = new Container();
    $container->instance(Project::class, $project);

    $registry = new FeatureRegistry($container);

    expect($container->bound(FunctionTypeResolver::class))->toBeTrue()
        ->and($container->bound(SemanticIndex::class))->toBeTrue();

    $resolver = $container->make(FunctionTypeResolver::class);
    expect($resolver->resolve('app', 'order.processor'))->toBe('\App\Services\OrderProcessor');

    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('BladeScopeResolver containing config or app calls completes without errors', function () {
    $tempDir = sys_get_temp_dir() . '/func_scope_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $scopeResolver = new BladeScopeResolver($project);
    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', <<<'BLADE'
    @php
        $name = config('app.name');
        $service = app('db');
    @endphp
    BLADE);

    $scope = $scopeResolver->resolve($doc);

    expect($scope->hasVariable('name'))->toBeTrue()
        ->and($scope->hasVariable('service'))->toBeTrue();

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/resources');
    @rmdir($tempDir);
});

test('architecture check: AST analyzer does not instantiate new FunctionTypeResolver inside inferTypeFromExprNode and SemanticIndex never receives null', function () {
    $bladeAstAnalyzerSource = file_get_contents(__DIR__ . '/../../app/Lsp/Analysis/BladeAstAnalyzer.php');
    
    // Ensure inferTypeFromExprNode method does not contain new FunctionTypeResolver
    preg_match('/function inferTypeFromExprNode.*?^    \}/ms', $bladeAstAnalyzerSource, $matches);
    $inferMethodSource = $matches[0] ?? $bladeAstAnalyzerSource;
    expect($inferMethodSource)->not->toContain('new FunctionTypeResolver');

    $functionTypeResolverSource = file_get_contents(__DIR__ . '/../../app/Lsp/Analysis/FunctionTypeResolver.php');
    // Ensure resolveSemanticIndex ends with return null rather than returning new SemanticIndex
    expect($functionTypeResolverSource)->toContain('return null;');

    // Ensure SemanticIndex constructor requires non-null Project
    $semanticIndexRef = new ReflectionClass(SemanticIndex::class);
    $constructor = $semanticIndexRef->getConstructor();
    expect($constructor)->not->toBeNull();
    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getType()->getName())->toBe(Project::class);
    expect($params[0]->getType()->allowsNull())->toBeFalse();
});
