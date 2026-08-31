<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\ScopeOrigin;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VariableSymbol;
use App\Lsp\Semantics\ViewScope;
use App\Lsp\Support\FileUri;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir() . '/blade_scope_test_' . uniqid();
    @mkdir($this->basePath . '/resources/views', 0777, true);

    $uri = FileUri::of($this->basePath);
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'globals' => [],
        'views' => [],
    ]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([]));

    $scripts = new ScriptRunner($this->basePath, ['php']);
    $this->project = new Project($uri, [], $mockIndex, $scripts);
    $this->scopeResolver = new BladeScopeResolver($this->project);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    @rmdir($this->basePath);
});

test('ViewScope correctly reports variable presence with hasVariable', function () {
    $scope = new ViewScope('users.show');
    $scope->addVariable(new VariableSymbol(
        name: 'user',
        type: TypeRef::fromString('\App\Models\User'),
        origin: new ScopeOrigin('Controller', 'UserController.php', 10),
    ));

    expect($scope->hasVariable('user'))->toBeTrue()
        ->and($scope->hasVariable('$user'))->toBeTrue()
        ->and($scope->hasVariable('undefinedVar'))->toBeFalse();
});

test('BladeScopeResolver includes @for loop index variable in positional scope', function () {
    $blade = <<<'BLADE'
@for ($i = 0; $i < 10; $i++)
    <p>{{ $i }}</p>
@endfor
BLADE;
    $doc = new Document('file:///test/for.blade.php', $blade);

    // Line 2 (0-indexed line 1) is inside the @for loop body
    $scopeInside = $this->scopeResolver->resolveAtPosition($doc, 1, 10);
    expect($scopeInside->hasVariable('i'))->toBeTrue()
        ->and($scopeInside->hasVariable('loop'))->toBeTrue();

    // Line 4 (0-indexed line 3) is outside the @for loop
    $scopeOutside = $this->scopeResolver->resolveAtPosition($doc, 3, 0);
    expect($scopeOutside->hasVariable('i'))->toBeFalse();
});
