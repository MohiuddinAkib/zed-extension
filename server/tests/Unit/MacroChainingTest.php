<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\DataPathResolver;
use App\Lsp\Analysis\FunctionTypeResolver;
use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

test('fluent chaining continues autocompletion after calling a macro on facade', function () {
    $tempDir = sys_get_temp_dir() . '/macro_chain_' . uniqid();
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

    $memberProvider = new BladeMemberCompletionProvider($project, macroRegistry: $macroRegistry);

    // Chained: Http::smsq('123')->with
    $code = <<<'PHP'
<?php
use Illuminate\Support\Facades\Http;
Http::smsq('123')->with
PHP;
    $doc = new Document('file://' . $tempDir . '/resources/views/test.php', $code);
    $items = $memberProvider->get($doc, ['line' => 2, 'character' => 23]); // at ->with|
    $labels = array_column($items, 'label');

    expect($labels)->toContain('withHeaders', 'withToken', 'withOptions');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('fluent chaining continues autocompletion after calling a macro on typed variable', function () {
    $tempDir = sys_get_temp_dir() . '/macro_chain_var_' . uniqid();
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
        PendingRequest::macro('withCustomAuth', function (string $token): PendingRequest {
            return $this->withToken($token);
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

    $memberProvider = new BladeMemberCompletionProvider($project, macroRegistry: $macroRegistry);

    // Chained: $client->withCustomAuth('secret')->with
    $code = <<<'PHP'
@php
/** @var \Illuminate\Http\Client\PendingRequest $client */
$client->withCustomAuth('secret')->with
@endphp
PHP;
    $doc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $code);
    $items = $memberProvider->get($doc, ['line' => 2, 'character' => 38]); // at ->with|
    $labels = array_column($items, 'label');

    expect($labels)->toContain('withHeaders', 'withToken', 'withOptions');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('FunctionTypeResolver and BladeAstAnalyzer infer return type of macro calls', function () {
    $tempDir = sys_get_temp_dir() . '/macro_infer_' . uniqid();
    @mkdir($tempDir . '/app/Providers', 0777, true);

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Str::macro('prefixAndSuffix', function (string $str): Stringable {
            return Str::of($str);
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

    $typeResolver = new FunctionTypeResolver($project, macroRegistry: $macroRegistry);
    $astAnalyzer = new BladeAstAnalyzer($project, $typeResolver, macroRegistry: $macroRegistry);
    $dataPathResolver = new DataPathResolver($project, functionTypeResolver: $typeResolver, bladeAnalyzer: $astAnalyzer, macroRegistry: $macroRegistry);

    // Resolve via FunctionTypeResolver
    $returnType = $typeResolver->resolveMethodReturnType('Illuminate\Support\Str', 'prefixAndSuffix');
    expect($returnType)->toBe('Illuminate\Support\Stringable');

    // Resolve via DataPathResolver inferExpressionType
    $doc = new Document('file://' . $tempDir . '/test.php', '<?php Str::prefixAndSuffix("test");');
    $exprType = $dataPathResolver->inferExpressionType('Str::prefixAndSuffix("test")', $doc);
    expect($exprType)->not->toBeNull();
    expect($exprType->displayName)->toBe('Illuminate\Support\Stringable');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir);
});

test('multi-hop fluent chaining through sequential macros', function () {
    $tempDir = sys_get_temp_dir() . '/macro_multihop_' . uniqid();
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
        Http::macro('tenant', function (string $tenantId): PendingRequest {
            return Http::withHeaders(['X-Tenant' => $tenantId]);
        });

        PendingRequest::macro('authenticated', function (): PendingRequest {
            return $this->withToken('jwt-token');
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

    $memberProvider = new BladeMemberCompletionProvider($project, macroRegistry: $macroRegistry);

    // Multi-hop: Http::tenant('org_123')->authenticated()->with
    $code = <<<'PHP'
<?php
use Illuminate\Support\Facades\Http;
Http::tenant('org_123')->authenticated()->with
PHP;
    $doc = new Document('file://' . $tempDir . '/resources/views/test.php', $code);
    $items = $memberProvider->get($doc, ['line' => 2, 'character' => 46]); // at ->with|
    $labels = array_column($items, 'label');

    expect($labels)->toContain('withHeaders', 'withToken', 'withOptions');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('FunctionTypeResolver handles null project and non-existent methods gracefully', function () {
    $resolver = new FunctionTypeResolver(null);

    expect($resolver->resolveMethodReturnType('NonExistentClass', 'nonExistentMethod'))->toBeNull();
    expect($resolver->resolveMacro('NonExistentClass', 'nonExistentMethod'))->toBeNull();
});

