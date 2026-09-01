<?php

declare(strict_types=1);

use App\Lsp\Analysis\ComponentRegistry;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeComponents\BladeComponentCompletionProvider;
use App\Lsp\Features\BladeComponents\BladeComponentDocumentMapper;
use App\Lsp\Features\BladeComponents\BladeComponentHoverProvider;
use App\Lsp\Methods\TextDocumentCompletion;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

function createMailComponentTestProject(string $tempDir, array $components, array $prefixes = ['x-']): Project
{
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn([
        'components' => $components,
        'prefixes'   => $prefixes,
    ]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('configs')->andReturn(['configs' => collect([]), 'paths' => collect([])]);

    return new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
}

test('mail component catalog completion returns all registered mail components', function () {
    $tempDir = sys_get_temp_dir() . '/mail_comp_test_' . uniqid();
    @mkdir($tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html', 0777, true);
    @mkdir($tempDir . '/resources/views/components', 0777, true);

    // Create fixture blade component files
    $buttonBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php';
    file_put_contents($buttonBlade, <<<'BLADE'
    @props([
        'url' => '#',
        'color' => 'primary',
        'align' => 'center',
    ])
    <table class="action" align="{{ $align }}">
        <a href="{{ $url }}" class="button button-{{ $color }}">{{ $slot }}</a>
    </table>
    BLADE);

    $panelBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/panel.blade.php';
    file_put_contents($panelBlade, <<<'BLADE'
    <table class="panel">
        <td class="panel-content">{{ $slot }}</td>
    </table>
    BLADE);

    $tableBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/table.blade.php';
    file_put_contents($tableBlade, <<<'BLADE'
    <div class="table">{{ $slot }}</div>
    BLADE);

    $subcopyBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/subcopy.blade.php';
    file_put_contents($subcopyBlade, <<<'BLADE'
    <table class="subcopy">{{ $slot }}</table>
    BLADE);

    $headerBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/header.blade.php';
    file_put_contents($headerBlade, <<<'BLADE'
    @props(['url'])
    <tr><td class="header"><a href="{{ $url }}">{{ $slot }}</a></td></tr>
    BLADE);

    $footerBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/footer.blade.php';
    file_put_contents($footerBlade, <<<'BLADE'
    <tr><td class="footer">{{ $slot }}</td></tr>
    BLADE);

    $messageBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/message.blade.php';
    file_put_contents($messageBlade, <<<'BLADE'
    @props(['theme' => 'default'])
    <x-mail::layout>
        <x-slot:header><x-mail::header :url="config('app.url')">{{ config('app.name') }}</x-mail::header></x-slot:header>
        {{ $slot }}
        <x-slot:footer><x-mail::footer>© {{ date('Y') }}</x-mail::footer></x-slot:footer>
    </x-mail::layout>
    BLADE);

    $components = [
        'button' => [
            'isVendor' => false,
            'paths'    => ['resources/views/components/button.blade.php'],
            'props'    => [],
        ],
        'mail::message' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/message.blade.php'],
            'props'    => ['theme' => ['name' => 'theme', 'type' => 'string', 'default' => 'default']],
        ],
        'mail::button' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php'],
            'props'    => [
                'url'   => ['name' => 'url', 'type' => 'string', 'default' => '#'],
                'color' => ['name' => 'color', 'type' => 'string', 'default' => 'primary'],
                'align' => ['name' => 'align', 'type' => 'string', 'default' => 'center'],
            ],
        ],
        'mail::panel' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/panel.blade.php'],
            'props'    => [],
        ],
        'mail::table' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/table.blade.php'],
            'props'    => [],
        ],
        'mail::subcopy' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/subcopy.blade.php'],
            'props'    => [],
        ],
        'mail::header' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/header.blade.php'],
            'props'    => ['url' => ['name' => 'url', 'type' => 'string', 'required' => true]],
        ],
        'mail::footer' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/footer.blade.php'],
            'props'    => [],
        ],
    ];

    $project = createMailComponentTestProject($tempDir, $components);
    $provider = new BladeComponentCompletionProvider($project);

    // 1. Bare <x-mail:: returns all mail components and excludes local x-button
    $doc1 = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', '<x-mail::');
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => 9]);
    $labels1 = array_column($items1, 'label');

    expect($labels1)->toContain(
        'x-mail::button',
        'x-mail::message',
        'x-mail::panel',
        'x-mail::table',
        'x-mail::subcopy',
        'x-mail::header',
        'x-mail::footer'
    );
    expect($labels1)->not->toContain('x-button');

    // 2. Partial prefix filtering: <x-mail::bu -> x-mail::button
    $doc2 = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', '<x-mail::bu');
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => 11]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('x-mail::button');
    expect($labels2)->not->toContain('x-mail::panel', 'x-mail::message');

    // 3. Partial prefix filtering: <x-mail::p -> x-mail::panel
    $doc3 = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', '<x-mail::p');
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => 10]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('x-mail::panel');
    expect($labels3)->not->toContain('x-mail::button', 'x-mail::header');

    // 4. Partial prefix filtering: <x-mail::m -> x-mail::message
    $doc4 = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', '<x-mail::m');
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => 10]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('x-mail::message');
    expect($labels4)->not->toContain('x-mail::button', 'x-mail::footer');

    // Clean up
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    @rmdir($tempDir);
});

