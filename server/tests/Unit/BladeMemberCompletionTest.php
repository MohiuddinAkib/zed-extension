<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('blade member completion provider suggests properties and methods on typed class variables', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/test.blade.php');
    $content = <<<'BLADE'
@php
    /** @var \DateTime $date */
@endphp
{{ $date->format('Y-m-d') }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    // Test cursor right after $date->
    $completions = $provider->get($doc, ['line' => 3, 'character' => 10]);

    expect($completions)->not->toBeEmpty();
    $labels = array_column($completions, 'label');
    expect($labels)->toContain('format', 'modify', 'getTimestamp');
});

test('blade member completion provider suggests keys on typed array/object shapes', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/chat.blade.php');
    $content = <<<'BLADE'
{{ $ticket->sub }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'chat' => [
                'variables' => [
                    'ticket' => [
                        'name' => 'ticket',
                        'type' => 'array{id?: int, subject?: string, status?: string}',
                        'origin' => 'Property',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'chat', 'path' => 'resources/views/chat.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    // Cursor right after $ticket->sub
    $completions = $provider->get($doc, ['line' => 0, 'character' => 14]);

    expect($completions)->not->toBeEmpty();
    $labels = array_column($completions, 'label');
    expect($labels)->toContain('subject');
    expect($labels)->not->toContain('id'); // filtered by prefix 'sub'
});

test('blade member completion provider suggests keys on array bracket index access $device[\'...\']', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/device.blade.php');
    $content = <<<'BLADE'
{{ $device['ip'] }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'device' => [
                'variables' => [
                    'device' => [
                        'name' => 'device',
                        'type' => 'array{ip: string, user_agent: string}',
                        'origin' => 'Property',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'device', 'path' => 'resources/views/device.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    // 1. Cursor right after $device[ (character 11, no quotes yet)
    $completionsNoQuote = $provider->get($doc, ['line' => 0, 'character' => 11]);
    expect($completionsNoQuote)->not->toBeEmpty();
    $labelsNoQuote = array_column($completionsNoQuote, 'label');
    expect($labelsNoQuote)->toContain("'ip'", "'user_agent'");
    $ipNoQuote = collect($completionsNoQuote)->firstWhere('label', "'ip'");
    expect($ipNoQuote['textEdit']['newText'])->toBe("'ip'");

    // 2. Cursor right after $device[' (character 12, quote opened)
    $completions1 = $provider->get($doc, ['line' => 0, 'character' => 12]);
    expect($completions1)->not->toBeEmpty();
    $labels1 = array_column($completions1, 'label');
    expect($labels1)->toContain('ip', 'user_agent');
    $ipItem = collect($completions1)->firstWhere('label', 'ip');
    expect($ipItem['textEdit']['newText'])->toBe('ip');
    expect($ipItem['detail'])->toBe('string');

    // 3. Cursor with prefix $device['i (character 13)
    $completions2 = $provider->get($doc, ['line' => 0, 'character' => 13]);
    $labels2 = array_column($completions2, 'label');
    expect($labels2)->toContain('ip');
    expect($labels2)->not->toContain('user_agent');
});

