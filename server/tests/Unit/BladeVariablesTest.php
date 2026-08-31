<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeVariableHoverProvider;
use App\Lsp\Features\BladeVariables\BladeVariableRenameProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

function createTestProjectWithIndex(ProjectIndex $index): Project
{
    $container = new Container();
    $uri = FileUri::of('/path/to/laravel-app');
    $scripts = new ScriptRunner($uri->path(), ['php']);

    $project = new Project($uri, [], $index, $scripts);
    $container->instance(Project::class, $project);

    return $project;
}

test('blade variable completion provider suggests globals and @props in blade templates', function () {
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'users.show' => [
                'key' => 'users.show',
                'variables' => [
                    'user' => [
                        'name' => 'user',
                        'type' => '\\App\\Models\\User',
                        'origin' => 'Controller',
                        'source' => 'app/Http/Controllers/UserController.php',
                    ],
                    'posts' => [
                        'name' => 'posts',
                        'type' => '\\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Post>',
                        'origin' => 'Controller',
                        'source' => 'app/Http/Controllers/UserController.php',
                    ],
                ],
                'sources' => ['app/Http/Controllers/UserController.php'],
            ],
        ],
        'globals' => [
            ['name' => '__env', 'type' => '\\Illuminate\\View\\Factory', 'origin' => 'Global'],
            ['name' => 'errors', 'type' => '\\Illuminate\\Support\\ViewErrorBag', 'origin' => 'Global'],
        ],
    ]);

    $project = createTestProjectWithIndex($mockIndex);
    $provider = new BladeVariableCompletionProvider($project);

    $bladeContent = "<h1>User Profile</h1>\n<div>\n    {{ \$\n</div>";
    $document = new Document('file:///path/to/laravel-app/resources/views/users/show.blade.php', $bladeContent);

    $completions = $provider->get($document, ['line' => 2, 'character' => 8]);

    expect($completions)->not->toBeEmpty();

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('$user')
        ->toContain('$posts')
        ->toContain('$__env')
        ->toContain('$errors');

    $userCompletion = collect($completions)->firstWhere('label', '$user');
    expect($userCompletion['detail'])->toBe('\\App\\Models\\User');
    expect($userCompletion['kind'])->toBe(6);
    expect($userCompletion['documentation']['value'])->toContain('@var \\App\Models\\User $user')
        ->toContain('UserController.php');
});

test('blade variable completion provider parses @props dynamically from unsaved document', function () {
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [],
        'globals' => [],
    ]);

    $project = createTestProjectWithIndex($mockIndex);
    $provider = new BladeVariableCompletionProvider($project);

    $bladeContent = "@props(['title', 'type' => 'info', 'active' => true])\n\n<div class=\"alert alert-{{ \$type }}\">\n    {{ \$";
    $document = new Document('file:///path/to/laravel-app/resources/views/components/alert.blade.php', $bladeContent);

    $completions = $provider->get($document, ['line' => 3, 'character' => 8]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('$title')
        ->toContain('$type')
        ->toContain('$active');
});

test('blade variable hover provider returns type and origin markdown with clickable links', function () {
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'users.show' => [
                'key' => 'users.show',
                'variables' => [
                    'user' => [
                        'name' => 'user',
                        'type' => '\\App\\Models\\User',
                        'origin' => 'Controller',
                        'source' => 'app/Http/Controllers/UserController.php',
                        'line' => 34,
                    ],
                ],
                'sources' => ['app/Http/Controllers/UserController.php'],
            ],
        ],
        'globals' => [
            ['name' => 'errors', 'type' => '\\Illuminate\\Support\\ViewErrorBag', 'origin' => 'Global'],
        ],
    ]);

    $project = createTestProjectWithIndex($mockIndex);
    $hoverProvider = new BladeVariableHoverProvider($project);

    $bladeContent = "<h1>{{ \$user->name }}</h1>\n<p>{{ \$errors->first() }}</p>";
    $document = new Document('file:///path/to/laravel-app/resources/views/users/show.blade.php', $bladeContent);

    // Hover over $user
    $hover = $hoverProvider->get($document, ['line' => 0, 'character' => 10]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('$user')
        ->toContain('\\App\\Models\\User')
        ->toContain('[app/Http/Controllers/UserController.php:34]');

    // Hover over $errors
    $errorHover = $hoverProvider->get($document, ['line' => 1, 'character' => 8]);
    expect($errorHover)->not->toBeNull();
    expect($errorHover['contents']['value'])->toContain('$errors')
        ->toContain('ViewErrorBag');
});

test('blade variable rename provider renames variable across template constructs', function () {
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [],
        'globals' => [],
    ]);

    $project = createTestProjectWithIndex($mockIndex);
    $renameProvider = new BladeVariableRenameProvider($project);

    $bladeContent = "<div>\n    <h1>{{ \$user->name }}</h1>\n    @if(\$user->isAdmin())\n        <span>Admin</span>\n    @endif\n    <p>{{ \$otherVar }}</p>\n</div>";
    $document = new Document('file:///path/to/laravel-app/resources/views/users/show.blade.php', $bladeContent);

    // Prepare rename on $user (line 1, col 13)
    $prepare = $renameProvider->prepareRename($document, ['line' => 1, 'character' => 13]);
    expect($prepare)->not->toBeNull();
    expect($prepare['placeholder'])->toBe('user');

    // Perform rename to customer
    $renameResult = $renameProvider->rename($document, ['line' => 1, 'character' => 13], 'customer');
    expect($renameResult)->not->toBeNull();
    expect($renameResult['changes'])->toHaveKey($document->uri);

    $edits = $renameResult['changes'][$document->uri];
    expect(count($edits))->toBe(2);
    expect($edits[0]['newText'])->toBe('customer');
    expect($edits[1]['newText'])->toBe('customer');
});

test('blade variable hover and link provider supports local @php variables and @var overrides', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_local_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/alert.blade.php');
    $bladeContent = <<<'BLADE'
@php
    /** @var array{ip: string} $something */
    $something = [];
    $totalCount = 42;
@endphp
{{ $something }}
{{ $totalCount }}
BLADE;
    $document = new Document((string) $viewUri, $bladeContent);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'alert' => [
                'variables' => [
                    'something' => [
                        'name' => 'something',
                        'type' => 'string', // Controller passed string, but blade overrides with array{ip: string}
                        'origin' => 'Controller',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'alert', 'path' => 'resources/views/alert.blade.php'],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $hoverProvider = new BladeVariableHoverProvider($project);
    $linkProvider = new \App\Lsp\Features\BladeVariables\BladeVariableLinkProvider($project);

    // Hover over $something on line 5
    $hoverSomething = $hoverProvider->get($document, ['line' => 5, 'character' => 5]);
    expect($hoverSomething)->not->toBeNull();
    expect($hoverSomething['contents']['value'])->toContain('array{ip: string}')
        ->toContain('*Origin:* `PHPDoc`');

    // Hover over $totalCount on line 6
    $hoverTotal = $hoverProvider->get($document, ['line' => 6, 'character' => 5]);
    expect($hoverTotal)->not->toBeNull();
    expect($hoverTotal['contents']['value'])->toContain('int')
        ->toContain('*Origin:* `@php`');

    // Links
    file_put_contents($viewUri->path(), $bladeContent);
    $links = $linkProvider->get($document);
    expect($links)->not->toBeEmpty();
    $somethingLink = collect($links)->first(fn ($l) => str_contains($l['target'], '#L2') || str_contains($l['target'], '#L3'));
    expect($somethingLink)->not->toBeNull();

    unlink($viewUri->path());
});
