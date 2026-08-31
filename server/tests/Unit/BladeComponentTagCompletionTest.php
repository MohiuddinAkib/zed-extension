<?php

declare(strict_types=1);

use App\Lsp\Analysis\ComponentRegistry;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeComponents\BladeComponentCompletionProvider;
use App\Lsp\Features\BladeComponents\BladeComponentDocumentMapper;
use App\Lsp\Methods\TextDocumentCompletion;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\ComponentSymbol;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use App\Lsp\Transport\JsonRpcRequest;

test('blade component tag completion filters candidates and produces correct ranges for partial tags', function () {
    $tempDir = sys_get_temp_dir() . '/comp_tag_' . uniqid();
    mkdir($tempDir . '/resources/views/components', 0777, true);

    file_put_contents($tempDir . '/resources/views/components/layout.blade.php', '<div>{{ $slot }}</div>');
    file_put_contents($tempDir . '/resources/views/components/label.blade.php', '<label>{{ $slot }}</label>');
    file_put_contents($tempDir . '/resources/views/components/alert.blade.php', '<div>{{ $slot }}</div>');

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn([
        'components' => [
            'mail::message' => [
                'paths' => ['resources/views/vendor/mail/html/message.blade.php'],
                'props' => [],
            ],
            'mail::panel' => [
                'paths' => ['resources/views/vendor/mail/html/panel.blade.php'],
                'props' => [],
            ],
            'flux:button' => [
                'paths' => ['resources/views/flux/button.blade.php'],
                'props' => [],
            ],
        ],
        'prefixes' => ['x-', 'flux:'],
    ]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $mapper = new BladeComponentDocumentMapper($project);

    // 1. Partial tag: <x-la should return only x-layout and x-label
    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-la');
    $completions = $mapper->completions($doc, ['line' => 0, 'character' => 5]);
    $labels = array_column($completions, 'label');

    expect($labels)->toContain('x-layout');
    expect($labels)->toContain('x-label');
    expect($labels)->not->toContain('x-alert');
    expect($labels)->not->toContain('x-mail::message');

    $layoutComp = collect($completions)->firstWhere('label', 'x-layout');
    expect($layoutComp['filterText'])->toBe('x-layout');
    expect($layoutComp['textEdit']['newText'])->toBe('x-layout');
    expect($layoutComp['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 1],
        'end'   => ['line' => 0, 'character' => 5],
    ]);

    // 2. Bare <x- returns all available x- Blade components
    $bareDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-');
    $bareCompletions = $mapper->completions($bareDoc, ['line' => 0, 'character' => 3]);
    $bareLabels = array_column($bareCompletions, 'label');

    expect($bareLabels)->toContain('x-layout');
    expect($bareLabels)->toContain('x-label');
    expect($bareLabels)->toContain('x-alert');
    expect($bareLabels)->toContain('x-mail::message');
    expect($bareLabels)->toContain('x-mail::panel');

    $alertComp = collect($bareCompletions)->firstWhere('label', 'x-alert');
    expect($alertComp['filterText'])->toBe('x-alert');
    expect($alertComp['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 1],
        'end'   => ['line' => 0, 'character' => 3],
    ]);

    // 3. Unrelated prefix <x-z excludes x-la... and x-alert
    $zDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-z');
    $zCompletions = $mapper->completions($zDoc, ['line' => 0, 'character' => 4]);
    expect($zCompletions)->toBeEmpty();

    // 4. Namespaced components: <x-mail:: and <x-mail::m
    $mailDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-mail::');
    $mailCompletions = $mapper->completions($mailDoc, ['line' => 0, 'character' => 9]);
    $mailLabels = array_column($mailCompletions, 'label');

    expect($mailLabels)->toContain('x-mail::message');
    expect($mailLabels)->toContain('x-mail::panel');
    expect($mailLabels)->not->toContain('x-layout');

    $mailMDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-mail::m');
    $mailMCompletions = $mapper->completions($mailMDoc, ['line' => 0, 'character' => 10]);
    $mailMLabels = array_column($mailMCompletions, 'label');

    expect($mailMLabels)->toContain('x-mail::message');
    expect($mailMLabels)->not->toContain('x-mail::panel');
    $mailMComp = collect($mailMCompletions)->firstWhere('label', 'x-mail::message');
    expect($mailMComp['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 1],
        'end'   => ['line' => 0, 'character' => 10],
    ]);

    // 5. Configured prefix <flux:
    $fluxDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<flux:b');
    $fluxCompletions = $mapper->completions($fluxDoc, ['line' => 0, 'character' => 7]);
    $fluxLabels = array_column($fluxCompletions, 'label');

    expect($fluxLabels)->toContain('flux:button');
    expect($fluxLabels)->not->toContain('x-layout');

    // 6. Multibyte content before component tag produces correct UTF-16 ranges
    $emojiLine = '<div>🔥 Rocket</div> <x-la';
    $emojiDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $emojiLine);
    $charPos = Utf16Position::length($emojiLine);
    $emojiCompletions = $mapper->completions($emojiDoc, ['line' => 0, 'character' => $charPos]);
    $emojiLabels = array_column($emojiCompletions, 'label');

    expect($emojiLabels)->toContain('x-layout');
    $emojiLayoutComp = collect($emojiCompletions)->firstWhere('label', 'x-layout');
    $expectedStartChar = $charPos - Utf16Position::length('x-la');
    expect($emojiLayoutComp['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => $expectedStartChar],
        'end'   => ['line' => 0, 'character' => $charPos],
    ]);
    expect($emojiDoc->textInRange($emojiLayoutComp['textEdit']['range']))->toBe('x-la');

    // 7. Completed, closing, and standard HTML tags are ignored
    $closingDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '</x-la');
    expect($mapper->completions($closingDoc, ['line' => 0, 'character' => 6]))->toBeEmpty();

    $completedDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<x-layout>');
    expect($mapper->completions($completedDoc, ['line' => 0, 'character' => 10]))->toBeEmpty();

    $htmlDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', '<div');
    expect($mapper->completions($htmlDoc, ['line' => 0, 'character' => 4]))->toBeEmpty();
});

