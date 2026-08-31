<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladePhp\BladeSemanticDiagnosticAnalyzer;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir() . '/blade_undef_var_test_' . uniqid();
    @mkdir($this->basePath . '/resources/views/components', 0777, true);
    @mkdir($this->basePath . '/resources/views/users', 0777, true);

    $uri = FileUri::of($this->basePath);
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'globals' => [],
        'views' => [
            'users.profile' => [
                'key' => 'users.profile',
                'variables' => [
                    'user' => ['name' => 'user', 'type' => '\App\Models\User'],
                ],
            ],
        ],
    ]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([]));

    $scripts = new ScriptRunner($this->basePath, ['php']);
    $this->project = new Project($uri, [], $mockIndex, $scripts);
    $this->analyzer = new BladeSemanticDiagnosticAnalyzer($this->project);
});

afterEach(function () {
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

test('emits error diagnostic for undefined variable in Blade template', function () {
    $doc = new Document('file://' . $this->basePath . '/resources/views/users/profile.blade.php', '<h1>{{ $nonExistentVariable }}</h1>');
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->firstWhere('source', 'blade-variable');
    expect($undef)->not->toBeNull();
    expect($undef['severity'])->toBe(1); // Error (Red Squiggly)
    expect($undef['message'])->toBe('Undefined variable: $nonExistentVariable');
});

test('does NOT emit error diagnostic for defined controller variable', function () {
    $doc = new Document('file://' . $this->basePath . '/resources/views/users/profile.blade.php', '<h1>{{ $user->name }}</h1>');
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->where('source', 'blade-variable')->all();
    expect($undef)->toBeEmpty();
});

test('does NOT emit error diagnostic for @props, @foreach, @php, or framework globals', function () {
    $blade = <<<'BLADE'
@props(['headerTitle' => 'Default'])

@php $localCount = 10; @endphp

<div>
    <h2>{{ $headerTitle }}</h2>
    <p>{{ $localCount }}</p>
    <p>{{ $errors->first('email') }}</p>
    <p>{{ $__env->make('header') }}</p>

    @foreach (['a', 'b'] as $letter)
        <span>{{ $letter }} &mdash; {{ $loop->index }}</span>
    @endforeach
</div>
BLADE;

    $doc = new Document('file://' . $this->basePath . '/resources/views/components/card.blade.php', $blade);
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->where('source', 'blade-variable')->all();
    expect($undef)->toBeEmpty();
});

test('does NOT emit error diagnostic for isset($var) or empty($var)', function () {
    $blade = <<<'BLADE'
@if(isset($optionalVar) && !empty($anotherOptional))
    <p>Guarded check</p>
@endif
BLADE;

    $doc = new Document('file://' . $this->basePath . '/resources/views/test.blade.php', $blade);
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->where('source', 'blade-variable')->all();
    expect($undef)->toBeEmpty();
});

test('does NOT emit error diagnostic for null coalesce expressions', function () {
    $blade = <<<'BLADE'
<p>{{ $maybeUser ?? 'Guest' }}</p>
BLADE;

    $doc = new Document('file://' . $this->basePath . '/resources/views/test.blade.php', $blade);
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->where('source', 'blade-variable')->all();
    expect($undef)->toBeEmpty();
});

test('does NOT emit error diagnostic for catch variables or arrow function parameters', function () {
    $blade = <<<'BLADE'
@php
try {
    doSomething();
} catch (\Throwable $e) {
    echo $e->getMessage();
}
@endphp

<div>
    {{ $errors->map(fn ($err) => $err) }}
</div>
BLADE;

    $doc = new Document('file://' . $this->basePath . '/resources/views/test.blade.php', $blade);
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->where('source', 'blade-variable')->all();
    expect($undef)->toBeEmpty();
});

test('emits error diagnostic for undefined variable inside loop body while loop var is valid', function () {
    $blade = <<<'BLADE'
@foreach (['one', 'two'] as $num)
    <p>{{ $num }}</p>
    <span>{{ $missingVariable }}</span>
@endforeach
BLADE;

    $doc = new Document('file://' . $this->basePath . '/resources/views/test.blade.php', $blade);
    $diagnostics = $this->analyzer->get($doc);

    $undef = collect($diagnostics)->where('source', 'blade-variable')->values()->all();
    expect($undef)->toHaveCount(1);
    expect($undef[0]['message'])->toBe('Undefined variable: $missingVariable');
});

