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

test('rename handles directive scopes, loop variables, and nested lexical shadowing accurately', function () {
    $tempDir = sys_get_temp_dir() . '/rename_scopes_' . uniqid();

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    $content = <<<'BLADE'
<p>Top: {{ $item->title }}</p>
@foreach($items as $item)
    <p>Outer item: {{ $item->name }}</p>
    @foreach($item->children as $item)
        <span>Inner item: {{ $item->subName }}</span>
    @endforeach
    <p>Outer item again: {{ $item->name }}</p>
@endforeach
<p>Bottom: {{ $item->title }}</p>
BLADE;

    $doc = new Document('file://' . $tempDir . '/scopes.blade.php', $content);

    // Test 1: Renaming outer template variable $item (cursor at line 0, col 12) to $globalItem
    $resultTemplate = $provider->rename($doc, ['line' => 0, 'character' => 12], 'globalItem');
    expect($resultTemplate)->not->toBeNull();
    $editsTemplate = $resultTemplate['changes'][$doc->uri];
    // Should rename line 0 and line 8 ($item->title), but NOT line 1, 2, 3, 4, 6
    expect($editsTemplate)->toHaveCount(2);
    expect($editsTemplate[0]['range']['start']['line'])->toBe(0);
    expect($editsTemplate[0]['newText'])->toBe('globalItem');
    expect($editsTemplate[1]['range']['start']['line'])->toBe(8);
    expect($editsTemplate[1]['newText'])->toBe('globalItem');

    // Test 2: Renaming outer loop $item (cursor at line 2, col 23) to $parentItem
    $resultOuter = $provider->rename($doc, ['line' => 2, 'character' => 23], 'parentItem');
    expect($resultOuter)->not->toBeNull();
    $editsOuter = $resultOuter['changes'][$doc->uri];
    // Should rename:
    // - Line 1: 'as $item'
    // - Line 2: '$item->name'
    // - Line 3: '$item->children' (source of inner loop)
    // - Line 6: '$item->name'
    // Should NOT rename: line 0, line 3 'as $item', line 4, line 8
    expect($editsOuter)->toHaveCount(4);
    expect($editsOuter[0]['range']['start']['line'])->toBe(1);
    expect($editsOuter[0]['newText'])->toBe('parentItem');
    expect($editsOuter[1]['range']['start']['line'])->toBe(2);
    expect($editsOuter[1]['newText'])->toBe('parentItem');
    expect($editsOuter[2]['range']['start']['line'])->toBe(3);
    expect($editsOuter[2]['newText'])->toBe('parentItem');
    expect($editsOuter[3]['range']['start']['line'])->toBe(6);
    expect($editsOuter[3]['newText'])->toBe('parentItem');

    // Test 3: Renaming inner loop $item (cursor at line 4, col 30) to $child
    $resultInner = $provider->rename($doc, ['line' => 4, 'character' => 30], 'child');
    expect($resultInner)->not->toBeNull();
    $editsInner = $resultInner['changes'][$doc->uri];
    // Should rename:
    // - Line 3: 'as $item' (inner declaration)
    // - Line 4: '$item->subName'
    // Should NOT rename: line 0, 1, 2, 3 ($item->children), 6, 8
    expect($editsInner)->toHaveCount(2);
    expect($editsInner[0]['range']['start']['line'])->toBe(3);
    expect($editsInner[0]['newText'])->toBe('child');
    expect($editsInner[1]['range']['start']['line'])->toBe(4);
    expect($editsInner[1]['newText'])->toBe('child');
});

test('rename handles forelse and for loops correctly', function () {
    $tempDir = sys_get_temp_dir() . '/rename_loops_' . uniqid();

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    // 1. @forelse
    $forelseBlade = <<<'BLADE'
<p>{{ $user->name }}</p>
@forelse($users as $user)
    <span>{{ $user->email }}</span>
@empty
    <p>No users</p>
@endforelse
BLADE;
    $forelseDoc = new Document('file://' . $tempDir . '/forelse.blade.php', $forelseBlade);

    // Rename inner $user inside forelse body (line 2, col 13) to $client
    $resForelse = $provider->rename($forelseDoc, ['line' => 2, 'character' => 13], 'client');
    expect($resForelse)->not->toBeNull();
    $editsForelse = $resForelse['changes'][$forelseDoc->uri];
    expect($editsForelse)->toHaveCount(2);
    expect($editsForelse[0]['range']['start']['line'])->toBe(1);
    expect($editsForelse[0]['newText'])->toBe('client');
    expect($editsForelse[1]['range']['start']['line'])->toBe(2);
    expect($editsForelse[1]['newText'])->toBe('client');

    // 2. @for
    $forBlade = <<<'BLADE'
<p>{{ $i }}</p>
@for($i = 0; $i < 10; $i++)
    <span>Count: {{ $i }}</span>
@endfor
<p>{{ $i }}</p>
BLADE;
    $forDoc = new Document('file://' . $tempDir . '/for.blade.php', $forBlade);

    // Rename $i inside @for body (line 2, col 20) to $index
    $resFor = $provider->rename($forDoc, ['line' => 2, 'character' => 20], 'index');
    expect($resFor)->not->toBeNull();
    $editsFor = $resFor['changes'][$forDoc->uri];
    // 3 occurrences in line 1 header ($i = 0, $i < 10, $i++) and 1 occurrence in line 2
    expect($editsFor)->toHaveCount(4);
    expect($editsFor[0]['range']['start']['line'])->toBe(1);
    expect($editsFor[1]['range']['start']['line'])->toBe(1);
    expect($editsFor[2]['range']['start']['line'])->toBe(1);
    expect($editsFor[3]['range']['start']['line'])->toBe(2);
    foreach ($editsFor as $edit) {
        expect($edit['newText'])->toBe('index');
    }
});

test('textDocument/prepareRename and textDocument/rename LSP protocol handlers function correctly', function () {
    $tempDir = sys_get_temp_dir() . '/rename_lsp_' . uniqid();
    $uri = 'file://' . $tempDir . '/test.blade.php';

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $documents = new \App\Lsp\DocumentManager();
    $documents->open($uri, '<div>{{ $user->name }}</div>');

    // 1. PrepareRename handler
    $prepHandler = new \App\Lsp\Methods\TextDocumentPrepareRename($documents, $project);
    $prepReq = \App\Lsp\Transport\JsonRpcRequest::from([
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'textDocument/prepareRename',
        'params' => [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 0, 'character' => 9],
        ],
    ]);
    $prepRes = $prepHandler->handle($prepReq);
    $prepData = $prepRes->toArray()['result'] ?? [];
    expect($prepData)->toHaveKey('placeholder', 'user');
    expect($prepData)->toHaveKey('range');

    // 2. Rename handler
    $renameHandler = new \App\Lsp\Methods\TextDocumentRename($documents, $project);
    $renameReq = \App\Lsp\Transport\JsonRpcRequest::from([
        'jsonrpc' => '2.0',
        'id' => 11,
        'method' => 'textDocument/rename',
        'params' => [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => 0, 'character' => 9],
            'newName' => '$client',
        ],
    ]);
    $renameRes = $renameHandler->handle($renameReq);
    $renameData = $renameRes->toArray()['result'] ?? [];
    expect($renameData)->toHaveKey('changes');
    expect($renameData['changes'][$uri])->toHaveCount(1);
    expect($renameData['changes'][$uri][0]['newText'])->toBe('client');
});