test('ComponentRegistry extracts props and slots for mail components and supports hover', function () {
    $tempDir = sys_get_temp_dir() . '/mail_reg_test_' . uniqid();
    @mkdir($tempDir . '/vendor/mail_pkg/views/html', 0777, true);

    $buttonFile = $tempDir . '/vendor/mail_pkg/views/html/button.blade.php';
    file_put_contents($buttonFile, <<<'BLADE'
    @props([
        'url' => '#',
        'color' => 'primary',
    ])
    <a href="{{ $url }}" class="btn-{{ $color }}">{{ $slot }}</a>
    BLADE);

    $messageFile = $tempDir . '/vendor/mail_pkg/views/html/message.blade.php';
    file_put_contents($messageFile, <<<'BLADE'
    @props(['theme' => 'default'])
    <div>
        <x-slot:header>Header Slot</x-slot:header>
        {{ $slot }}
        <x-slot:footer>Footer Slot</x-slot:footer>
    </div>
    BLADE);

    $components = [
        'mail::button' => [
            'isVendor' => true,
            'paths'    => [$buttonFile],
            'props'    => [
                'url'   => ['name' => 'url', 'type' => 'string', 'default' => '#'],
                'color' => ['name' => 'color', 'type' => 'string', 'default' => 'primary'],
            ],
        ],
        'mail::message' => [
            'isVendor' => true,
            'paths'    => [$messageFile],
            'props'    => ['theme' => ['name' => 'theme', 'type' => 'string', 'default' => 'default']],
        ],
    ];

    $project = createMailComponentTestProject($tempDir, $components);
    $registry = new ComponentRegistry($project);

    $buttonSym = $registry->getComponent('mail::button');
    expect($buttonSym)->not->toBeNull();
    expect($buttonSym->props)->toHaveKey('url');
    expect($buttonSym->props)->toHaveKey('color');

    $xButtonSym = $registry->getComponent('x-mail::button');
    expect($xButtonSym)->not->toBeNull();

    $messageSym = $registry->getComponent('mail::message');
    expect($messageSym)->not->toBeNull();
    expect($messageSym->slots)->toHaveKey('header');
    expect($messageSym->slots)->toHaveKey('footer');

    // Hover provider on component prop
    $hoverProvider = new BladeComponentHoverProvider($project);
    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-mail::button url="https://example.com" color="success">Click</x-mail::button>');
    $hover = $hoverProvider->get($doc, ['line' => 0, 'character' => 20]); // on url prop
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('url');

    // Clean up
    @unlink($buttonFile);
    @unlink($messageFile);
    @rmdir($tempDir . '/vendor/mail_pkg/views/html');
    @rmdir($tempDir . '/vendor/mail_pkg/views');
    @rmdir($tempDir . '/vendor/mail_pkg');
    @rmdir($tempDir . '/vendor');
    @rmdir($tempDir);
});

