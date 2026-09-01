<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeVariableRenameProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('blade variable rename is scoped, safe against substrings, and rejects invalid targets or names', function () {
    $tempDir = sys_get_temp_dir() . '/rename_safe_' . uniqid();

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    $content = <<<'BLADE'
<div class="user-card">
    <p>User: {{ $user->name }}</p>
    <p>Username: {{ $username }}</p>
    <p>String: 'user'</p>
    @if($user->isAdmin())
        <span>Admin</span>
    @endif
</div>
BLADE;

    $doc = new Document('file://' . $tempDir . '/test.blade.php', $content);

    // 1. prepareRename on HTML should return null
    $prepareHtml = $provider->prepareRename($doc, ['line' => 0, 'character' => 4]);
    expect($prepareHtml)->toBeNull();

    // 2. prepareRename on $user (line 1, col 18) should return placeholder 'user'
    $prepareVar = $provider->prepareRename($doc, ['line' => 1, 'character' => 18]);
    expect($prepareVar)->not->toBeNull();
    expect($prepareVar['placeholder'])->toBe('user');

    // 3. Rename $user to $customer
    $renameResult = $provider->rename($doc, ['line' => 1, 'character' => 18], 'customer');
    expect($renameResult)->not->toBeNull();
    $edits = $renameResult['changes'][$doc->uri];

    // Exactly 2 occurrences of $user (line 1 and line 4), NOT $username, NOT class="user-card", NOT 'user'
    expect($edits)->toHaveCount(2);
    expect($edits[0]['range']['start']['line'])->toBe(1);
    expect($edits[0]['newText'])->toBe('customer');
    expect($edits[1]['range']['start']['line'])->toBe(4);
    expect($edits[1]['newText'])->toBe('customer');

    // 4. Invalid new identifier should return null
    $invalidRename = $provider->rename($doc, ['line' => 1, 'character' => 18], '123-bad-var');
    expect($invalidRename)->toBeNull();
});

test('initialize response advertises renameProvider with prepareProvider', function () {
    $container = $this->app;
    $container->singletonIf(\App\Lsp\Contracts\ExceptionHandler::class, \App\Lsp\Exceptions\Handler::class);
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $init = new \App\Lsp\Methods\Initialize($container, $logger);

    $tempDir = sys_get_temp_dir() . '/init_rename_' . uniqid();
    @mkdir($tempDir, 0777, true);
    touch($tempDir . '/artisan');

    $request = \App\Lsp\Transport\JsonRpcRequest::from([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'rootUri' => 'file://' . $tempDir,
            'capabilities' => [],
        ],
    ]);

    $response = $init->handle($request);
    $result = $response->toArray()['result'] ?? [];

    expect($result['capabilities'])->toHaveKey('renameProvider');
    expect($result['capabilities']['renameProvider'])->toBe(['prepareProvider' => true]);

    @rmdir($tempDir);
});
