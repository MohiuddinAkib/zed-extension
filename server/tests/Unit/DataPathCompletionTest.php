<?php

declare(strict_types=1);

use App\Lsp\Analysis\DataPathResolver;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\DataPaths\DataPathCompletionProvider;
use App\Lsp\Methods\TextDocumentCompletion;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

function createDataPathTestProject(string $tempDir): Project
{
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('configs')->andReturn(['configs' => collect([]), 'paths' => collect([])]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\Models\User' => [
            'class' => 'App\Models\User',
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
            'relations' => [
                ['name' => 'profile', 'type' => 'HasOne', 'related' => 'App\Models\Profile'],
                ['name' => 'posts', 'type' => 'HasMany', 'related' => 'App\Models\Post'],
            ],
        ],
        'App\Models\Profile' => [
            'class' => 'App\Models\Profile',
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'bio', 'type' => 'string'],
                ['name' => 'city', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);

    return new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
}

test('DataPathResolver resolves keys and traverses nested array/object shapes with optional keys', function () {
    $resolver = new DataPathResolver();

    // 1. Array shape with nested shape and optional key
    $shapeType = TypeRef::fromString('array{id: int, profile: array{name: string, bio?: string, address: array{city: string, zip?: string}}}');
    expect($shapeType->isShape())->toBeTrue();

    // Root keys
    $rootKeys = $resolver->resolveKeysForType($shapeType);
    expect(array_keys($rootKeys))->toEqualCanonicalizing(['id', 'profile']);
    expect($rootKeys['id']['type']->displayName)->toBe('int');
    expect($rootKeys['id']['isOptional'])->toBeFalse();
    expect($rootKeys['profile']['type']->isShape())->toBeTrue();

    // Traversal: profile -> address
    $addressType = $resolver->traversePath($shapeType, ['profile', 'address']);
    expect($addressType)->not->toBeNull();
    expect($addressType->isShape())->toBeTrue();

    $addressKeys = $resolver->resolveKeysForType($addressType);
    expect(array_keys($addressKeys))->toEqualCanonicalizing(['city', 'zip']);
    expect($addressKeys['city']['type']->displayName)->toBe('string');
    expect($addressKeys['city']['isOptional'])->toBeFalse();
    expect($addressKeys['zip']['type']->displayName)->toBe('string');
    expect($addressKeys['zip']['isOptional'])->toBeTrue();

    // Traversal to non-existent key returns null
    expect($resolver->traversePath($shapeType, ['profile', 'non_existent']))->toBeNull();

    // 2. Object shape
    $objShape = TypeRef::fromString('object{user: array{name: string, role: string}, count: int}');
    $objKeys = $resolver->resolveKeysForType($objShape);
    expect(array_keys($objKeys))->toEqualCanonicalizing(['user', 'count']);
});