test('protocol level TextDocumentCompletion returns mail component tag completions', function () {
    $tempDir = sys_get_temp_dir() . '/mail_proto_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $components = [
        'mail::button' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php'],
            'props'    => [],
        ],
        'mail::message' => [
            'isVendor' => true,
            'paths'    => ['vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/message.blade.php'],
            'props'    => [],
        ],
        'button' => [
            'isVendor' => false,
            'paths'    => ['resources/views/components/button.blade.php'],
            'props'    => [],
        ],
    ];

    $project = createMailComponentTestProject($tempDir, $components);
    $container = new Container();
    $container->instance(Project::class, $project);

    $docUri = 'file://' . $tempDir . '/resources/views/test.blade.php';
    $docManager = new DocumentManager();
    $docManager->open($docUri, '<div><x-mail::bu</div>');

    $featureRegistry = new FeatureRegistry($container);
    $featureRegistry->completions = [BladeComponentCompletionProvider::class];

    $handler = new TextDocumentCompletion($docManager, $featureRegistry, $project);
    $request = new JsonRpcRequest(1, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 0, 'character' => 16], // after <x-mail::bu
    ]);

    $response = $handler->handle($request);
    $result = $response->toArray()['result'] ?? [];

    $labels = array_column($result, 'label');
    expect($labels)->toContain('x-mail::button');
    expect($labels)->not->toContain('x-mail::message');
    expect($labels)->not->toContain('x-button');

    $item = collect($result)->firstWhere('label', 'x-mail::button');
    expect($item['filterText'])->toBe('x-mail::button');
    expect($item['textEdit']['newText'])->toBe('x-mail::button');
    expect($item['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 6], // start of x-mail::bu
        'end'   => ['line' => 0, 'character' => 16],
    ]);

    @unlink($tempDir . '/resources/views/test.blade.php');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('BladeComponents watcher patterns include composer.lock and autoload files', function () {
    $tempDir = sys_get_temp_dir() . '/mail_patterns_' . uniqid();
    @mkdir($tempDir, 0777, true);

    $project = new Project(FileUri::of($tempDir), [], Mockery::mock(ProjectIndex::class), new ScriptRunner($tempDir, ['php']));
    $bladeComponentsData = new \App\Lsp\Data\BladeComponents($project);

    $patterns = $bladeComponentsData->patterns();
    expect($patterns)->toContain('composer.lock');
    expect($patterns)->toContain('vendor/composer/autoload_psr4.php');

    @rmdir($tempDir);
});

