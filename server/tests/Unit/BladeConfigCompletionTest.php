<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\Configs\ConfigCompletionProvider;
use App\Lsp\Features\Configs\ConfigDiagnosticProvider;
use App\Lsp\Features\Configs\ConfigDocumentMapper;
use App\Lsp\Features\Configs\ConfigHoverProvider;
use App\Lsp\Methods\TextDocumentCompletion;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\CompletionItems;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

function createConfigTestProject(string $tempDir, array $configs): Project
{
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('configs')->andReturn([
        'configs' => collect($configs),
        'paths' => collect(['config/app.php', 'config/services.php']),
    ]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    return new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
}

test('config helper completion works across all Blade expression locations', function () {
    $tempDir = sys_get_temp_dir() . '/config_test_' . uniqid();
    $configs = [
        ['name' => 'app.name', 'value' => 'Laravel App', 'file' => 'config/app.php', 'line' => 5],
        ['name' => 'app.debug', 'value' => true, 'file' => 'config/app.php', 'line' => 10],
        ['name' => 'app.env', 'value' => 'production', 'file' => 'config/app.php', 'line' => 15],
        ['name' => 'services.mail.host', 'value' => 'smtp.mailgun.org', 'file' => 'config/services.php', 'line' => 20],
        ['name' => 'services.mail.port', 'value' => 587, 'file' => 'config/services.php', 'line' => 25],
    ];

    $project = createConfigTestProject($tempDir, $configs);
    $provider = new ConfigCompletionProvider($project);

    // 1. Blade echo: {{ config('') }}
    $doc1 = new Document('file://' . $tempDir . '/resources/views/echo.blade.php', "{{ config('') }}");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => 11]);
    $labels1 = array_column($items1, 'label');

    expect($labels1)->toContain('app.name', 'app.debug', 'app.env', 'services.mail.host', 'services.mail.port');
    $nameItem = collect($items1)->firstWhere('label', 'app.name');
    expect($nameItem['detail'])->toBe('Laravel App');
    expect($nameItem['filterText'])->toBe('app.name');
    expect($nameItem['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 11],
        'end'   => ['line' => 0, 'character' => 11],
    ]);

    // 2. Block @php section: @php\n    $val = config('');\n@endphp
    $phpBlockContent = "@php\n    \$val = config('');\n@endphp";
    $doc2 = new Document('file://' . $tempDir . '/resources/views/block.blade.php', $phpBlockContent);
    $items2 = $provider->get($doc2, ['line' => 1, 'character' => 19]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('app.name', 'services.mail.host');

    // 3. Inline @php(config(''))
    $doc3 = new Document('file://' . $tempDir . '/resources/views/inline.blade.php', "@php(config(''))");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => 13]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('app.name', 'app.debug');

    // 4. Directive argument: @if(config(''))
    $doc4 = new Document('file://' . $tempDir . '/resources/views/if.blade.php', "@if(config(''))");
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => 12]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('app.name', 'app.debug');

    // 5. Bound component / HTML attribute: <x-button :value="config('')" />
    $doc5 = new Document('file://' . $tempDir . '/resources/views/attr.blade.php', '<x-button :value="config(\'\')" />');
    $items5 = $provider->get($doc5, ['line' => 0, 'character' => 26]);
    $labels5 = array_column($items5, 'label');
    expect($labels5)->toContain('app.name', 'services.mail.host');

    // 6. Raw echo: {!! config('') !!}
    $doc6 = new Document('file://' . $tempDir . '/resources/views/raw.blade.php', "{!! config('') !!}");
    $items6 = $provider->get($doc6, ['line' => 0, 'character' => 12]);
    $labels6 = array_column($items6, 'label');
    expect($labels6)->toContain('app.name', 'services.mail.host');
});

