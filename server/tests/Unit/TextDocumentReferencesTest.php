<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Methods\TextDocumentReferences;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;
use Mockery;

test('textDocument/references finds symbol references in Blade templates', function () {
    $container = new Container();
    $tempDir = sys_get_temp_dir() . '/laravel_test_refs_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $container->instance(Project::class, $project);

    $bladeContent = <<<'BLADE'
@php
    $user = 'Akib';
    echo $user;
@endphp

<div>
    {{ $user }}
</div>
BLADE;

    $documents = new DocumentManager();
    $container->instance(DocumentManager::class, $documents);
    $uri = 'file://' . $tempDir . '/resources/views/refs.blade.php';
    $documents->open($uri, $bladeContent);

    $features = new FeatureRegistry($container);
    $method = new TextDocumentReferences($documents, $features, $project);

    // Request references for $user at line 1, char 6
    $request = new JsonRpcRequest(
        1,
        'textDocument/references',
        [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 1, 'character' => 6],
            'context' => ['includeDeclaration' => true],
        ]
    );

    $response = $method->handle($request);
    $result = $response->toArray()['result'] ?? [];

    expect(count($result))->toBeGreaterThanOrEqual(2);
    foreach ($result as $loc) {
        expect($loc['uri'])->toBe($uri);
    }

    @unlink($tempDir);
});