test('protocol level TextDocumentCompletion handles partial Blade component tag completion', function () {
    $tempDir = sys_get_temp_dir() . '/comp_proto_' . uniqid();
    mkdir($tempDir . '/resources/views/components', 0777, true);

    file_put_contents($tempDir . '/resources/views/components/layout.blade.php', '<div>{{ $slot }}</div>');
    file_put_contents($tempDir . '/resources/views/components/label.blade.php', '<label>{{ $slot }}</label>');
    file_put_contents($tempDir . '/resources/views/components/alert.blade.php', '<div>{{ $slot }}</div>');

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn([
        'components' => [],
        'prefixes' => ['x-'],
    ]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $container = new \Illuminate\Container\Container();
    $container->instance(Project::class, $project);

    $docUri = 'file://' . $tempDir . '/resources/views/welcome.blade.php';
    $docManager = new DocumentManager();
    $docManager->open($docUri, '<div><x-la');

    $featureRegistry = new FeatureRegistry($container);
    $featureRegistry->completions = [BladeComponentCompletionProvider::class];

    $handler = new TextDocumentCompletion($docManager, $featureRegistry, $project);
    $request = new JsonRpcRequest(1, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 0, 'character' => 10],
    ]);

    $response = $handler->handle($request);
    $result = $response->toArray()['result'] ?? [];

    $labels = array_column($result, 'label');
    expect($labels)->toContain('x-layout');
    expect($labels)->toContain('x-label');
    expect($labels)->not->toContain('x-alert');

    $layoutItem = collect($result)->firstWhere('label', 'x-layout');
    expect($layoutItem['filterText'])->toBe('x-layout');
    expect($layoutItem['textEdit']['range'])->toBe([
        'start' => ['line' => 0, 'character' => 6],
        'end'   => ['line' => 0, 'character' => 10],
    ]);
    expect($layoutItem['textEdit']['newText'])->toBe('x-layout');
});