test('DataPathCompletionProvider completes root and nested segments from inline array literals', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $provider = new DataPathCompletionProvider($project);

    // 1. Root segment partial: data_get(['profile' => ['address' => ['city' => 'NY']]], 'pro')
    $code1 = "<?php data_get(['profile' => ['address' => ['city' => 'NY']]], 'pro');";
    $doc1 = new Document('file://' . $tempDir . '/test.php', $code1);
    $char1 = strrpos($code1, "'pro") + strlen("'pro");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => $char1]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('profile');

    $profItem = collect($items1)->firstWhere('label', 'profile');
    expect($profItem['textEdit']['newText'])->toBe('profile');
    // Replacement range spans 'pro'
    expect($profItem['textEdit']['range'])->toEqual([
        'start' => ['line' => 0, 'character' => $char1 - 3],
        'end'   => ['line' => 0, 'character' => $char1],
    ]);

    // 2. Nested segment after dot: data_get(['profile' => ['address' => ['city' => 'NY']]], 'profile.')
    $code2 = "<?php data_get(['profile' => ['address' => ['city' => 'NY']]], 'profile.');";
    $doc2 = new Document('file://' . $tempDir . '/test.php', $code2);
    $char2 = strrpos($code2, "'profile.") + strlen("'profile.");
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => $char2]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('address');

    $addrItem = collect($items2)->firstWhere('label', 'address');
    expect($addrItem['textEdit']['newText'])->toBe('address');
    expect($addrItem['textEdit']['range'])->toEqual([
        'start' => ['line' => 0, 'character' => $char2],
        'end'   => ['line' => 0, 'character' => $char2],
    ]);

    // 3. Deeply nested partial segment: data_get(['profile' => ['address' => ['city' => 'NY']]], 'profile.address.ci')
    $code3 = "<?php data_get(['profile' => ['address' => ['city' => 'NY']]], 'profile.address.ci');";
    $doc3 = new Document('file://' . $tempDir . '/test.php', $code3);
    $char3 = strrpos($code3, "'profile.address.ci") + strlen("'profile.address.ci");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => $char3]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('city');

    $cityItem = collect($items3)->firstWhere('label', 'city');
    expect($cityItem['textEdit']['newText'])->toBe('city');
    expect($cityItem['textEdit']['range'])->toEqual([
        'start' => ['line' => 0, 'character' => $char3 - 2],
        'end'   => ['line' => 0, 'character' => $char3],
    ]);

    // 4. Double quoted string: data_get(['user' => ['name' => 'Alice']], "user.na")
    $code4 = '<?php data_get([\'user\' => [\'name\' => \'Alice\']], "user.na");';
    $doc4 = new Document('file://' . $tempDir . '/test.php', $code4);
    $char4 = strrpos($code4, '"user.na') + strlen('"user.na');
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => $char4]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('name');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('DataPathCompletionProvider completes from PHPDoc shapes and local variable assignments', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_doc_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $provider = new DataPathCompletionProvider($project);

    // 1. Variable typed via @var docblock
    $content1 = <<<'PHP'
<?php
/** @var array{user: array{id: int, profile: array{name: string, bio?: string}}} $data */
Arr::get($data, 'user.profile.');
PHP;
    $doc1 = new Document('file://' . $tempDir . '/test.php', $content1);
    $lines1 = explode("\n", $content1);
    $char1 = strrpos($lines1[2], "'user.profile.") + strlen("'user.profile.");
    $items1 = $provider->get($doc1, ['line' => 2, 'character' => $char1]); // at 'user.profile.|'
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('name', 'bio');

    $bioItem = collect($items1)->firstWhere('label', 'bio');
    expect($bioItem['detail'])->toContain('optional');

    // 2. Variable assigned in local scope
    $content2 = <<<'PHP'
<?php
$config = ['database' => ['host' => 'localhost', 'port' => 3306]];
data_get($config, 'database.h');
PHP;
    $doc2 = new Document('file://' . $tempDir . '/test.php', $content2);
    $lines2 = explode("\n", $content2);
    $char2 = strrpos($lines2[2], "'database.h") + strlen("'database.h");
    $items2 = $provider->get($doc2, ['line' => 2, 'character' => $char2]); // at 'database.h|'
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('host');
    expect($labels2)->not->toContain('port');

    // 3. Eloquent Model properties & relations
    $content3 = <<<'PHP'
<?php
/** @var \App\Models\User $user */
data_get($user, 'profile.');
PHP;
    $doc3 = new Document('file://' . $tempDir . '/test.php', $content3);
    $lines3 = explode("\n", $content3);
    $char3 = strrpos($lines3[2], "'profile.") + strlen("'profile.");
    $items3 = $provider->get($doc3, ['line' => 2, 'character' => $char3]); // at 'profile.|'
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('bio', 'city');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('DataPathCompletionProvider handles all supported global data_* and Arr::* methods', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_helpers_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $provider = new DataPathCompletionProvider($project);

    $helpers = ['data_get', 'data_set', 'data_fill', 'data_has', 'data_forget'];
    foreach ($helpers as $fn) {
        $code = "<?php {$fn}(['key' => 'value'], 'k');";
        $doc = new Document('file://' . $tempDir . '/test.php', $code);
        $char = strrpos($code, "'k") + strlen("'k");
        $items = $provider->get($doc, ['line' => 0, 'character' => $char]);
        $labels = array_column($items, 'label');
        expect($labels)->toContain('key');
    }

    $arrMethods = ['get', 'set', 'add', 'has', 'hasAny', 'forget', 'pull', 'only', 'except'];
    foreach ($arrMethods as $method) {
        $code = "<?php Arr::{$method}(['key' => 'value'], 'k');";
        $doc = new Document('file://' . $tempDir . '/test.php', $code);
        $char = strrpos($code, "'k") + strlen("'k");
        $items = $provider->get($doc, ['line' => 0, 'character' => $char]);
        $labels = array_column($items, 'label');
        expect($labels)->toContain('key');
    }

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('fluent() helper provides key completions for methods and typed accessors, dynamic property completions, and typed downstream chaining', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_fluent_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $pathProvider = new DataPathCompletionProvider($project);
    $memberProvider = new BladeMemberCompletionProvider($project);

    // 1. fluent($data)->get('...') key completion
    $code1 = "<?php fluent(['title' => 'Hello', 'tags' => ['php', 'laravel']])->get('t');";
    $doc1 = new Document('file://' . $tempDir . '/test.php', $code1);
    $char1 = strrpos($code1, "'t") + strlen("'t");
    $items1 = $pathProvider->get($doc1, ['line' => 0, 'character' => $char1]); // at 't|'
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('title', 'tags');

    // 2. fluent($data)->string('...') typed accessor key completion
    $code2 = "<?php fluent(['title' => 'Hello', 'count' => 5])->string('ti');";
    $doc2 = new Document('file://' . $tempDir . '/test.php', $code2);
    $char2 = strrpos($code2, "'ti") + strlen("'ti");
    $items2 = $pathProvider->get($doc2, ['line' => 0, 'character' => $char2]); // at 'ti|'
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('title');

    // 3. $fluent->key dynamic property completion in Blade expressions
    $bladeCode1 = "{{ fluent(['name' => 'Alice', 'role' => 'admin'])-> }}";
    $bladeDoc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $bladeCode1);
    $char3 = strrpos($bladeCode1, '->') + 2;
    $items3 = $memberProvider->get($bladeDoc1, ['line' => 0, 'character' => $char3]); // after fluent(...)->
    $labels3 = array_column($items3, 'label');
    // Inferred dynamic properties
    expect($labels3)->toContain('name', 'role');
    // Fluent methods
    expect($labels3)->toContain('get', 'set', 'has', 'string', 'integer', 'toArray');

    // 4. Downstream chaining on typed accessor: fluent($data)->string('name')->...
    $bladeCode2 = "{{ fluent(['name' => 'Alice'])->string('name')-> }}";
    $bladeDoc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $bladeCode2);
    $char4 = strrpos($bladeCode2, '->') + 2;
    $items4 = $memberProvider->get($bladeDoc2, ['line' => 0, 'character' => $char4]); // after ->string('name')->
    $labels4 = array_column($items4, 'label');
    // Stringable methods
    expect($labels4)->toContain('trim', 'lower', 'upper', 'contains');

    // 5. Downstream chaining on collection accessor: fluent($data)->collection('tags')->...
    $bladeCode3 = "{{ fluent(['tags' => ['php', 'laravel']])->collection('tags')-> }}";
    $bladeDoc3 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $bladeCode3);
    $char5 = strrpos($bladeCode3, '->') + 2;
    $items5 = $memberProvider->get($bladeDoc3, ['line' => 0, 'character' => $char5]); // after ->collection('tags')->
    $labels5 = array_column($items5, 'label');
    // Collection methods
    expect($labels5)->toContain('map', 'filter', 'each', 'first');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('DataPath completions work in Blade expressions, directives, and echoes', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_blade_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $provider = new DataPathCompletionProvider($project);

    // 1. Blade Echo: {{ data_get(['user' => ['name' => 'Alice']], 'user.') }}
    $code1 = '<div>{{ data_get([\'user\' => [\'name\' => \'Alice\']], \'user.\') }}</div>';
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $code1);
    $char1 = strrpos($code1, "'user.") + strlen("'user.");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => $char1]); // at 'user.|'
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('name');

    // 2. Directive: @if(Arr::has(['profile' => ['active' => true]], 'profile.ac'))
    $code2 = '@if(Arr::has([\'profile\' => [\'active\' => true]], \'profile.ac\'))';
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $code2);
    $char2 = strrpos($code2, "'profile.ac") + strlen("'profile.ac");
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => $char2]); // at 'profile.ac|'
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('active');

    // 3. Bound HTML attribute: :user="data_get(['profile' => ['city' => 'NY']], 'profile.')"
    $code3 = '<x-card :user="data_get([\'profile\' => [\'city\' => \'NY\']], \'profile.\')" />';
    $doc3 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $code3);
    $char3 = strrpos($code3, "'profile.") + strlen("'profile.");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => $char3]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('city');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('DataPathResolver infers exact data_get and fluent path return types', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_types_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $resolver = new DataPathResolver($project);

    $content = <<<'PHP'
<?php
/** @var array{user: array{id: int, name: string, active?: bool}, tags: array<int, string>} $data */
$name = data_get($data, 'user.name');
PHP;
    $doc = new Document('file://' . $tempDir . '/test.php', $content);

    $nameType = $resolver->inferExpressionType("data_get(\$data, 'user.name')", $doc, ['line' => 2, 'character' => 0]);
    expect($nameType?->displayName)->toBe('string');

    $missingWithDefault = $resolver->inferExpressionType("data_get(\$data, 'user.email', 'n/a')", $doc, ['line' => 2, 'character' => 0]);
    expect($missingWithDefault?->displayName)->toBe('string');

    $fluentType = $resolver->inferExpressionType("fluent(\$data)", $doc, ['line' => 2, 'character' => 0]);
    expect($fluentType?->displayName)->toBe('\\Illuminate\\Support\\Fluent<array{user: array{id: int, name: string, active?: bool}, tags: array<int, string>}>');

    $fluentValueType = $resolver->inferExpressionType("fluent(\$data)->get('user.id')", $doc, ['line' => 2, 'character' => 0]);
    expect($fluentValueType?->displayName)->toBe('int');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('DataPathCompletionProvider returns empty for dynamic/mixed sources, malformed paths, or invalid argument positions', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_guard_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $provider = new DataPathCompletionProvider($project);

    // 1. Dynamic / unknown variable
    $code1 = '<?php data_get($unknownVariable, "pro");';
    $doc1 = new Document('file://' . $tempDir . '/test.php', $code1);
    $char1 = strrpos($code1, '"pro') + strlen('"pro');
    expect($provider->get($doc1, ['line' => 0, 'character' => $char1]))->toBeEmpty();

    // 2. Argument 2 (default value argument, not path)
    $code2 = '<?php data_get([\'name\' => \'Alice\'], \'name\', \'default\');';
    $doc2 = new Document('file://' . $tempDir . '/test.php', $code2);
    $char2 = strrpos($code2, "'default") + strlen("'default");
    expect($provider->get($doc2, ['line' => 0, 'character' => $char2]))->toBeEmpty();

    // 3. Argument 0 (first argument is not path)
    $code3 = '<?php data_get(\'something\', $data);';
    $doc3 = new Document('file://' . $tempDir . '/test.php', $code3);
    $char3 = strrpos($code3, "'something") + strlen("'something");
    expect($provider->get($doc3, ['line' => 0, 'character' => $char3]))->toBeEmpty();

    // 4. Malformed path segment
    $code4 = '<?php data_get([\'profile\' => [\'name\' => \'Alice\']], \'profile..name\');';
    $doc4 = new Document('file://' . $tempDir . '/test.php', $code4);
    $char4 = strrpos($code4, "'profile..name") + strlen("'profile..name");
    expect($provider->get($doc4, ['line' => 0, 'character' => $char4]))->toBeEmpty();

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('protocol level TextDocumentCompletion returns typed data path completions', function () {
    $tempDir = sys_get_temp_dir() . '/datapath_proto_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createDataPathTestProject($tempDir);
    $container = new Container();
    $container->instance(Project::class, $project);

    $docUri = 'file://' . $tempDir . '/resources/views/test.blade.php';
    $docManager = new DocumentManager();
    $featureRegistry = new FeatureRegistry($container);
    $featureRegistry->completions = [
        DataPathCompletionProvider::class,
        BladeMemberCompletionProvider::class,
    ];

    $handler = new TextDocumentCompletion($docManager, $featureRegistry, $project);

    $code = '<div>{{ data_get([\'user\' => [\'name\' => \'Alice\', \'email\' => \'a@example.com\']], \'user.na\') }}</div>';
    $char = strrpos($code, "'user.na") + strlen("'user.na");
    $docManager->open($docUri, $code);
    $req = new JsonRpcRequest(1, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 0, 'character' => $char],
    ]);
    $resp = $handler->handle($req);
    $res = $resp->toArray()['result'] ?? [];
    $labels = array_column($res, 'label');
    expect($labels)->toContain('name');
    expect($labels)->not->toContain('email');

    @unlink($tempDir . '/resources/views/test.blade.php');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});
