<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir() . '/blade_dynamic_helper_comp_' . uniqid();
    @mkdir($this->basePath . '/app/Models', 0777, true);
    @mkdir($this->basePath . '/app/Helpers', 0777, true);

    file_put_contents($this->basePath . '/app/Models/Cart.php', <<<'PHP'
<?php
namespace App\Models;

if (!class_exists('App\Models\Cart', false)) {
    class Cart {
        public int $id = 1;
        public float $total = 99.50;
        public function items() { return []; }
        public function checkout(): bool { return true; }
    }
}
PHP);


    file_put_contents($this->basePath . '/app/Helpers/helpers.php', <<<'PHP'
<?php
/**
 * @return \App\Models\Cart
 */
function cart() {
    return new \App\Models\Cart();
}
PHP);

    require_once $this->basePath . '/app/Models/Cart.php';

    $uri = FileUri::of($this->basePath);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([
        'App\\Models\\Cart' => [
            'class' => 'App\\Models\\Cart',
            'path' => 'app/Models/Cart.php',
            'line' => 4,
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'total', 'type' => 'float'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([]));

    $scripts = new ScriptRunner($this->basePath, ['php']);
    $this->project = new Project($uri, [], $mockIndex, $scripts);
    $this->provider = new BladeMemberCompletionProvider($this->project);
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

test('BladeMemberCompletionProvider provides completions for standard helpers dynamically', function () {
    $doc = new Document('file:///test/view.blade.php', '{{ auth()-> }}');
    $completions = $this->provider->get($doc, ['line' => 0, 'character' => 11]);
    $labels = collect($completions)->pluck('label')->all();

    expect($labels)->toContain('user', 'check', 'id', 'guard');
});

test('BladeMemberCompletionProvider provides completions for custom user helpers dynamically', function () {
    $doc = new Document('file:///test/view.blade.php', '{{ cart()-> }}');
    $completions = $this->provider->get($doc, ['line' => 0, 'character' => 11]);
    $labels = collect($completions)->pluck('label')->all();

    expect($labels)->toContain('items', 'checkout', 'total', 'id');
});

test('BladeMemberCompletionProvider supports chained calls on helper functions', function () {
    $doc = new Document('file:///test/view.blade.php', '{{ now()->addDays(2)-> }}');
    $completions = $this->provider->get($doc, ['line' => 0, 'character' => 22]);
    $labels = collect($completions)->pluck('label')->all();

    expect($labels)->toContain('format', 'diffForHumans', 'toISOString');
});
