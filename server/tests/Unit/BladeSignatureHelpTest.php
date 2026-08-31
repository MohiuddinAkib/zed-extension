<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Methods\TextDocumentSignatureHelp;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

test('signature help provides accurate signatures and active parameter tracking for blade directives and helpers', function () {
    $tempDir = sys_get_temp_dir() . '/sig_test_' . uniqid();
    $container = new Container();

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $container->instance(Project::class, $project);

    $documents = new DocumentManager();
    $features = new FeatureRegistry($container);
    $handler = new TextDocumentSignatureHelp($documents, $features, $project);

    // 1. Test @include('partials.nav',
    $uri1 = 'file://' . $tempDir . '/resources/views/test.blade.php';
    $content1 = "@include('partials.nav', ";
    $documents->open($uri1, $content1);

    $req = new JsonRpcRequest(1, 'textDocument/signatureHelp', [
        'textDocument' => ['uri' => $uri1],
        'position' => ['line' => 0, 'character' => 25],
    ]);

    $resp = $handler->handle($req);
    $result = $resp->toArray()['result'];
    expect($result)->not->toBeNull();
    expect($result['signatures'])->toHaveCount(1);
    expect($result['signatures'][0]['label'])->toContain('@include');
    expect($result['activeParameter'])->toBe(1); // Second parameter: $data

    // 2. Test route('users.show', [
    $uri2 = 'file://' . $tempDir . '/resources/views/test2.blade.php';
    $content2 = "{{ route('users.show', ";
    $documents->open($uri2, $content2);

    $req2 = new JsonRpcRequest(2, 'textDocument/signatureHelp', [
        'textDocument' => ['uri' => $uri2],
        'position' => ['line' => 0, 'character' => 24],
    ]);

    $resp2 = $handler->handle($req2);
    $result2 = $resp2->toArray()['result'];
    expect($result2)->not->toBeNull();
    expect($result2['signatures'][0]['label'])->toContain('route');
    expect($result2['activeParameter'])->toBe(1); // Second parameter: $parameters
});
