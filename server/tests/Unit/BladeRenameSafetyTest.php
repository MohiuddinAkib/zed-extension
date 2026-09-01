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

test('prepareRename validates valid PHP variables and rejects comments, reserved vars, non-PHP tokens, and non-variable symbols', function () {
    $tempDir = sys_get_temp_dir() . '/rename_guards_' . uniqid();

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    $content = <<<'BLADE'
<div>
    <p>{{ $user }}</p>
    @if($item)
        <span :item="$item">Active</span>
    @endif
    @php
        $counter = 1;
        // $user in single line comment
        /* $user in multi line comment */
    @endphp
    @foreach($users as $user)
        <p>{{ $loop->index }} {{ $errors->first() }} {{ $__env }} {{ $this }} {{ $app }}</p>
    @endforeach
    {{-- $user in blade comment --}}
    <script>var $user = 123;</script>
    <div class="$user">Raw text with $user</div>
    {{ $user->run() }}
    {{ User::class }}
    {{ $user->name }}
</div>
BLADE;

    $doc = new Document('file://' . $tempDir . '/guards.blade.php', $content);

    // 1. Valid targets:
    // Line 1: '    <p>{{ $user }}</p>' -> char 11 is $user
    $prep1 = $provider->prepareRename($doc, ['line' => 1, 'character' => 11]);
    expect($prep1)->not->toBeNull();
    expect($prep1['placeholder'])->toBe('user');

    // Line 2: '    @if($item)' -> char 9 is $item
    $prep2 = $provider->prepareRename($doc, ['line' => 2, 'character' => 9]);
    expect($prep2)->not->toBeNull();
    expect($prep2['placeholder'])->toBe('item');

    // Line 3: '        <span :item="$item">Active</span>' -> char 22 is $item
    $prep3 = $provider->prepareRename($doc, ['line' => 3, 'character' => 22]);
    expect($prep3)->not->toBeNull();
    expect($prep3['placeholder'])->toBe('item');

    // Line 6: '        $counter = 1;' -> char 10 is $counter
    $prep4 = $provider->prepareRename($doc, ['line' => 6, 'character' => 10]);
    expect($prep4)->not->toBeNull();
    expect($prep4['placeholder'])->toBe('counter');

    // Line 10: '    @foreach($users as $user)'
    // char 14 is $users
    $prepUsers = $provider->prepareRename($doc, ['line' => 10, 'character' => 14]);
    expect($prepUsers)->not->toBeNull();
    expect($prepUsers['placeholder'])->toBe('users');

    // char 24 is $user
    $prepUserIter = $provider->prepareRename($doc, ['line' => 10, 'character' => 24]);
    expect($prepUserIter)->not->toBeNull();
    expect($prepUserIter['placeholder'])->toBe('user');

    // 2. Reserved variables (Line 11):
    // $loop (char 15)
    expect($provider->prepareRename($doc, ['line' => 11, 'character' => 15]))->toBeNull();
    // $errors (char 33)
    expect($provider->prepareRename($doc, ['line' => 11, 'character' => 33]))->toBeNull();
    // $__env (char 53)
    expect($provider->prepareRename($doc, ['line' => 11, 'character' => 53]))->toBeNull();
    // $this (char 64)
    expect($provider->prepareRename($doc, ['line' => 11, 'character' => 64]))->toBeNull();
    // $app (char 74)
    expect($provider->prepareRename($doc, ['line' => 11, 'character' => 74]))->toBeNull();

    // 3. Comments and Non-PHP contexts:
    // Line 7: PHP single-line comment '// $user in single line comment' (char 13)
    expect($provider->prepareRename($doc, ['line' => 7, 'character' => 13]))->toBeNull();
    // Line 8: PHP multi-line comment '/* $user in multi line comment */' (char 13)
    expect($provider->prepareRename($doc, ['line' => 8, 'character' => 13]))->toBeNull();
    // Line 13: Blade comment '{{-- $user in blade comment --}}' (char 10)
    expect($provider->prepareRename($doc, ['line' => 13, 'character' => 10]))->toBeNull();
    // Line 14: <script> block '    <script>var $user = 123;</script>' (char 17)
    expect($provider->prepareRename($doc, ['line' => 14, 'character' => 17]))->toBeNull();
    // Line 15: HTML attribute & text '    <div class="$user">Raw text with $user</div>' (char 17)
    expect($provider->prepareRename($doc, ['line' => 15, 'character' => 17]))->toBeNull();
    // Line 15: HTML text (char 39)
    expect($provider->prepareRename($doc, ['line' => 15, 'character' => 39]))->toBeNull();

    // 4. Non-variable symbols:
    // Line 16: '    {{ $user->run() }}' (cursor on 'run' char 15)
    expect($provider->prepareRename($doc, ['line' => 16, 'character' => 15]))->toBeNull();
    // Line 17: '    {{ User::class }}' (cursor on 'User' char 8)
    expect($provider->prepareRename($doc, ['line' => 17, 'character' => 8]))->toBeNull();
    // Line 18: '    {{ $user->name }}' (cursor on 'name' char 15)
    expect($provider->prepareRename($doc, ['line' => 18, 'character' => 15]))->toBeNull();
});

