<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Document;
use App\Lsp\Features\BladeDirectives\BladeDirectiveHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Mockery;

test('Blade @use directive provides completion for project classes, vendor classes, and enums', function () {
    $tempDir = sys_get_temp_dir() . '/blade_use_inject_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);
    @mkdir($tempDir . '/app/Factories', 0777, true);
    @mkdir($tempDir . '/app/Services', 0777, true);
    @mkdir($tempDir . '/app/Models', 0777, true);

    file_put_contents($tempDir . '/app/Factories/UserFactory.php', <<<'PHP'
<?php
namespace App\Factories;
class UserFactory {}
PHP);

    file_put_contents($tempDir . '/app/Services/MetricsService.php', <<<'PHP'
<?php
namespace App\Services;
class MetricsService {
    public function monthlyRevenue(): float { return 100.0; }
}
PHP);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\Models\User' => [
            'path' => 'app/Models/User.php',
            'line' => 10,
            'attributes' => [['name' => 'id', 'type' => 'int']],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('appBindings')->andReturn(collect([
        'payment.gateway' => [
            'class' => 'App\Services\PaymentGateway',
            'path' => 'app/Providers/AppServiceProvider.php',
            'line' => 20,
        ],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $blade = <<<'BLADE'
@use('App\Fa')
@use('Illuminate\Support\St')
@inject('', )
@inject('metrics', '')
@inject('service', 'App\Services\MetricsService')
@inject('db', 'db')
{{ $service-> }}
BLADE;

    file_put_contents($tempDir . '/resources/views/test.blade.php', $blade);
    $document = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $blade);

    $compProvider = new BladeMemberCompletionProvider($project);

    // 1. Test @use('App\Fa') completions (Line 0, char 12)
    $useCompletions = $compProvider->get($document, ['line' => 0, 'character' => 12]);
    $useLabels = collect($useCompletions)->pluck('label')->all();

    expect($useLabels)->toContain('App\Factories\UserFactory');
    $userFactoryItem = collect($useCompletions)->firstWhere('label', 'App\Factories\UserFactory');
    expect($userFactoryItem)->not->toBeNull();
    expect($userFactoryItem['kind'])->toBe(7);
    expect($userFactoryItem['textEdit']['newText'])->toBe('App\Factories\UserFactory');

    // 2. Test @use('Illuminate\Support\St') completions (Line 1, char 27)
    $vendorCompletions = $compProvider->get($document, ['line' => 1, 'character' => 27]);
    $vendorLabels = collect($vendorCompletions)->pluck('label')->all();

    expect($vendorLabels)->toContain('Illuminate\Support\Str');

    // 3. Test @inject('', ) first argument completions (Line 2, char 9)
    $injectVarCompletions = $compProvider->get($document, ['line' => 2, 'character' => 9]);
    $varLabels = collect($injectVarCompletions)->pluck('label')->all();

    expect($varLabels)->toContain('metrics', 'db', 'auth', 'cache');

    // 4. Test @inject('metrics', '') second argument completions (Line 3, char 19)
    $injectServiceCompletions = $compProvider->get($document, ['line' => 3, 'character' => 19]);
    $serviceLabels = collect($injectServiceCompletions)->pluck('label')->all();

    // Contains container bindings
    expect($serviceLabels)->toContain('db', 'auth', 'cache', 'log', 'router');
    // Contains custom app bindings
    expect($serviceLabels)->toContain('payment.gateway');
    // Contains project classes
    expect($serviceLabels)->toContain('App\Services\MetricsService', 'App\Factories\UserFactory', 'App\Models\User');

    // 5. Test Hover on @inject directive (Line 2, char 3)
    $hoverProvider = new BladeDirectiveHoverProvider($project);
    $dirHover = $hoverProvider->get($document, ['line' => 2, 'character' => 3]);

    expect($dirHover)->not->toBeNull();
    expect($dirHover['contents']['value'])->toContain('@inject(string $variable, string|class-string $service)');
    expect($dirHover['contents']['value'])->toContain('BindingResolutionException');

    // 6. Test Hover on @use directive (Line 0, char 2)
    $useHover = $hoverProvider->get($document, ['line' => 0, 'character' => 2]);

    expect($useHover)->not->toBeNull();
    expect($useHover['contents']['value'])->toContain('@use(string $class, ?string $as = null)');

    // 7. Test Injected variable in Blade variable scope (Line 6, char 4 after $)
    $varProvider = new BladeVariableCompletionProvider($project);
    $vars = $varProvider->get($document, ['line' => 6, 'character' => 4]);
    $serviceVar = collect($vars)->firstWhere('label', '$service');

    expect($serviceVar)->not->toBeNull();
    expect($serviceVar['labelDetails']['description'])->toContain('MetricsService');

    // 8. Test Hover on other Blade directives (@include, @props, @aware, @error, @vite)
    $includeDoc = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "@include('partials.header')\n@props(['title'])\n@error('email')\n@vite(['app.js'])");
    $includeHover = $hoverProvider->get($includeDoc, ['line' => 0, 'character' => 4]);
    expect($includeHover)->not->toBeNull();
    expect($includeHover['contents']['value'])->toContain('@include(string $view, array $data = [], array $mergeData = [])');
    expect($includeHover['contents']['value'])->toContain('InvalidArgumentException');

    $propsHover = $hoverProvider->get($includeDoc, ['line' => 1, 'character' => 3]);
    expect($propsHover)->not->toBeNull();
    expect($propsHover['contents']['value'])->toContain('@props(array $props)');

    $errorHover = $hoverProvider->get($includeDoc, ['line' => 2, 'character' => 3]);
    expect($errorHover)->not->toBeNull();
    expect($errorHover['contents']['value'])->toContain('@error(string $key, string $bag = \'default\')');

    $viteHover = $hoverProvider->get($includeDoc, ['line' => 3, 'character' => 3]);
    expect($viteHover)->not->toBeNull();
    expect($viteHover['contents']['value'])->toContain('@vite(string|array $entrypoints, ?string $buildDirectory = null)');

    // 9. Test Hover on @inject service argument (Line 4, char 25 in $document)
    $serviceArgHover = $hoverProvider->get($document, ['line' => 4, 'character' => 25]);
    expect($serviceArgHover)->not->toBeNull();
    expect($serviceArgHover['contents']['value'])->toContain('Injected Service: `$service`');
    expect($serviceArgHover['contents']['value'])->toContain('App\Services\MetricsService');

    @unlink($tempDir . '/app/Factories/UserFactory.php');
    @unlink($tempDir . '/app/Services/MetricsService.php');
    @unlink($tempDir . '/resources/views/test.blade.php');
    @rmdir($tempDir . '/app/Factories');
    @rmdir($tempDir . '/app/Services');
    @rmdir($tempDir . '/app/Models');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/resources');
    @rmdir($tempDir);
});
