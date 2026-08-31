<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('blade member hover provider shows hover card for object properties and methods', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_hover_test_' . uniqid();
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
    $hoverProvider = new BladeMemberHoverProvider($project);

    // Hover over 'format' on line 3: {{ $date->format...
    // Character 12 is inside 'format'
    $hover = $hoverProvider->get($doc, ['line' => 3, 'character' => 12]);

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('DateTime::format()')
        ->toContain('public function format');
});

test('blade member hover provider shows hover card for array shape keys', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_hover_test_' . uniqid();
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
    $hoverProvider = new BladeMemberHoverProvider($project);

    // Hover over 'ip' (character 12)
    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 12]);

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain("\$device['ip']")
        ->toContain('@var string $device[\'ip\']')
        ->toContain('*Origin:* `Array Shape`');
});

test('blade member hover and link providers resolve model attributes with clickable source links', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_hover_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);
    mkdir($tempDir . '/app/Models', 0777, true);

    file_put_contents($tempDir . '/app/Models/SupportTicket.php', "<?php\nnamespace App\Models;\nclass SupportTicket {}");

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/ticket.blade.php');
    $content = <<<'BLADE'
{{ $ticket->id }} {{ $ticket->subject }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'ticket' => [
                'variables' => [
                    'ticket' => [
                        'name' => 'ticket',
                        'type' => 'App\\Models\\SupportTicket',
                        'origin' => 'Property',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'ticket', 'path' => 'resources/views/ticket.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\SupportTicket' => [
            'path' => 'app/Models/SupportTicket.php',
            'line' => 10,
            'attributes' => [
                ['name' => 'id', 'cast' => 'int'],
                ['name' => 'subject', 'cast' => 'string'],
            ],
            'relations' => [],
        ],
    ]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $hoverProvider = new BladeMemberHoverProvider($project);
    $linkProvider = new \App\Lsp\Features\BladeVariables\BladeMemberLinkProvider($project);

    // Hover over 'id' in $ticket->id (character 12)
    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 12]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('App\Models\SupportTicket::$id')
        ->toContain('public int $id;')
        ->toContain('*Source:* [app/Models/SupportTicket.php:10]');

    // Links for line
    $links = $linkProvider->get($doc);
    expect($links)->not->toBeEmpty();
    $idLink = collect($links)->firstWhere('tooltip', 'Go to definition: app/Models/SupportTicket.php:10');
    expect($idLink)->not->toBeNull();
});

test('blade member hover provider shows hover card for all intermediate chain members in $ticket->status?->value', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_member_hover_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);
    mkdir($tempDir . '/app/Models', 0777, true);
    mkdir($tempDir . '/app/Enums', 0777, true);

    file_put_contents($tempDir . '/app/Models/SupportTicket.php', "<?php\nnamespace App\Models;\nclass SupportTicket {}");

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/ticket.blade.php');
    $content = <<<'BLADE'
{{ $ticket->status?->value }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'ticket' => [
                'variables' => [
                    'ticket' => [
                        'name' => 'ticket',
                        'type' => 'App\\Models\\SupportTicket',
                        'origin' => 'Property',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'ticket', 'path' => 'resources/views/ticket.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\SupportTicket' => [
            'path' => 'app/Models/SupportTicket.php',
            'line' => 15,
            'attributes' => [
                ['name' => 'status', 'cast' => 'App\\Enums\\SupportTicketStatus'],
            ],
            'relations' => [],
        ],
    ]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $hoverProvider = new BladeMemberHoverProvider($project);
    $linkProvider = new \App\Lsp\Features\BladeVariables\BladeMemberLinkProvider($project);

    // 1. Hover over 'status' (character 12 in $ticket->status?->value)
    $hoverStatus = $hoverProvider->get($doc, ['line' => 0, 'character' => 12]);
    expect($hoverStatus)->not->toBeNull();
    expect($hoverStatus['contents']['value'])->toContain('App\Models\SupportTicket::$status')
        ->toContain('public App\Enums\SupportTicketStatus $status;')
        ->toContain('*Source:* [app/Models/SupportTicket.php:15]');

    // 2. Links contains status link
    $links = $linkProvider->get($doc);
    expect($links)->not->toBeEmpty();
    $statusLink = collect($links)->firstWhere('tooltip', 'Go to definition: app/Models/SupportTicket.php:15');
    expect($statusLink)->not->toBeNull();
});