test('config helper filters partial keys and handles incomplete calls while typing', function () {
    $tempDir = sys_get_temp_dir() . '/config_test_' . uniqid();
    $configs = [
        ['name' => 'app.name', 'value' => 'Laravel App', 'file' => 'config/app.php', 'line' => 5],
        ['name' => 'app.debug', 'value' => true, 'file' => 'config/app.php', 'line' => 10],
        ['name' => 'services.mail.host', 'value' => 'smtp.mailgun.org', 'file' => 'config/services.php', 'line' => 20],
        ['name' => 'services.mail.port', 'value' => 587, 'file' => 'config/services.php', 'line' => 25],
    ];

    $project = createConfigTestProject($tempDir, $configs);
    $provider = new ConfigCompletionProvider($project);

    // 1. Partial key prefix: config('app.')
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ config('app.') }}");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => 15]);
    $labels1 = array_column($items1, 'label');

    expect($labels1)->toContain('app.name', 'app.debug');
    expect($labels1)->not->toContain('services.mail.host');
    $appDebug = collect($items1)->firstWhere('label', 'app.debug');
    expect($appDebug['detail'])->toBe('true');
    expect($appDebug['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 11],
        'end'   => ['line' => 0, 'character' => 15],
    ]);

    // 2. Partial nested prefix: config('services.mail.')
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ config('services.mail.') }}");
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => 25]);
    $labels2 = array_column($items2, 'label');

    expect($labels2)->toContain('services.mail.host', 'services.mail.port');
    expect($labels2)->not->toContain('app.name');

    // 3. Incomplete call while typing (no closing quote or paren): {{ config('app.na
    $doc3 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ config('app.na");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => 17]);
    $labels3 = array_column($items3, 'label');

    expect($labels3)->toContain('app.name');
    expect($labels3)->not->toContain('app.debug');
    $incompleteItem = collect($items3)->firstWhere('label', 'app.name');
    expect($incompleteItem['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 11],
        'end'   => ['line' => 0, 'character' => 17],
    ]);
    expect($incompleteItem['textEdit']['newText'])->toBe('app.name');

    // 4. Double quotes: config("services.")
    $doc4 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '{!! config("services.") !!}');
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => 21]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('services.mail.host', 'services.mail.port');
    $doubleQuoteItem = collect($items4)->firstWhere('label', 'services.mail.host');
    expect($doubleQuoteItem['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 12],
        'end'   => ['line' => 0, 'character' => 21],
    ]);

    // 5. Multiple calls on the same line: cursor in the second call
    $multiLine = "{{ config('app.name') }} and {{ config('services.m') }}";
    $doc5 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $multiLine);
    // Cursor at 'services.m'
    $secondCallOffset = strpos($multiLine, 'services.m') + strlen('services.m');
    $items5 = $provider->get($doc5, ['line' => 0, 'character' => $secondCallOffset]);
    $labels5 = array_column($items5, 'label');
    expect($labels5)->toContain('services.mail.host', 'services.mail.port');
    expect($labels5)->not->toContain('app.name');
    $secondItem = collect($items5)->firstWhere('label', 'services.mail.host');
    $expectedStart = strpos($multiLine, 'services.m');
    expect($secondItem['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => $expectedStart],
        'end'   => ['line' => 0, 'character' => $secondCallOffset],
    ]);

    // 6. Facade call in Blade: {{ Config::get('app.') }}
    $facadeLine = "{{ Config::get('app.') }}";
    $doc6 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $facadeLine);
    $items6 = $provider->get($doc6, ['line' => 0, 'character' => strlen("{{ Config::get('app.")]);
    $labels6 = array_column($items6, 'label');
    expect($labels6)->toContain('app.name', 'app.debug');
    expect($labels6)->not->toContain('services.mail.host');
});

test('config helper does not offer completions in invalid contexts', function () {
    $tempDir = sys_get_temp_dir() . '/config_test_' . uniqid();
    $configs = [
        ['name' => 'app.name', 'value' => 'Laravel App', 'file' => 'config/app.php', 'line' => 5],
    ];

    $project = createConfigTestProject($tempDir, $configs);
    $provider = new ConfigCompletionProvider($project);

    // 1. Second / default argument: config('app.name', '')
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ config('app.name', '') }}");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => 23]);
    expect($items1)->toBeEmpty();

    // 2. Method call on object: $service->config('app.')
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ \$service->config('app.') }}");
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => 23]);
    expect($items2)->toBeEmpty();

    // 3. Inside Blade comment: {{-- config('') --}}
    $doc3 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{-- config('') --}}");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => 13]);
    expect($items3)->toBeEmpty();

    // 4. Inside single-line comment: // config('')
    $doc4 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "@php\n    // config('');\n@endphp");
    $items4 = $provider->get($doc4, ['line' => 1, 'character' => 15]);
    expect($items4)->toBeEmpty();

    // 5. Inside multi-line comment: /* config('') */
    $doc5 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "@php\n    /* config('') */\n@endphp");
    $items5 = $provider->get($doc5, ['line' => 1, 'character' => 15]);
    expect($items5)->toBeEmpty();

    // 6. Non-string expression: config($key)
    $doc6 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ config(\$key) }}");
    $items6 = $provider->get($doc6, ['line' => 0, 'character' => 14]);
    expect($items6)->toBeEmpty();
});

