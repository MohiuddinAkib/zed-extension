<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Document;
use App\Lsp\Features\AppBindings\AppBindingDocumentMapper;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberLinkProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('app binding completions preserve suggestions with filterText and support dot syntax', function () {
    $tempDir = sys_get_temp_dir() . '/app_binding_dot_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([
        'db' => ['class' => 'Illuminate\\Database\\DatabaseManager'],
        'db.connection' => ['class' => 'Illuminate\\Database\\ConnectionInterface'],
        'db.schema' => ['class' => 'Illuminate\\Database\\Schema\\Builder'],
        'auth' => ['class' => 'Illuminate\\Auth\\AuthManager'],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $mapper = new AppBindingDocumentMapper($project);

    $doc = new Document('file://' . $tempDir . '/routes/web.php', "<?php\napp('db.');");
    $items = $mapper->completions($doc, ['line' => 1, 'character' => 8]);

    expect($items)->not->toBeEmpty();
    $labels = collect($items)->pluck('label')->all();
    expect($labels)->toContain('db.connection')
        ->toContain('db.schema')
        ->toContain('db');

    $dbConn = collect($items)->firstWhere('label', 'db.connection');
    expect($dbConn['filterText'])->toBe('db.connection');
    expect($dbConn['detail'])->toBe('Illuminate\Database\ConnectionInterface');
});

test('blade member completion provider autocompletes methods on container binding call app(db.connection)->', function () {
    $tempDir = sys_get_temp_dir() . '/app_binding_member_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/test.blade.php');
    $content = <<<'BLADE'
{{ app('db.connection')-> }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    $completions = $provider->get($doc, ['line' => 0, 'character' => 25]);
    expect($completions)->not->toBeEmpty();

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('getDatabaseName')
        ->toContain('select')
        ->toContain('table');
});

test('blade member hover and link providers resolve container binding call member app(db.connection)->getDatabaseName()', function () {
    $tempDir = sys_get_temp_dir() . '/app_binding_hover_test_' . uniqid();
    mkdir($tempDir . '/resources/views', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $hoverProvider = new BladeMemberHoverProvider($project);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/test.blade.php');
    $content = <<<'BLADE'
{{ app('db.connection')->getDatabaseName() }}
BLADE;
    $doc = new Document((string) $viewUri, $content);

    // Hover over 'getDatabaseName' (char 28)
    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 28]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('Illuminate\Database\ConnectionInterface::getDatabaseName()')
        ->toContain('public function getDatabaseName');
});