test('duplicate discovery sources produce a single deduplicated completion item', function () {
    $tempDir = sys_get_temp_dir() . '/mail_dedup_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    // Component present in both registry and ProjectIndex under the same and alternate tag forms
    $components = [
        'mail::button' => [
            'isVendor' => true,
            'paths'    => [
                'vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php',
                'vendor/laravel/framework/src/Illuminate/Mail/resources/views/components/button.blade.php',
            ],
            'props'    => [],
        ],
    ];

    $project = createMailComponentTestProject($tempDir, $components);
    $provider = new BladeComponentCompletionProvider($project);

    $doc = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', '<x-mail::bu');
    $items = $provider->get($doc, ['line' => 0, 'character' => 11]);

    $buttonItems = array_values(array_filter($items, fn ($i) => $i['label'] === 'x-mail::button'));
    expect($buttonItems)->toHaveCount(1);

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('missing vendor paths do not crash or prevent local component discovery', function () {
    $tempDir = sys_get_temp_dir() . '/mail_missing_' . uniqid();
    @mkdir($tempDir . '/resources/views/components', 0777, true);
    file_put_contents($tempDir . '/resources/views/components/alert.blade.php', '<div>{{ $slot }}</div>');

    $components = [
        'alert' => [
            'isVendor' => false,
            'paths'    => ['resources/views/components/alert.blade.php'],
            'props'    => [],
        ],
        'missing::component' => [
            'isVendor' => true,
            'paths'    => ['non/existent/path/comp.blade.php'],
            'props'    => [],
        ],
    ];

    $project = createMailComponentTestProject($tempDir, $components);
    $registry = new ComponentRegistry($project);

    $alert = $registry->getComponent('alert');
    expect($alert)->not->toBeNull();
    expect($alert->tagName)->toBe('x-alert');

    $missing = $registry->getComponent('missing::component');
    expect($missing)->not->toBeNull();
    expect($missing->tagName)->toBe('x-missing::component');

    @unlink($tempDir . '/resources/views/components/alert.blade.php');
    @rmdir($tempDir . '/resources/views/components');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('mail component tags produce clickable document links pointing to their view files', function () {
    $tempDir = sys_get_temp_dir() . '/mail_links_' . uniqid();
    @mkdir($tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html', 0777, true);
    @mkdir($tempDir . '/resources/views', 0777, true);

    $buttonBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php';
    file_put_contents($buttonBlade, "@props(['url' => '#', 'color' => 'primary'])\n<a href=\"{{ \$url }}\">{{ \$slot }}</a>");

    $project = createMailComponentTestProject($tempDir, []);

    $mapper = new BladeComponentDocumentMapper($project);
    $blade = "<x-mail::button url=\"https://example.com\">Click</x-mail::button>";
    $doc = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', $blade);

    $links = $mapper->links($doc);

    // At least one link pointing to the button blade file
    $buttonLinks = array_values(array_filter($links, fn ($l) => str_contains((string) ($l['target'] ?? ''), 'button.blade.php')));
    expect($buttonLinks)->not->toBeEmpty();
    expect((string) $buttonLinks[0]['target'])->toContain('button.blade.php');

    // Also verify Go to Definition resolves the component file
    $docManager = new DocumentManager();
    $docManager->open($doc->uri, $doc->content);
    $container = new Container();
    $container->instance(Project::class, $project);
    $container->instance(DocumentManager::class, $docManager);
    $featureRegistry = new FeatureRegistry($container);
    $defHandler = new \App\Lsp\Methods\TextDocumentDefinition($docManager, $featureRegistry, $project);
    $defReq = new JsonRpcRequest(1, 'textDocument/definition', [
        'textDocument' => ['uri' => $doc->uri],
        'position'     => ['line' => 0, 'character' => 5], // on 'mail::button'
    ]);
    $defRes = $defHandler->handle($defReq);
    expect($defRes->result)->not->toBeEmpty();
    expect($defRes->result[0]['targetUri'])->toContain('button.blade.php');

    // Also verify hover displays relative path in markdown link
    $hover = $mapper->hover($doc, ['line' => 0, 'character' => 5]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('Laravel mail component: <x-mail::button>');
    expect($hover['contents']['value'])->toContain('[vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php]');

    @unlink($buttonBlade);
    @rmdir($tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('published mail views override vendor defaults for document links and definitions', function () {
    $tempDir = sys_get_temp_dir() . '/mail_published_' . uniqid();
    @mkdir($tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html', 0777, true);
    @mkdir($tempDir . '/resources/views/vendor/mail/html', 0777, true);

    // Vendor default
    $vendorBlade = $tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/button.blade.php';
    file_put_contents($vendorBlade, "@props(['url' => '#'])\n<a href=\"{{ \$url }}\">{{ \$slot }}</a>");

    // Published override (higher priority)
    $publishedBlade = $tempDir . '/resources/views/vendor/mail/html/button.blade.php';
    file_put_contents($publishedBlade, "@props(['url' => '#', 'color' => 'primary'])\n<a href=\"{{ \$url }}\" class=\"{{ \$color }}\">{{ \$slot }}</a>");

    $project = createMailComponentTestProject($tempDir, []);
    $registry = new ComponentRegistry($project);
    $symbol = $registry->getComponent('mail::button');

    expect($symbol)->not->toBeNull();
    // Published path should win
    expect($symbol->viewPath)->toBe($publishedBlade);

    // Verify document link points to published path
    $mapper = new BladeComponentDocumentMapper($project, $registry);
    $blade = "<x-mail::button url=\"https://example.com\">Click</x-mail::button>";
    $doc = new Document('file://' . $tempDir . '/resources/views/mail.blade.php', $blade);
    $links = $mapper->links($doc);

    $buttonLinks = array_values(array_filter($links, fn ($l) => str_contains((string) ($l['target'] ?? ''), 'resources/views/vendor/mail/html/button.blade.php')));
    expect($buttonLinks)->not->toBeEmpty();

    @unlink($vendorBlade);
    @unlink($publishedBlade);
    @rmdir($tempDir . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html');
    @rmdir($tempDir . '/resources/views/vendor/mail/html');
    @rmdir($tempDir . '/resources/views/vendor/mail');
    @rmdir($tempDir . '/resources/views/vendor');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});
