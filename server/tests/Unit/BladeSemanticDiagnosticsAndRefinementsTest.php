<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Document;
use App\Lsp\Features\BladePhp\BladeSemanticDiagnosticAnalyzer;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeVariableRenameProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use Illuminate\Container\Container;

beforeEach(function () {
    $container = new Container();
    $this->basePath = sys_get_temp_dir() . '/laravel_lsp_diag_test_' . uniqid();
    @mkdir($this->basePath . '/app/Models', 0777, true);
    @mkdir($this->basePath . '/app/Helpers', 0777, true);

    file_put_contents($this->basePath . '/app/Models/User.php', <<<'PHP'
<?php
namespace App\Models;

class User {
    public string $name = 'John';
    public function posts() { return []; }
}
PHP);

    file_put_contents($this->basePath . '/app/helpers.php', <<<'PHP'
<?php

/**
 * Format currency amount.
 *
 * @param float $amount
 * @param string $currency
 * @return string
 */
function format_price(float $amount, string $currency = 'USD'): string {
    return $currency . ' ' . number_format($amount, 2);
}
PHP);

    $uri = new FileUri($this->basePath);
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\User' => [
            'class' => 'App\\Models\\User',
            'path' => 'app/Models/User.php',
            'line' => 4,
            'attributes' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => []]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['prefixes' => ['x'], 'components' => []]);

    $scripts = new ScriptRunner($this->basePath, ['php']);
    $this->project = new Project($uri, [
        'phpEnvironment' => 'local',
        'routeCompletion' => true,
        'viewVariableCompletion' => true,
        'bladeMemberHover' => true,
        'bladeVariableLinks' => true,
        'bladeVariableRename' => true,
    ], $mockIndex, $scripts);

    $container->instance(Project::class, $this->project);
    Container::setInstance($container);
});

test('BladeSemanticDiagnosticAnalyzer reports error squiggly for global functions with too few args', function () {
    $analyzer = new BladeSemanticDiagnosticAnalyzer($this->project);

    // route() requires at least 1 parameter ($name)
    $doc = new Document('file://' . $this->basePath . '/resources/views/test_diag.blade.php', '{{ route() }}');
    $diagnostics = $analyzer->get($doc);

    expect($diagnostics)->not->toBeEmpty();
    $d = $diagnostics[0];
    expect($d['severity'])->toBe(1)
        ->and($d['message'])->toContain('Too few arguments to function route()')
        ->and($d['message'])->toContain('route(string $name');
});

test('BladeSemanticDiagnosticAnalyzer reports error squiggly for static methods with too few args', function () {
    $analyzer = new BladeSemanticDiagnosticAnalyzer($this->project);

    // Js::from() requires at least 1 parameter ($data)
    $doc = new Document('file://' . $this->basePath . '/resources/views/test_diag2.blade.php', '{{ \Illuminate\Support\Js::from() }}');
    $diagnostics = $analyzer->get($doc);

    expect($diagnostics)->not->toBeEmpty();
    $d = $diagnostics[0];
    expect($d['severity'])->toBe(1)
        ->and($d['message'])->toContain('Too few arguments to method')
        ->and($d['message'])->toContain('from');
});

test('UTF-16 position correctly handles emojis and multibyte characters in column calculations', function () {
    $emojiLine = "👋 {{ \$user->name }}";
    // 👋 in UTF-8 is 4 bytes, in UTF-16 is 2 code units.
    // '👋 {{ ' is 2 + 1 + 1 + 1 + 1 = 6 UTF-16 code units.
    $utf16Col = Utf16Position::byteOffsetToUtf16Column($emojiLine, 8); // 4 (emoji) + 4 (' {{ ') = 8 bytes
    expect($utf16Col)->toBe(6);

    $astAnalyzer = new BladePhpAstAnalyzer();
    $expressions = $astAnalyzer->extractAllExpressions($emojiLine);

    // Find the property expression for 'name'
    $propExpr = collect($expressions)->firstWhere('name', 'name');
    expect($propExpr)->not->toBeNull();
    // '👋 {{ $user->' = 2 (emoji) + 4 (' {{ ') + 5 ('$user') + 2 ('->') = 13
    expect($propExpr['startCol'])->toBe(13);
});

test('BladeVariableRenameProvider safely renames only AST PHP variables and ignores strings/comments/JS', function () {
    $renameProvider = new BladeVariableRenameProvider($this->project);

    $template = <<<'BLADE'
@php $user = 'akib'; @endphp
{{ $user }}
<!-- This is a comment mentioning $user -->
<script>
    let $user = "constant";
</script>
<p>The cost is $user dollars</p>
BLADE;

    $doc = new Document('file://' . $this->basePath . '/resources/views/rename_test.blade.php', $template);
    // Cursor on line 1 (0-indexed line 1: '{{ $user }}') on 'user' (char 4)
    $result = $renameProvider->rename($doc, ['line' => 1, 'character' => 4], 'renamedUser');

    expect($result)->not->toBeNull()
        ->and($result['changes'])->toHaveKey($doc->uri);

    $edits = $result['changes'][$doc->uri];
    // Should rename @php $user (line 0) and {{ $user }} (line 1), but NEVER line 2 (comment), line 4 (script), or line 6 (html text)
    $renamedLines = collect($edits)->pluck('range.start.line')->all();
    expect($renamedLines)->toContain(0)
        ->and($renamedLines)->toContain(1)
        ->and($renamedLines)->not->toContain(2)
        ->and($renamedLines)->not->toContain(4)
        ->and($renamedLines)->not->toContain(6);
});

test('BladeMemberCompletionProvider and BladeMemberHoverProvider support all loop properties', function () {
    $doc = new Document('file://' . $this->basePath . '/resources/views/loop_test.blade.php', "@foreach(\$items as \$item)\n{{ \$loop-> }}\n@endforeach");

    $compProvider = new BladeMemberCompletionProvider($this->project);
    $completions = $compProvider->get($doc, ['line' => 1, 'character' => 10]);
    $labels = collect($completions)->pluck('label')->all();

    expect($labels)->toContain('index')
        ->and($labels)->toContain('iteration')
        ->and($labels)->toContain('first')
        ->and($labels)->toContain('last')
        ->and($labels)->toContain('count')
        ->and($labels)->toContain('remaining')
        ->and($labels)->toContain('even')
        ->and($labels)->toContain('odd');

    $hoverProvider = new BladeMemberHoverProvider($this->project);
    $docHover = new Document('file://' . $this->basePath . '/resources/views/loop_test.blade.php', "@foreach(\$items as \$item)\n{{ \$loop->iteration }}\n@endforeach");
    $hover = $hoverProvider->get($docHover, ['line' => 1, 'character' => 11]);

    expect($hover)->not->toBeNull()
        ->and($hover['contents']['value'])->toContain('$loop->iteration')
        ->and($hover['contents']['value'])->toContain('current loop iteration');
});
