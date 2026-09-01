<?php

declare(strict_types=1);

use App\Lsp\Analysis\DocBlockParser;
use App\Lsp\Analysis\PhpAstViewAnalyzer;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('php ast view analyzer extracts @var docblock annotations on variable assignments in closures', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

Route::view('/test', function() {
    $title = 'hello world';
    /** @var stdClass&object{name: string, age: int} $myObj */
    $myObj = new stdClass();
    $myObj->name = 'akib';
    $myObj->age = 20;

    return view('test', compact('title', 'myObj'));
});
PHP;

    $result = $analyzer->analyze($code, 'routes/web.php');

    expect($result)->toHaveKey('test');
    $vars = $result['test']['variables'];
    expect($vars)->toHaveKeys(['title', 'myObj']);
    expect($vars['myObj']['type'])->toContain('object{name: string, age: int}');
});

test('docblock parser extracts shape keys from ObjectShapeNode and IntersectionTypeNode', function () {
    $parser = new DocBlockParser();

    $keys1 = $parser->extractArrayShapeKeys('object{name: string, age: int}');
    expect($keys1)->toHaveKeys(['name', 'age']);
    expect($keys1['name']['type'])->toBe('string');
    expect($keys1['age']['type'])->toBe('int');

    $keys2 = $parser->extractArrayShapeKeys('\stdClass&object{name: string, age: int}');
    expect($keys2)->toHaveKeys(['name', 'age']);
    expect($keys2['name']['type'])->toBe('string');
    expect($keys2['age']['type'])->toBe('int');

    $keys3 = $parser->extractArrayShapeKeys('(stdClass & object{name: string, age: int})');
    expect($keys3)->toHaveKeys(['name', 'age']);
});

test('blade member completion provider suggests object shape keys and does not suggest loop properties for non-loop objects', function () {
    $tempDir = sys_get_temp_dir() . '/blade_intersection_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/test.blade.php');
    $content = '{{ $myObj-> }}';
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'test' => [
                'variables' => [
                    'myObj' => [
                        'name' => 'myObj',
                        'type' => '\stdClass&object{name: string, age: int}',
                        'origin' => 'compact()',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'test', 'path' => 'resources/views/test.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    $completions = $provider->get($doc, ['line' => 0, 'character' => 11]);
    $labels = array_column($completions, 'label');

    expect($labels)->toContain('name', 'age');
    expect($labels)->not->toContain('count', 'iteration', 'depth', 'even', 'odd');
});

test('blade member completion provider does not suggest loop properties for generic stdClass when var is not loop', function () {
    $tempDir = sys_get_temp_dir() . '/blade_stdclass_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/test.blade.php');
    $content = '{{ $myObj-> }}';
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'test' => [
                'variables' => [
                    'myObj' => [
                        'name' => 'myObj',
                        'type' => '\stdClass',
                        'origin' => 'compact()',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'test', 'path' => 'resources/views/test.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    $completions = $provider->get($doc, ['line' => 0, 'character' => 11]);
    $labels = array_column($completions, 'label');

    expect($labels)->not->toContain('count', 'iteration', 'depth', 'even', 'odd', 'index');
});

test('blade member hover provider displays object shape property hover with correct title and type', function () {
    $tempDir = sys_get_temp_dir() . '/blade_hover_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $viewUri = FileUri::fromPath($tempDir . '/resources/views/test.blade.php');
    $content = '{{ $myObj->name }}';
    $doc = new Document((string) $viewUri, $content);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'test' => [
                'variables' => [
                    'myObj' => [
                        'name' => 'myObj',
                        'type' => '\stdClass&object{name: string, age: int}',
                        'origin' => 'compact()',
                    ],
                ],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'test', 'path' => 'resources/views/test.blade.php'],
    ]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $hoverProvider = new BladeMemberHoverProvider($project);

    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 12]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('$myObj->name');
    expect($hover['contents']['value'])->toContain('string');
});

test('type ref parses intersection types with outer parentheses', function () {
    $ref = \App\Lsp\Semantics\TypeRef::fromString('(stdClass & object{name: string, age: int})');
    expect($ref->kind)->toBe('intersection');
    expect($ref->children)->toHaveCount(2);
    expect($ref->children[0]->displayName)->toBe('stdClass');
    expect($ref->children[1]->kind)->toBe('object-shape');
    expect($ref->children[1]->shape)->toHaveKeys(['name', 'age']);
});

test('data path resolver resolves keys for intersection types', function () {
    $resolver = new \App\Lsp\Analysis\DataPathResolver();
    $typeRef = \App\Lsp\Semantics\TypeRef::fromString('stdClass & object{name: string, age: int}');
    $keys = $resolver->resolveKeysForType($typeRef);

    expect($keys)->toHaveKeys(['name', 'age']);
    expect($keys['name']['type']->displayName)->toBe('string');
    expect($keys['age']['type']->displayName)->toBe('int');
});

test('blade ast analyzer extracts @var docblock annotations inside @php blocks', function () {
    $analyzer = new \App\Lsp\Analysis\BladeAstAnalyzer();

    $template = <<<'BLADE'
@php
    /** @var stdClass&object{name: string, age: int} $myObj */
    $myObj = new stdClass();
@endphp
BLADE;

    $vars = $analyzer->extractTemplateVariables($template);
    expect($vars)->toHaveKey('myObj');
    expect($vars['myObj']['type'])->toContain('object{name: string, age: int}');
});
