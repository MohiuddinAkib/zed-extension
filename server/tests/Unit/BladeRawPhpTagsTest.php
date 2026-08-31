<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Mockery;

test('blade ast analyzer extracts variables from raw php tags', function () {
    $analyzer = new BladeAstAnalyzer();

    $content = <<<'BLADE'
<?php
$name = 'akib';
$age = 25;
$isActive = true;
?>

<div>
    {{ $name }}
</div>
BLADE;

    $symbols = $analyzer->extractTemplateSymbols($content, 'resources/views/test.blade.php');

    expect($symbols)->toHaveKeys(['name', 'age', 'isActive']);
    expect((string) $symbols['name']->type)->toBe('string');
    expect((string) $symbols['age']->type)->toBe('int');
    expect((string) $symbols['isActive']->type)->toBe('bool');
    expect($symbols['name']->origin->name)->toBe('<?php');
});

test('blade scope resolver and completion provider provide autocompletion for variables in raw php tags', function () {
    $tempDir = sys_get_temp_dir() . '/blade_raw_php_test_' . uniqid();
    @mkdir($tempDir, 0777, true);

    $content = <<<'BLADE'
<?php
$name = 'akib';
?>

{{ $title }}

{{ $}}
BLADE;

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'test' => [
                'key' => 'test',
                'variables' => [
                    'title' => [
                        'name' => 'title',
                        'type' => 'string',
                        'origin' => 'compact()',
                    ],
                ],
                'sources' => ['routes/web.php'],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $document = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $content);

    $provider = new BladeVariableCompletionProvider($project);

    // Line 6 is 0-indexed line 6 ("{{ $}}"), cursor right after "$" (character 4)
    $items = $provider->get($document, ['line' => 6, 'character' => 4]);

    $labels = array_map(fn ($item) => $item['label'], $items);

    expect($labels)->toContain('$name', '$title');

    $nameItem = collect($items)->firstWhere('label', '$name');
    expect($nameItem)->not->toBeNull();
    expect($nameItem['documentation']['value'])->toContain('@var string $name');

    @unlink($tempDir);
});
