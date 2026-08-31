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

test('Blade higher order collection proxies and tap proxy provide autocompletion and hover previews', function () {
    $tempDir = sys_get_temp_dir() . '/blade_hop_test_' . uniqid();
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
            'relations' => [
                ['name' => 'posts', 'type' => 'HasMany', 'related' => 'App\Models\Post'],
            ],
        ],
    ]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $blade = <<<'BLADE'
@use('App\Models\User')
@php
    $users = User::all();
    $user = $users->first();
    $userNames = $users->map->name;
    $firstUser = $users->first->name;
    $total = $users->sum->id;
@endphp

{{ $users-> }}
{{ $users->map-> }}
{{ $users->each-> }}
{{ $users->filter-> }}
{{ $users->sortBy-> }}
{{ tap($user)-> }}
{{ $users->map->name }}
BLADE;

    $document = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $blade);

    // 1. Check AST inferred types
    $analyzer = new BladeAstAnalyzer();
    $symbols = $analyzer->extractTemplateSymbols($blade, 'resources/views/test.blade.php');

    expect($symbols)->toHaveKey('userNames');
    expect((string) $symbols['userNames']->type)->toBe('\Illuminate\Support\Collection');

    expect($symbols)->toHaveKey('firstUser');
    expect((string) $symbols['firstUser']->type)->toBe('\App\Models\User');

    expect($symbols)->toHaveKey('total');
    expect((string) $symbols['total']->type)->toBe('int|float');

    $compProvider = new BladeMemberCompletionProvider($project);

    // 2. Autocompletion on $users-> (line 9) suggests proxy properties: map, each, filter, sortBy, sum, tap
    $rootCompletions = $compProvider->get($document, ['line' => 9, 'character' => 11]);
    $rootLabels = array_map(fn ($c) => $c['label'], $rootCompletions);
    expect($rootLabels)->toContain('map', 'each', 'filter', 'sortBy', 'sum', 'tap');

    $mapItem = collect($rootCompletions)->firstWhere('label', 'map');
    expect($mapItem['detail'])->toContain('HigherOrderCollectionProxy');

    // 3. Autocompletion on $users->map-> (line 10) suggests User attributes and relations
    $mapCompletions = $compProvider->get($document, ['line' => 10, 'character' => 16]);
    $mapLabels = array_map(fn ($c) => $c['label'], $mapCompletions);
    expect($mapLabels)->toContain('name', 'email', 'id', 'posts');

    // 4. Autocompletion on $users->each-> (line 11) suggests User members
    $eachCompletions = $compProvider->get($document, ['line' => 11, 'character' => 17]);
    $eachLabels = array_map(fn ($c) => $c['label'], $eachCompletions);
    expect($eachLabels)->toContain('name', 'email', 'id', 'posts');

    // 5. Autocompletion on $users->filter-> (line 12) suggests User members
    $filterCompletions = $compProvider->get($document, ['line' => 12, 'character' => 19]);
    $filterLabels = array_map(fn ($c) => $c['label'], $filterCompletions);
    expect($filterLabels)->toContain('name', 'email', 'id');

    // 6. Autocompletion on $users->sortBy-> (line 13) suggests User members
    $sortByCompletions = $compProvider->get($document, ['line' => 13, 'character' => 19]);
    $sortByLabels = array_map(fn ($c) => $c['label'], $sortByCompletions);
    expect($sortByLabels)->toContain('name', 'email', 'id');

    // 7. Autocompletion on tap($user)-> (line 14) suggests User members
    $tapCompletions = $compProvider->get($document, ['line' => 14, 'character' => 15]);
    $tapLabels = array_map(fn ($c) => $c['label'], $tapCompletions);
    expect($tapLabels)->toContain('name', 'email', 'id', 'posts');

    // 8. Hover tests
    $hoverProvider = new BladeMemberHoverProvider($project);

    // Hover on "map" in "{{ $users->map->name }}" (line 15)
    // "{{ $users->map->name }}" -> "map" is characters 11-14
    $mapHover = $hoverProvider->get($document, ['line' => 15, 'character' => 12]); // on "map"
    expect($mapHover)->not->toBeNull();
    expect($mapHover['contents']['value'])->toContain('Higher Order Collection Proxy (`map`)');
    expect($mapHover['contents']['value'])->toContain('App\Models\User');

    // Hover on "name" in "{{ $users->map->name }}" (line 15)
    // "name" is characters 16-20
    $nameHover = $hoverProvider->get($document, ['line' => 15, 'character' => 17]); // on "name"
    expect($nameHover)->not->toBeNull();
    expect($nameHover['contents']['value'])->toContain('App\Models\User::$name');

    @unlink($tempDir);
});