test('config helper completion handles emoji and multibyte positions correctly', function () {
    $tempDir = sys_get_temp_dir() . '/config_test_' . uniqid();
    $configs = [
        ['name' => 'app.name', 'value' => 'Laravel App', 'file' => 'config/app.php', 'line' => 5],
        ['name' => 'app.debug', 'value' => false, 'file' => 'config/app.php', 'line' => 10],
    ];

    $project = createConfigTestProject($tempDir, $configs);
    $provider = new ConfigCompletionProvider($project);

    $emojiLine = '<div>🔥 Rocket 🚀</div> {{ config(\'app.\') }}';
    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $emojiLine);

    // Cursor right after 'app.'
    $prefixLine = '<div>🔥 Rocket 🚀</div> {{ config(\'app.';
    $charPos = Utf16Position::length($prefixLine);

    $items = $provider->get($doc, ['line' => 0, 'character' => $charPos]);
    $labels = array_column($items, 'label');
    expect($labels)->toContain('app.name', 'app.debug');

    $nameItem = collect($items)->firstWhere('label', 'app.name');
    $startChar = $charPos - Utf16Position::length('app.');
    expect($nameItem['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => $startChar],
        'end'   => ['line' => 0, 'character' => $charPos],
    ]);
    expect($doc->textInRange($nameItem['textEdit']['range']))->toBe('app.');

    // Verify CompletionItems::matching preserves it
    $matched = CompletionItems::matching($doc, $items);
    expect(array_column($matched, 'label'))->toContain('app.name', 'app.debug');
});

test('protocol level TextDocumentCompletion returns config completions in Blade files', function () {
    $tempDir = sys_get_temp_dir() . '/config_proto_' . uniqid();
    $configs = [
        ['name' => 'app.name', 'value' => 'Laravel App', 'file' => 'config/app.php', 'line' => 5],
        ['name' => 'app.debug', 'value' => true, 'file' => 'config/app.php', 'line' => 10],
        ['name' => 'services.mail.host', 'value' => 'smtp.mailgun.org', 'file' => 'config/services.php', 'line' => 20],
    ];

    $project = createConfigTestProject($tempDir, $configs);
    $container = new Container();
    $container->instance(Project::class, $project);

    $docUri = 'file://' . $tempDir . '/resources/views/welcome.blade.php';
    $docManager = new DocumentManager();
    $docManager->open($docUri, '<div>{{ config(\'app.na\') }}</div>');

    $featureRegistry = new FeatureRegistry($container);
    $featureRegistry->completions = [ConfigCompletionProvider::class];

    $handler = new TextDocumentCompletion($docManager, $featureRegistry, $project);
    $request = new JsonRpcRequest(1, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 0, 'character' => 22],
    ]);

    $response = $handler->handle($request);
    $result = $response->toArray()['result'] ?? [];

    $labels = array_column($result, 'label');
    expect($labels)->toContain('app.name');
    expect($labels)->not->toContain('app.debug');
    expect($labels)->not->toContain('services.mail.host');

    $item = collect($result)->firstWhere('label', 'app.name');
    expect($item['filterText'])->toBe('app.name');
    expect($item['textEdit']['newText'])->toBe('app.name');
    expect($item['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 16],
        'end'   => ['line' => 0, 'character' => 22],
    ]);
});

test('existing config hover, diagnostics, and dynamic index refresh work properly', function () {
    $tempDir = sys_get_temp_dir() . '/config_diag_' . uniqid();
    $configCollection = collect([
        ['name' => 'app.name', 'value' => 'Initial App', 'file' => 'config/app.php', 'line' => 5],
    ]);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('configs')->andReturnUsing(fn () => [
        'configs' => $configCollection,
        'paths' => collect(['config/app.php']),
    ]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new ConfigCompletionProvider($project);

    // 1. Initial completions
    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "{{ config('') }}");
    $items1 = $provider->get($doc, ['line' => 0, 'character' => 11]);
    expect(array_column($items1, 'label'))->toBe(['app.name']);

    // 2. Dynamic index refresh
    $configCollection->push(['name' => 'app.timezone', 'value' => 'UTC', 'file' => 'config/app.php', 'line' => 12]);
    $items2 = $provider->get($doc, ['line' => 0, 'character' => 11]);
    expect(array_column($items2, 'label'))->toContain('app.name', 'app.timezone');

    // 3. Existing Hover & Diagnostics in PHP files
    $mapper = new ConfigDocumentMapper($project);
    $phpDoc = new Document('file://' . $tempDir . '/routes/web.php', "<?php\nconfig('app.name');\nconfig('unknown.key');\n");

    $hover = $mapper->hover($phpDoc, ['line' => 1, 'character' => 10]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('Initial App');

    $diagnostics = $mapper->diagnostics($phpDoc);
    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]['message'])->toContain('unknown.key');
});
