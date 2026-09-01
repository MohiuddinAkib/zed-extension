<?php

declare(strict_types=1);

use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberLinkProvider;
use App\Lsp\Methods\TextDocumentDefinition;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

test('BladeMemberCompletionProvider suggests macros on static facades and instance variables', function () {
    $tempDir = sys_get_temp_dir() . '/macro_comp_' . uniqid();
    @mkdir($tempDir . '/app/Providers', 0777, true);
    @mkdir($tempDir . '/resources/views', 0777, true);

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PendingRequest::macro('withCaching', function (int $ttl = 3600): PendingRequest {
            return $this->withOptions(['cache_ttl' => $ttl]);
        });

        Http::macro('smsq', function (string $to): PendingRequest {
            return Http::baseUrl('https://sms.api');
        });
    }
}
PHP;
    file_put_contents($tempDir . '/app/Providers/AppServiceProvider.php', $providerCode);

    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $macroRegistry = new MacroRegistry($project);
    $container = Container::getInstance();
    $container->instance(MacroRegistry::class, $macroRegistry);
    $container->instance(Project::class, $project);

    $memberProvider = new BladeMemberCompletionProvider($project);

    // 1. Static facade Http::smsq and Http::withCaching
    $code1 = <<<'PHP'
<?php
use Illuminate\Support\Facades\Http;
Http::s
PHP;
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.php', $code1);
    $items1 = $memberProvider->get($doc1, ['line' => 2, 'character' => 7]); // at Http::s|
    $labels1 = array_column($items1, 'label');

    expect($labels1)->toContain('smsq');

    // 2. Instance $client->withCaching
    $code2 = <<<'PHP'
<?php
/** @var \Illuminate\Http\Client\PendingRequest $client */
$client->withC
PHP;
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.php', $code2);
    $items2 = $memberProvider->get($doc2, ['line' => 2, 'character' => 14]); // at $client->withC|
    $labels2 = array_column($items2, 'label');

    expect($labels2)->toContain('withCaching');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('TextDocumentDefinition jumps to exact macro registration line for static and instance calls', function () {
    $tempDir = sys_get_temp_dir() . '/macro_def_' . uniqid();
    @mkdir($tempDir . '/app/Providers', 0777, true);
    @mkdir($tempDir . '/resources/views', 0777, true);

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PendingRequest::macro('withCaching', function (int $ttl = 3600): PendingRequest {
            return $this->withOptions(['cache_ttl' => $ttl]);
        });

        Http::macro('smsq', function (string $to): PendingRequest {
            return Http::baseUrl('https://sms.api');
        });
    }
}
PHP;
    file_put_contents($tempDir . '/app/Providers/AppServiceProvider.php', $providerCode);

    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $macroRegistry = new MacroRegistry($project);
    $container = Container::getInstance();
    $container->instance(MacroRegistry::class, $macroRegistry);
    $container->instance(Project::class, $project);

    $features = new FeatureRegistry($container);
    $documents = new DocumentManager();

    $code = <<<'PHP'
<?php
use Illuminate\Support\Facades\Http;
/** @var \Illuminate\Http\Client\PendingRequest $client */

Http::smsq('12345');
$client->withCaching(60);
PHP;
    $docUri = 'file://' . $tempDir . '/resources/views/test.php';
    $doc = new Document($docUri, $code);
    $documents->open($docUri, $code);

    $definitionHandler = new TextDocumentDefinition($documents, $features, $project);

    // 1. Jump on Http::smsq (line 4, character 7)
    $req1 = new JsonRpcRequest(1, 'textDocument/definition', [
        'textDocument' => ['uri' => $docUri],
        'position'     => ['line' => 4, 'character' => 7],
    ]);
    $res1 = $definitionHandler->handle($req1);
    $result1 = $res1->toArray()['result'] ?? [];

    expect($result1)->not->toBeEmpty();
    expect($result1[0]['targetUri'])->toContain('AppServiceProvider.php');
    expect($result1[0]['targetRange']['start']['line'])->toBe(16); // line 17 is 0-indexed 16

    // 2. Jump on $client->withCaching (line 5, character 12)
    $req2 = new JsonRpcRequest(2, 'textDocument/definition', [
        'textDocument' => ['uri' => $docUri],
        'position'     => ['line' => 5, 'character' => 12],
    ]);
    $res2 = $definitionHandler->handle($req2);
    $result2 = $res2->toArray()['result'] ?? [];

    expect($result2)->not->toBeEmpty();
    expect($result2[0]['targetUri'])->toContain('AppServiceProvider.php');
    expect($result2[0]['targetRange']['start']['line'])->toBe(12); // line 13 is 0-indexed 12

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('BladeMemberHoverProvider and BladeMemberLinkProvider resolve macro hover card and links in Blade templates', function () {
    $tempDir = sys_get_temp_dir() . '/macro_hover_' . uniqid();
    @mkdir($tempDir . '/app/Providers', 0777, true);
    @mkdir($tempDir . '/resources/views', 0777, true);

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Client\PendingRequest;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PendingRequest::macro('withCaching', function (int $ttl = 3600): PendingRequest {
            return $this->withOptions(['cache_ttl' => $ttl]);
        });
    }
}
PHP;
    file_put_contents($tempDir . '/app/Providers/AppServiceProvider.php', $providerCode);

    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'test' => [
                'variables' => [
                    'client' => [
                        'name' => 'client',
                        'type' => 'Illuminate\\Http\\Client\\PendingRequest',
                        'origin' => 'Property',
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

    $macroRegistry = new MacroRegistry($project);
    $container = Container::getInstance();
    $container->instance(MacroRegistry::class, $macroRegistry);
    $container->instance(Project::class, $project);

    $hoverProvider = new BladeMemberHoverProvider($project);
    $linkProvider = new BladeMemberLinkProvider($project);

    $bladeCode = <<<'BLADE'
{{ $client->withCaching(120) }}
BLADE;
    $bladeDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $bladeCode);

    // 1. Hover on 'withCaching' (character 14)
    $hover = $hoverProvider->get($bladeDoc, ['line' => 0, 'character' => 14]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('PendingRequest::withCaching()')
        ->toContain('Origin:* `Macro (PendingRequest)`')
        ->toContain('AppServiceProvider.php:12');

    // 2. Links on withCaching
    $links = $linkProvider->get($bladeDoc);
    expect($links)->not->toBeEmpty();
    $targetLink = collect($links)->first(fn ($l) => str_contains($l['target'], 'AppServiceProvider.php#L12'));
    expect($targetLink)->not->toBeNull();

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('FeatureRegistry binds MacroRegistry singleton correctly', function () {
    $container = new Container();
    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $project = new Project(FileUri::of('/fake/path'), [], $mockIndex, new ScriptRunner('/fake/path', ['php']));
    $container->instance(Project::class, $project);

    $features = new FeatureRegistry($container);

    expect($container->bound(MacroRegistry::class))->toBeTrue();
    $registry1 = $features->macroRegistry();
    $registry2 = $container->make(MacroRegistry::class);

    expect($registry1)->toBeInstanceOf(MacroRegistry::class);
    expect($registry1)->toBe($registry2);
});
