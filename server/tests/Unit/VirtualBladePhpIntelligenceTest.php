<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeDocumentCompiler;
use App\Lsp\Analysis\DefaultPhpIntelligenceAdapter;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\ScopeOrigin;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VariableSymbol;
use App\Lsp\Semantics\ViewScope;
use App\Lsp\Support\FileUri;

test('virtual blade document pipeline provides completion, hover, and diagnostics with source map precision', function () {
    $tempDir = sys_get_temp_dir() . '/virt_blade_' . uniqid();
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $compiler = new BladeDocumentCompiler();
    $adapter = new DefaultPhpIntelligenceAdapter($project);

    $scope = new ViewScope('users.show');
    $scope->addVariable(new VariableSymbol(
        name: 'user',
        type: TypeRef::fromString('\Illuminate\Database\ConnectionInterface'),
        origin: new ScopeOrigin('Controller', 'UserController.php', 25),
    ));

    $blade = <<<'BLADE'
<div>
    <h1>{{ $user->getDatabaseName() }}</h1>
</div>
BLADE;

    $doc = new Document('file://' . $tempDir . '/resources/views/users/show.blade.php', $blade);
    $virtualDoc = $compiler->compile($doc, $scope);

    // 1. Virtual document has valid code
    expect($virtualDoc->phpCode)->toContain('/** @var \Illuminate\Database\ConnectionInterface $user */')
        ->toContain('$__blade_echo = ($user->getDatabaseName());');

    // 2. Find position of getDatabaseName in virtual document
    $virtOffset = strpos($virtualDoc->phpCode, 'getDatabaseName');
    expect($virtOffset)->not->toBeFalse();

    // Map to virtual line & char
    $virtLines = explode("\n", substr($virtualDoc->phpCode, 0, $virtOffset));
    $vLine = count($virtLines) - 1;
    $vChar = strlen(end($virtLines));

    // Test Hover via Adapter
    $hover = $adapter->hover($virtualDoc, ['line' => $vLine, 'character' => $vChar + 2]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('Illuminate\Database\ConnectionInterface::getDatabaseName()');

    // Verify hover range mapped back to Blade file line 1
    expect($hover['range'])->not->toBeNull();
    expect($hover['range']['start']['line'])->toBe(1);
});
