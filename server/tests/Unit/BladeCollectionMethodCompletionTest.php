<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('Blade collection variables provide autocompletion for collection methods and chained types', function () {
    $tempDir = sys_get_temp_dir() . '/blade_collection_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\Models\User' => [
            'path' => 'app/Models/User.php',
            'line' => 10,
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $blade = <<<'BLADE'
@use('App\Models\User')
@php
    $name = 'akib';
    $age = 20;
    $users = User::all();
    $user = $users->first();
@endphp

{{ $title }}

{{ $name }}
{{ $age }}

{{ $users-> }}
{{ $user-> }}
BLADE;

    $document = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $blade);

    $analyzer = new BladeAstAnalyzer();
    $symbols = $analyzer->extractTemplateSymbols($blade, 'resources/views/test.blade.php');

    expect($symbols)->toHaveKey('users');
    expect((string) $symbols['users']->type)->toBe('\Illuminate\Database\Eloquent\Collection<int, \App\Models\User>');

    expect($symbols)->toHaveKey('user');
    expect((string) $symbols['user']->type)->toBe('\App\Models\User');

    $compProvider = new BladeMemberCompletionProvider($project);

    // Line 13 is "{{ $users-> }}" (0-indexed line 13), character 11 (right after "->")
    $usersCompletions = $compProvider->get($document, ['line' => 13, 'character' => 11]);
    $usersLabels = array_map(fn ($c) => $c['label'], $usersCompletions);

    expect($usersLabels)->toContain('count', 'all', 'first', 'last', 'where', 'map');

    // Line 14 is "{{ $user-> }}" (0-indexed line 14), character 10 (right after "->")
    $userCompletions = $compProvider->get($document, ['line' => 14, 'character' => 10]);
    $userLabels = array_map(fn ($c) => $c['label'], $userCompletions);

    expect($userLabels)->toContain('name', 'email', 'id');

    // Verify property completion format: plain text without ()
    $nameItem = collect($userCompletions)->firstWhere('label', 'name');
    expect($nameItem)->not->toBeNull();
    expect($nameItem['kind'])->toBe(10);
    expect($nameItem['insertTextFormat'])->toBe(1);
    expect($nameItem['textEdit']['newText'])->toBe('name');

    // Line 13: check collection method completion format: snippet with ()
    $countItem = collect($usersCompletions)->firstWhere('label', 'count');
    expect($countItem)->not->toBeNull();
    expect($countItem['kind'])->toBe(2);
    expect($countItem['insertTextFormat'])->toBe(2);
    expect($countItem['textEdit']['newText'])->toBe('count()');

    // Static member completions: Str::
    $staticCompletions = $compProvider->getStaticMemberCompletions($document, 'Str', '', 50, 10);
    
    // Static method Str::of() -> Snippet with ()
    $ofStaticItem = collect($staticCompletions)->firstWhere('label', 'of');
    expect($ofStaticItem)->not->toBeNull();
    expect($ofStaticItem['kind'])->toBe(2);
    expect($ofStaticItem['insertTextFormat'])->toBe(2);
    expect($ofStaticItem['textEdit']['newText'])->toBe('of(${1})');

    // Pseudo-constant Str::class -> Constant without ()
    $classStaticItem = collect($staticCompletions)->firstWhere('label', 'class');
    expect($classStaticItem)->not->toBeNull();
    expect($classStaticItem['kind'])->toBe(21);
    expect($classStaticItem['insertTextFormat'])->toBe(1);
    expect($classStaticItem['textEdit']['newText'])->toBe('class');

    // Test hover on count()
    $hoverProvider = new BladeMemberHoverProvider($project);
    $hoverDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', str_replace('{{ $users-> }}', '{{ $users->count() }}', $blade));
    $hover = $hoverProvider->get($hoverDoc, ['line' => 13, 'character' => 13]); // on "count"

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('count');

    @unlink($tempDir);
});
