<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider;
use App\Lsp\Project;

test('Eloquent models provide static and instance builder method completions and type inference', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_test_eloquent_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);
    @mkdir($tempDir . '/app/Models', 0777, true);

    $mockIndex = \Mockery::mock(\App\Lsp\ProjectIndex::class);
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

    $project = new Project(\App\Lsp\Support\FileUri::of($tempDir), [], $mockIndex, new \App\Lsp\ScriptRunner($tempDir, ['php']));

    $blade = <<<'BLADE'
@use('App\Models\User')
@php
    $query = User::query();
    $users = User::query()->get();
    $user = $users->first();
@endphp

{{ User::whe }}
{{ $query-> }}
{{ $users-> }}
{{ $user-> }}
BLADE;

    file_put_contents($tempDir . '/resources/views/test.blade.php', $blade);
    $document = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $blade);

    $compProvider = new BladeMemberCompletionProvider($project);

    // 1. Test Static completions on User::whe (Line 7, char 14)
    $staticCompletions = $compProvider->getStaticMemberCompletions($document, 'User', 'whe', 7, 14);
    $staticLabels = collect($staticCompletions)->pluck('label')->all();

    expect($staticLabels)->toContain('where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween');

    $whereItem = collect($staticCompletions)->firstWhere('label', 'where');
    expect($whereItem)->not->toBeNull();
    expect($whereItem['kind'])->toBe(2);
    expect($whereItem['insertTextFormat'])->toBe(2);
    expect($whereItem['textEdit']['newText'])->toBe('where(${1})');
    expect($whereItem['labelDetails']['description'])->toContain('Builder');

    // 2. Test Instance completions on $query-> (Builder instance, Line 8, char 11)
    $queryCompletions = $compProvider->get($document, ['line' => 8, 'character' => 11]);
    $queryLabels = collect($queryCompletions)->pluck('label')->all();

    expect($queryLabels)->toContain('where', 'get', 'first', 'paginate', 'latest', 'with', 'count', 'exists');

    $getItem = collect($queryCompletions)->firstWhere('label', 'get');
    expect($getItem)->not->toBeNull();
    expect($getItem['kind'])->toBe(2);
    expect($getItem['insertTextFormat'])->toBe(2);
    expect($getItem['textEdit']['newText'])->toBe('get()');
    expect($getItem['labelDetails']['description'])->toContain('Collection');

    $firstItem = collect($queryCompletions)->firstWhere('label', 'first');
    expect($firstItem)->not->toBeNull();
    expect($firstItem['kind'])->toBe(2);
    expect($firstItem['insertTextFormat'])->toBe(2);
    expect($firstItem['textEdit']['newText'])->toBe('first()');
    expect($firstItem['labelDetails']['description'])->toContain('User');

    // 3. Test completions on $users-> (Collection instance, Line 9, char 11)
    $usersCompletions = $compProvider->get($document, ['line' => 9, 'character' => 11]);
    $usersLabels = collect($usersCompletions)->pluck('label')->all();

    expect($usersLabels)->toContain('first', 'map', 'filter', 'each', 'count', 'pluck');

    // 4. Test Variable type inference
    $varProvider = new BladeVariableCompletionProvider($project);
    $varCompletions = $varProvider->get($document, ['line' => 8, 'character' => 4]);
    
    $queryVar = collect($varCompletions)->firstWhere('label', '$query');
    expect($queryVar)->not->toBeNull();
    expect($queryVar['labelDetails']['description'])->toContain('Builder');

    $usersVar = collect($varCompletions)->firstWhere('label', '$users');
    expect($usersVar)->not->toBeNull();
    expect($usersVar['labelDetails']['description'])->toContain('Collection');

    $userVar = collect($varCompletions)->firstWhere('label', '$user');
    expect($userVar)->not->toBeNull();
    expect($userVar['labelDetails']['description'])->toContain('User');

    // 5. Test Hover Provider
    $hoverProvider = new BladeMemberHoverProvider($project);
    $hoverDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', str_replace('{{ User::whe }}', '{{ User::where("active", true) }}', $blade));
    $hover = $hoverProvider->get($hoverDoc, ['line' => 7, 'character' => 12]); // on "where"

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('where');
    expect($hover['contents']['value'])->toContain('Eloquent Builder');

    @unlink($tempDir);
});
