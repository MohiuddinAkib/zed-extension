<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberLinkProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('hover provider and link provider support app db methods via mixin', function () {
    $tempDir = sys_get_temp_dir() . '/app_db_hover_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/db_test.blade.php');
    $content = <<<'BLADE'
{{ app('db')->select('select 1') }}
{{ app('db.connection')->table('users') }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $hoverProvider = new BladeMemberHoverProvider($project);
    $linkProvider = new BladeMemberLinkProvider($project);

    // 1. Hover on 'select' in app('db')->select (line 0, character 16)
    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 16]);

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('select')
        ->toContain('public function select');

    // 2. Links contains link to Connection.php for select
    $links = $linkProvider->get($doc);
    expect($links)->not->toBeEmpty();

    $selectLink = collect($links)->first(fn ($l) => str_contains($l['tooltip'] ?? '', 'Connection.php'));
    expect($selectLink)->not->toBeNull();
});

test('hover provider and link provider support object properties and methods in directives and attributes', function () {
    $tempDir = sys_get_temp_dir() . '/app_db_hover_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);
    mkdir($tempDir . '/app/Models', 0777, true);

    file_put_contents($tempDir . '/app/Models/User.php', "<?php\nnamespace App\Models;\nclass User {\n    public string \$email = '';\n}");

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/profile.blade.php');
    $content = <<<'BLADE'
@if($user->email)
    <div :title="$user->email">{{ $user->email }}</div>
@endif
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'profile' => [
                'variables' => [
                    'user' => [
                        'name' => 'user',
                        'type' => 'App\\Models\\User',
                        'origin' => 'Property',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'profile', 'path' => 'resources/views/profile.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\User' => [
            'path' => 'app/Models/User.php',
            'line' => 3,
            'attributes' => [
                ['name' => 'email', 'cast' => 'string'],
            ],
            'relations' => [],
        ],
    ]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $hoverProvider = new BladeMemberHoverProvider($project);
    $linkProvider = new BladeMemberLinkProvider($project);

    // 1. Hover on 'email' inside directive @if($user->email) on line 0, char 12
    $hoverDirective = $hoverProvider->get($doc, ['line' => 0, 'character' => 12]);
    expect($hoverDirective)->not->toBeNull();
    expect($hoverDirective['contents']['value'])->toContain('App\Models\User::$email')
        ->toContain('public string $email;');

    // 2. Hover on 'email' inside attribute :title="$user->email" on line 1, char 24
    $hoverAttr = $hoverProvider->get($doc, ['line' => 1, 'character' => 24]);
    expect($hoverAttr)->not->toBeNull();
    expect($hoverAttr['contents']['value'])->toContain('App\Models\User::$email');

    // 3. Links contain links for email
    $links = $linkProvider->get($doc);
    expect($links)->not->toBeEmpty();
});
