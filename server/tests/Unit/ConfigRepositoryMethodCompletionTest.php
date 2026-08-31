<?php

declare(strict_types=1);

use App\Lsp\Analysis\FunctionTypeResolver;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\Configs\ConfigCompletionProvider;
use App\Lsp\Methods\TextDocumentCompletion;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

function createConfigRepoTestProject(string $tempDir): Project
{
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('configs')->andReturn([
        'configs' => collect([
            [
                'name' => 'app.name',
                'file' => 'config/app.php',
                'line' => 10,
                'value' => 'Laravel',
            ],
            [
                'name' => 'app.timezone',
                'file' => 'config/app.php',
                'line' => 20,
                'value' => 'UTC',
            ],
            [
                'name' => 'database.default',
                'file' => 'config/database.php',
                'line' => 15,
                'value' => 'mysql',
            ],
            [
                'name' => 'services.mailgun.domain',
                'file' => 'config/services.php',
                'line' => 30,
                'value' => 'mg.example.com',
            ],
        ]),
        'paths' => collect([
            'config/app.php',
            'config/database.php',
            'config/services.php',
        ]),
    ]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);

    return new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
}

test('FunctionTypeResolver resolves config() with 0 args to Illuminate\Config\Repository and 1 arg to null/mixed', function () {
    $resolver = new FunctionTypeResolver();

    // 0 arguments -> Repository
    expect($resolver->resolve('config', '', null, 0))->toBe('\Illuminate\Config\Repository');
    expect($resolver->resolve('config', null, null, 0))->toBe('\Illuminate\Config\Repository');
    expect($resolver->resolve('config', ''))->toBe('\Illuminate\Config\Repository');

    // 1 argument (string key) -> null / mixed
    expect($resolver->resolve('config', "'app.name'", null, 1))->toBeNull();
    expect($resolver->resolve('config', "'app.name'"))->toBeNull();

    // 1 argument (variable / dynamic) -> null / mixed
    expect($resolver->resolve('config', '$key', null, 1))->toBeNull();
    expect($resolver->resolve('config', '$key'))->toBeNull();

    // 2 arguments -> null / mixed
    expect($resolver->resolve('config', "'app.name', 'default'", null, 2))->toBeNull();
});

test('config()-> in Blade expressions returns repository method completions with signatures and snippets', function () {
    $tempDir = sys_get_temp_dir() . '/config_repo_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createConfigRepoTestProject($tempDir);
    $provider = new BladeMemberCompletionProvider($project);

    // 1. config()-> returns repository methods like get, set, has, all
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config()-> }}');
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => 13]); // after config()->
    $labels1 = array_column($items1, 'label');

    expect($labels1)->toContain('get', 'set', 'has', 'all');

    $getItem = collect($items1)->firstWhere('label', 'get');
    expect($getItem)->not->toBeNull();
    expect($getItem['kind'])->toBe(2); // Method
    expect($getItem['insertTextFormat'])->toBe(2); // Snippet
    expect($getItem['textEdit']['newText'])->toContain('get(');

    // 2. config()->g filters to matching methods (get)
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config()->g }}');
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => 14]); // after config()->g
    $labels2 = array_column($items2, 'label');

    expect($labels2)->toContain('get');
    expect($labels2)->not->toContain('set', 'all');

    // 3. config($key)-> does not produce unsafe repository methods
    $doc3 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config($key)-> }}');
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => 18]);
    expect($items3)->toBeEmpty();

    // 4. config("app.name")-> does not produce repository methods
    $doc4 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config("app.name")-> }}');
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => 24]);
    expect($items4)->toBeEmpty();

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('config key completion remains active for config("...") while config()-> resolves repository methods', function () {
    $tempDir = sys_get_temp_dir() . '/config_key_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createConfigRepoTestProject($tempDir);
    $configProvider = new ConfigCompletionProvider($project);
    $memberProvider = new BladeMemberCompletionProvider($project);

    // 1. config('') returns indexed config keys
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config(\'\') }}');
    $items1 = $configProvider->get($doc1, ['line' => 0, 'character' => 11]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('app.name', 'app.timezone', 'database.default', 'services.mailgun.domain');

    // 2. config('app.') returns filtered app.* keys
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config(\'app.\') }}');
    $items2 = $configProvider->get($doc2, ['line' => 0, 'character' => 15]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('app.name', 'app.timezone');
    expect($labels2)->not->toContain('database.default', 'services.mailgun.domain');

    // 3. BladeMemberCompletionProvider does NOT intercept config('')
    $memberItems = $memberProvider->get($doc1, ['line' => 0, 'character' => 11]);
    expect($memberItems)->toBeEmpty();

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('protocol level TextDocumentCompletion handles both config key completion and config()-> repository completion', function () {
    $tempDir = sys_get_temp_dir() . '/config_proto_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createConfigRepoTestProject($tempDir);
    $container = new Container();
    $container->instance(Project::class, $project);

    $docUri = 'file://' . $tempDir . '/resources/views/test.blade.php';
    $docManager = new DocumentManager();
    $featureRegistry = new FeatureRegistry($container);
    $featureRegistry->completions = [
        ConfigCompletionProvider::class,
        BladeMemberCompletionProvider::class,
    ];

    $handler = new TextDocumentCompletion($docManager, $featureRegistry, $project);

    // Request 1: config('app.na') -> key completion
    $docManager->open($docUri, '<div>{{ config(\'app.na\') }}</div>');
    $req1 = new JsonRpcRequest(1, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 0, 'character' => 22],
    ]);
    $resp1 = $handler->handle($req1);
    $res1 = $resp1->toArray()['result'] ?? [];
    $labels1 = array_column($res1, 'label');
    expect($labels1)->toContain('app.name');
    expect($labels1)->not->toContain('get', 'set');

    // Request 2: config()->get -> repository method completion
    $docManager->open($docUri, '<div>{{ config()->get }}</div>');
    $req2 = new JsonRpcRequest(2, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 0, 'character' => 21],
    ]);
    $resp2 = $handler->handle($req2);
    $res2 = $resp2->toArray()['result'] ?? [];
    $labels2 = array_column($res2, 'label');
    expect($labels2)->toContain('get');
    @unlink($tempDir . '/resources/views/test.blade.php');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('config()-> completion works inside @php blocks and directive arguments', function () {
    $tempDir = sys_get_temp_dir() . '/config_php_block_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createConfigRepoTestProject($tempDir);
    $provider = new BladeMemberCompletionProvider($project);

    // 1. In @php block
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "@php\n    config()->\n@endphp");
    $items1 = $provider->get($doc1, ['line' => 1, 'character' => 14]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('get', 'set', 'has', 'all');

    // 2. In directive argument: @if(config()->ha)
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '@if(config()->ha)');
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => 15]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('has');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('BladeMemberHoverProvider provides hover info for config()->get', function () {
    $tempDir = sys_get_temp_dir() . '/config_hover_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createConfigRepoTestProject($tempDir);
    $hoverProvider = new BladeMemberHoverProvider($project);

    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{{ config()->get(\'app.name\') }}');
    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 14]); // on 'get'

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('get(');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

