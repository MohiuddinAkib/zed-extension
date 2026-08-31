<?php

declare(strict_types=1);

use App\Lsp\Analysis\FunctionTypeResolver;
use App\Lsp\Features\Functions\GlobalFunctionRegistry;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

beforeEach(function () {
    $this->basePath = sys_get_temp_dir() . '/func_resolver_test_' . uniqid();
    @mkdir($this->basePath . '/app/Helpers', 0777, true);

    file_put_contents($this->basePath . '/app/Helpers/custom.php', <<<'PHP'
<?php
/**
 * @return \App\Models\Cart
 */
function current_cart() {
    return new \App\Models\Cart();
}

function current_tenant(): \App\Models\Tenant {
    return new \App\Models\Tenant();
}
PHP);

    $uri = FileUri::of($this->basePath);
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('models')->andReturn([]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([
        'cart.service' => ['class' => 'App\\Services\\CartService'],
    ]));


    $scripts = new ScriptRunner($this->basePath, ['php']);
    $this->project = new Project($uri, [], $mockIndex, $scripts);
    $this->resolver = new FunctionTypeResolver($this->project);
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

test('FunctionTypeResolver dynamically resolves standard Laravel helpers', function () {
    expect($this->resolver->resolve('auth'))->toBe('\Illuminate\Auth\AuthManager')
        ->and($this->resolver->resolve('request'))->toBe('\Illuminate\Http\Request')
        ->and($this->resolver->resolve('session'))->toBe('\Illuminate\Session\SessionManager')
        ->and($this->resolver->resolve('now'))->toBe('\Illuminate\Support\Carbon')
        ->and($this->resolver->resolve('today'))->toBe('\Illuminate\Support\Carbon')
        ->and($this->resolver->resolve('collect'))->toBe('\Illuminate\Support\Collection')
        ->and($this->resolver->resolve('str'))->toBe('\Illuminate\Support\Stringable');
});

test('FunctionTypeResolver dynamically resolves container bindings via app() and resolve()', function () {
    expect($this->resolver->resolve('app', 'cart.service'))->toBe('\App\Services\CartService')
        ->and($this->resolver->resolve('resolve', 'auth'))->toBe('\Illuminate\Auth\AuthManager')
        ->and($this->resolver->resolve('app'))->toBe('\Illuminate\Foundation\Application');
});

test('FunctionTypeResolver dynamically resolves user-defined and composer helper functions', function () {
    expect($this->resolver->resolve('current_tenant'))->toBe('\App\Models\Tenant')
        ->and($this->resolver->resolve('current_cart'))->toBe('\App\Models\Cart');
});
