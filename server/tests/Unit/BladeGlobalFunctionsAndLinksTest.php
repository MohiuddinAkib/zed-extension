<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberLinkProvider;
use App\Lsp\Features\BladeVariables\BladeVariableLinkProvider;
use App\Lsp\Features\Functions\GlobalFunctionRegistry;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

beforeEach(function () {
    $container = new Container();
    $this->basePath = sys_get_temp_dir() . '/laravel_lsp_test_' . uniqid();
    @mkdir($this->basePath . '/app/Models', 0777, true);
    @mkdir($this->basePath . '/app/Helpers', 0777, true);

    // Create a User model
    file_put_contents($this->basePath . '/app/Models/User.php', <<<'PHP'
<?php
namespace App\Models;

class User {
    public string $name = 'John';
    public string $email = 'john@example.com';
    public function posts() { return []; }
}
PHP);

    // Create custom helpers.php
    file_put_contents($this->basePath . '/app/helpers.php', <<<'PHP'
<?php

/**
 * Format currency amount.
 */
function format_price(float $amount, string $currency = 'USD'): string {
    return $currency . ' ' . number_format($amount, 2);
}
PHP);

    $uri = FileUri::of($this->basePath);
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\User' => [
            'class' => 'App\\Models\\User',
            'path' => 'app/Models/User.php',
            'line' => 4,
            'attributes' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'users.show' => [
                'key' => 'users.show',
                'variables' => [
                    'user' => [
                        'name' => 'user',
                        'type' => '\\App\\Models\\User',
                        'origin' => 'Controller',
                        'source' => null, // Source not explicitly known, tests fallback to Model class!
                    ],
                ],
            ],
        ],
    ]);

    $scripts = new ScriptRunner($this->basePath, ['php']);
    $this->project = new Project($uri, [], $mockIndex, $scripts);
    $container->instance(Project::class, $this->project);
});

afterEach(function () {
    // Cleanup temporary directory
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    @rmdir($this->basePath);
});

test('GlobalFunctionRegistry lists Laravel helpers, PHP builtins, and project helpers', function () {
    $registry = new GlobalFunctionRegistry($this->project);

    expect($registry->has('route'))->toBeTrue()
        ->and($registry->has('view'))->toBeTrue()
        ->and($registry->has('asset'))->toBeTrue()
        ->and($registry->has('count'))->toBeTrue()
        ->and($registry->has('in_array'))->toBeTrue()
        ->and($registry->has('format_price'))->toBeTrue();

    $routeInfo = $registry->get('route');
    expect($routeInfo['signature'])->toContain('route(')
        ->and($routeInfo['returnType'])->toBe('string');

    $customInfo = $registry->get('format_price');
    expect($customInfo['signature'])->toContain('format_price(')
        ->and($customInfo['source'])->toContain('app/helpers.php')
        ->and($customInfo['doc'])->toContain('Format currency amount');
});

test('BladeMemberCompletionProvider suggests functions inside Blade expressions', function () {
    $provider = new BladeMemberCompletionProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', '{{ rou }}');
    $completions = $provider->get($doc, ['line' => 0, 'character' => 6]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('route()');

    $docIf = new Document('file:///test/view.blade.php', '@if(cou');
    $ifCompletions = $provider->get($docIf, ['line' => 0, 'character' => 7]);
    $ifLabels = collect($ifCompletions)->pluck('label')->all();
    expect($ifLabels)->toContain('count()');

    $docCustom = new Document('file:///test/view.blade.php', '{{ format_p }}');
    $customCompletions = $provider->get($docCustom, ['line' => 0, 'character' => 11]);
    $customLabels = collect($customCompletions)->pluck('label')->all();
    expect($customLabels)->toContain('format_price()');
});

test('BladeMemberHoverProvider provides hover for global and custom helper functions', function () {
    $provider = new BladeMemberHoverProvider($this->project);

    $doc = new Document('file:///test/view.blade.php', '{{ route("users.show") }}');
    // Hover on 'route' (character 4)
    $hover = $provider->get($doc, ['line' => 0, 'character' => 4]);

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('route(');

    $docCustom = new Document('file:///test/view.blade.php', '{{ format_price(99.99) }}');
    // Hover on 'format_price' (character 5)
    $customHover = $provider->get($docCustom, ['line' => 0, 'character' => 5]);

    expect($customHover)->not->toBeNull();
    expect($customHover['contents']['value'])->toContain('format_price(')
        ->toContain('Format currency amount');
});

test('BladeVariableLinkProvider links object variables to their model class file', function () {
    $provider = new BladeVariableLinkProvider($this->project);

    $doc = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '<h1>{{ $user->name }}</h1>');
    $links = $provider->get($doc);

    expect(count($links))->toBeGreaterThan(0);
    $userLink = collect($links)->first(fn ($l) => str_contains($l['target'], 'User.php'));
    expect($userLink)->not->toBeNull();
    expect($userLink['tooltip'])->toContain('User.php');
});

test('BladeMemberLinkProvider links object properties to their model definition', function () {
    $provider = new BladeMemberLinkProvider($this->project);

    $doc = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '<h1>{{ $user->name }}</h1>');
    $links = $provider->get($doc);

    expect(count($links))->toBeGreaterThan(0);
    $nameLink = collect($links)->first(fn ($l) => str_contains($l['target'], 'User.php'));
    expect($nameLink)->not->toBeNull();
});

test('BladeMemberLinkProvider links custom helper functions to helpers.php file', function () {
    $provider = new BladeMemberLinkProvider($this->project);

    $doc = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '<span>{{ format_price(100) }}</span>');
    $links = $provider->get($doc);

    $helperLink = collect($links)->first(fn ($l) => str_contains($l['target'], 'helpers.php'));
    expect($helperLink)->not->toBeNull();
});

test('BladeVariableCompletionProvider auto-suggests global variables $__env, $errors, $app', function () {
    $provider = new \App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider($this->project);

    $doc = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '{{ $__ }}');
    $completions = $provider->get($doc, ['line' => 0, 'character' => 6]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('$__env')
        ->and($labels)->toContain('$errors')
        ->and($labels)->toContain('$app')
        ->and($labels)->toContain('$request');
});

test('BladeMemberCompletionProvider suggests methods on $__env and $errors', function () {
    $provider = new BladeMemberCompletionProvider($this->project);

    $docEnv = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '{{ $__env-> }}');
    $envCompletions = $provider->get($docEnv, ['line' => 0, 'character' => 11]);
    $envLabels = collect($envCompletions)->pluck('label')->all();

    expect($envLabels)->toContain('make')
        ->and($envLabels)->toContain('renderWhen')
        ->and($envLabels)->toContain('share');

    $docErr = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '{{ $errors-> }}');
    $errCompletions = $provider->get($docErr, ['line' => 0, 'character' => 12]);
    $errLabels = collect($errCompletions)->pluck('label')->all();

    expect($errLabels)->toContain('any')
        ->and($errLabels)->toContain('has')
        ->and($errLabels)->toContain('get');
});

test('BladeMemberHoverProvider displays hover signature on $__env->make', function () {
    $provider = new BladeMemberHoverProvider($this->project);

    $doc = new Document('file://' . $this->basePath . '/resources/views/users/show.blade.php', '{{ $__env->make("header") }}');
    // Hover on 'make' (character 11)
    $hover = $provider->get($doc, ['line' => 0, 'character' => 11]);

    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('Factory::make')
        ->toContain('$view');
});

