<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeDocumentCompiler;
use App\Lsp\Analysis\DefaultPhpIntelligenceAdapter;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberLinkProvider;
use App\Lsp\Features\Facades\FacadeMap;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

beforeEach(function () {
    $container = new Container();
    $uri = FileUri::of(realpath(__DIR__ . '/../../'));
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\User' => [
            'class' => 'App\\Models\\User',
            'path' => 'app/Models/User.php',
            'line' => 10,
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => []]);

    $scripts = new ScriptRunner($uri->path(), ['php']);
    $this->project = new Project($uri, [], $mockIndex, $scripts);
    $container->instance(Project::class, $this->project);
});

test('FacadeMap has all major Laravel facades and aliases', function () {
    expect(FacadeMap::isFacadeOrAlias('Js'))->toBeTrue()
        ->and(FacadeMap::isFacadeOrAlias('Str'))->toBeTrue()
        ->and(FacadeMap::isFacadeOrAlias('Auth'))->toBeTrue()
        ->and(FacadeMap::isFacadeOrAlias('Route'))->toBeTrue()
        ->and(FacadeMap::isFacadeOrAlias('DB'))->toBeTrue()
        ->and(FacadeMap::isFacadeOrAlias('Gate'))->toBeTrue()
        ->and(FacadeMap::isFacadeOrAlias('Vite'))->toBeTrue();

    expect(FacadeMap::resolve('Js'))->toBe('\\Illuminate\\Support\\Js')
        ->and(FacadeMap::resolve('Str'))->toBe('\\Illuminate\\Support\\Str')
        ->and(FacadeMap::resolve('Auth'))->toBe('\\Illuminate\\Support\\Facades\\Auth');

    expect(FacadeMap::resolveAccessor('Auth'))->toBe('\\Illuminate\\Auth\\AuthManager')
        ->and(FacadeMap::resolveAccessor('DB'))->toBe('\\Illuminate\\Database\\DatabaseManager');

    expect(FacadeMap::defaultUseStatements())->toContain('use Illuminate\\Support\\Js;')
        ->toContain('use Illuminate\\Support\\Str;');
});

test('BladeAstAnalyzer extracts @use directives with and without aliases', function () {
    $analyzer = new BladeAstAnalyzer();

    $content = <<<'BLADE'
@use('App\Models\Flight')
@use('App\Models\User', 'AppUser')
@use(App\Models\Post::class)
@use(App\Models\Comment::class, 'PostComment')

<div>
    {{ Flight::all() }}
</div>
BLADE;

    $uses = $analyzer->extractUseDirectives($content);

    expect($uses)->toHaveKey('Flight')
        ->and($uses['Flight']['class'])->toBe('\\App\\Models\\Flight')
        ->and($uses)->toHaveKey('AppUser')
        ->and($uses['AppUser']['class'])->toBe('\\App\\Models\\User')
        ->and($uses)->toHaveKey('Post')
        ->and($uses['Post']['class'])->toBe('\\App\\Models\\Post')
        ->and($uses)->toHaveKey('PostComment')
        ->and($uses['PostComment']['class'])->toBe('\\App\\Models\\Comment');
});

test('BladeDocumentCompiler compiles global facades and @use directives into virtual PHP doc', function () {
    $compiler = new BladeDocumentCompiler();

    $doc = new Document('file:///test/example.blade.php', <<<'BLADE'
@use('App\Models\Flight', 'AirFlight')
@use(App\Models\User::class)

<div>
    {{ Js::from($data) }}
    {{ AirFlight::all() }}
</div>
BLADE);

    $virtualDoc = $compiler->compile($doc);

    expect($virtualDoc->phpCode)->toContain('use Illuminate\\Support\\Js;')
        ->toContain('use Illuminate\\Support\\Str;')
        ->toContain('use App\\Models\\Flight as AirFlight;')
        ->toContain('use App\\Models\\User;');
});

test('BladeMemberCompletionProvider suggests static methods for Js and Str facades', function () {
    $provider = new BladeMemberCompletionProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', '{{ Js:: }}');
    $completions = $provider->get($doc, ['line' => 0, 'character' => 7]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('from');

    $docStr = new Document('file:///test/view.blade.php', '{{ Str:: }}');
    $strCompletions = $provider->get($docStr, ['line' => 0, 'character' => 8]);

    $strLabels = collect($strCompletions)->pluck('label')->all();
    expect($strLabels)->toContain('slug')
        ->toContain('limit')
        ->toContain('contains');
});

test('BladeMemberCompletionProvider suggests methods for Auth facade via accessor', function () {
    $provider = new BladeMemberCompletionProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', '{{ Auth:: }}');
    $completions = $provider->get($doc, ['line' => 0, 'character' => 9]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('user')
        ->toContain('check')
        ->toContain('guard');
});

test('BladeMemberCompletionProvider provides @use directive argument completions', function () {
    $provider = new BladeMemberCompletionProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', "@use('");
    $completions = $provider->get($doc, ['line' => 0, 'character' => 6]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('Illuminate\\Support\\Js')
        ->toContain('Illuminate\\Support\\Str');
});

test('BladeMemberCompletionProvider suggests global facades in Blade expression context', function () {
    $provider = new BladeMemberCompletionProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', '{{ J }}');
    $completions = $provider->get($doc, ['line' => 0, 'character' => 4]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('Js');
});

test('BladeMemberHoverProvider provides hover info for Js::from and Str::slug', function () {
    $provider = new BladeMemberHoverProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', '{{ Js::from($data) }}');
    // Hover on 'from' (offset 7)
    $hover = $provider->get($doc, ['line' => 0, 'character' => 7]);

    expect($hover)->not->toBeNull();
    $val = $hover['contents']['value'] ?? '';
    expect($val)->toContain('Js::from')
        ->toContain('Illuminate\\Support\\Js');

    $docStr = new Document('file:///test/view.blade.php', '{{ Str::slug($title) }}');
    // Hover on 'slug' (offset 8)
    $hoverStr = $provider->get($docStr, ['line' => 0, 'character' => 8]);

    expect($hoverStr)->not->toBeNull();
    $valStr = $hoverStr['contents']['value'] ?? '';
    expect($valStr)->toContain('Str::slug')
        ->toContain('Illuminate\\Support\\Str');
});

test('BladeMemberHoverProvider provides hover info for @use directive and imported alias', function () {
    $provider = new BladeMemberHoverProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', "@use('Illuminate\\Support\\Js', 'CustomJs')\n{{ CustomJs::from(\$data) }}");

    // Hover on @use line
    $hoverUse = $provider->get($doc, ['line' => 0, 'character' => 5]);
    expect($hoverUse)->not->toBeNull();
    expect($hoverUse['contents']['value'])->toContain('Blade Class Import');

    // Hover on CustomJs token in second line
    $hoverAlias = $provider->get($doc, ['line' => 1, 'character' => 5]);
    expect($hoverAlias)->not->toBeNull();
    expect($hoverAlias['contents']['value'])->toContain('CustomJs')
        ->toContain('Illuminate\\Support\\Js');
});

test('BladeMemberLinkProvider provides links for @use directive classes and static methods', function () {
    $provider = new BladeMemberLinkProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', "@use('Illuminate\\Support\\Js')\n{{ Js::from(\$data) }}");
    $links = $provider->get($doc);

    expect(count($links))->toBeGreaterThan(0);
    $targets = collect($links)->pluck('target')->all();
    expect(collect($targets)->some(fn ($t) => str_contains($t, 'Js.php')))->toBeTrue();
});
