<?php

declare(strict_types=1);

use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\MacroSymbol;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Support\FileUri;

test('MacroRegistry discovers macros in project and bridges Facades with concrete classes', function () {
    $tempDir = sys_get_temp_dir() . '/macro_reg_' . uniqid();
    @mkdir($tempDir . '/app/Providers', 0777, true);

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PendingRequest::macro('withCaching', function (int $ttl = 3600): PendingRequest {
            return $this->withOptions(['cache_ttl' => $ttl]);
        });

        Http::macro('smsq', function (string $to): PendingRequest {
            return Http::baseUrl('https://sms.api');
        });

        Collection::macro('toCsv', function (): string {
            return implode(',', $this->all());
        });
    }
}
PHP;
    file_put_contents($tempDir . '/app/Providers/AppServiceProvider.php', $providerCode);

    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $registry = new MacroRegistry($project);

    // 1. PendingRequest has withCaching and smsq (via Http bridging)
    $pendingMacros = $registry->getMacrosForClass('Illuminate\Http\Client\PendingRequest');
    expect($pendingMacros)->toHaveKey('withCaching');
    expect($pendingMacros)->toHaveKey('smsq');

    // 2. Http facade has smsq and withCaching (via PendingRequest bridging)
    $httpMacros = $registry->getMacrosForClass('Illuminate\Support\Facades\Http');
    expect($httpMacros)->toHaveKey('smsq');
    expect($httpMacros)->toHaveKey('withCaching');

    // 3. Collection macros available on base, LazyCollection, and Eloquent Collection
    $colMacros = $registry->getMacrosForClass('Illuminate\Support\Collection');
    expect($colMacros)->toHaveKey('toCsv');

    $eloquentColMacros = $registry->getMacrosForClass('Illuminate\Database\Eloquent\Collection');
    expect($eloquentColMacros)->toHaveKey('toCsv');

    // 4. getMacro returns single macro or null
    $macro = $registry->getMacro('Illuminate\Http\Client\PendingRequest', 'withCaching');
    expect($macro)->not->toBeNull();
    expect($macro->name)->toBe('withCaching');

    $macroBridged = $registry->getMacro('Illuminate\Support\Facades\Http', 'withCaching');
    expect($macroBridged)->not->toBeNull();
    expect($macroBridged->name)->toBe('withCaching');

    $nonExistent = $registry->getMacro('Illuminate\Support\Facades\Http', 'doesNotExist');
    expect($nonExistent)->toBeNull();

    // 5. Short basename lookup
    $shortLookup = $registry->getMacrosForClass('Http');
    expect($shortLookup)->toHaveKey('smsq');
    expect($shortLookup)->toHaveKey('withCaching');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('MacroRegistry supports manual macro registration with bridging', function () {
    $registry = new MacroRegistry();

    $macro = new MacroSymbol(
        name: 'customStrHelper',
        targetClass: 'Illuminate\Support\Str',
        facadeClass: null,
        parameters: [],
        returnType: TypeRef::fromString('string'),
        sourcePath: '/path/to/file.php',
        sourceLine: 10,
    );

    $registry->registerMacro($macro);

    expect($registry->getMacrosForClass('Illuminate\Support\Str'))->toHaveKey('customStrHelper');
    expect($registry->getMacrosForClass('Illuminate\Support\Stringable'))->toHaveKey('customStrHelper');
    expect($registry->getMacrosForClass('Str'))->toHaveKey('customStrHelper');
    expect($registry->getMacro('Illuminate\Support\Stringable', 'customStrHelper'))->not->toBeNull();
    expect($registry->getMacro('UnknownClass', 'customStrHelper'))->toBeNull();
});

test('MacroRegistry scans app/Macros and app/Mixins directories', function () {
    $tempDir = sys_get_temp_dir() . '/macro_reg_' . uniqid();
    @mkdir($tempDir . '/app/Macros', 0777, true);
    @mkdir($tempDir . '/app/Mixins', 0777, true);

    $macroCode = <<<'PHP'
<?php

use Illuminate\Support\Str;

Str::macro('shout', function (string $val): string {
    return strtoupper($val);
});
PHP;
    file_put_contents($tempDir . '/app/Macros/StrMacros.php', $macroCode);

    $mixinCode = <<<'PHP'
<?php

namespace App\Mixins;

use Illuminate\Routing\Router;

class RouterMixin
{
    public function apiResourceWithTrash(): \Closure
    {
        return function (string $name, string $controller): void {};
    }
}

Router::mixin(new RouterMixin());
PHP;
    file_put_contents($tempDir . '/app/Mixins/RouterMixin.php', $mixinCode);

    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $registry = new MacroRegistry($project);

    $strMacros = $registry->getMacrosForClass('Illuminate\Support\Str');
    expect($strMacros)->toHaveKey('shout');

    $routerMacros = $registry->getMacrosForClass('Illuminate\Routing\Router');
    expect($routerMacros)->toHaveKey('apiResourceWithTrash');

    // Route facade bridged to Router
    $routeFacadeMacros = $registry->getMacrosForClass('Illuminate\Support\Facades\Route');
    expect($routeFacadeMacros)->toHaveKey('apiResourceWithTrash');

    @unlink($tempDir . '/app/Macros/StrMacros.php');
    @unlink($tempDir . '/app/Mixins/RouterMixin.php');
    @rmdir($tempDir . '/app/Macros');
    @rmdir($tempDir . '/app/Mixins');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('MacroRegistry bridges Response facade and Eloquent/Query Builder correctly', function () {
    $registry = new MacroRegistry();

    $responseMacro = new MacroSymbol(
        name: 'customXml',
        targetClass: 'Illuminate\Support\Facades\Response',
        facadeClass: 'Illuminate\Support\Facades\Response',
        parameters: [],
        returnType: TypeRef::fromString('Illuminate\Http\Response'),
        sourcePath: '/path/to/ResponseMacro.php',
        sourceLine: 12,
    );
    $registry->registerMacro($responseMacro);

    expect($registry->getMacrosForClass('Illuminate\Support\Facades\Response'))->toHaveKey('customXml');
    expect($registry->getMacrosForClass('Illuminate\Http\Response'))->toHaveKey('customXml');
    expect($registry->getMacrosForClass('Illuminate\Http\JsonResponse'))->toHaveKey('customXml');
    expect($registry->getMacrosForClass('Illuminate\Contracts\Routing\ResponseFactory'))->toHaveKey('customXml');

    $builderMacro = new MacroSymbol(
        name: 'whereLike',
        targetClass: 'Illuminate\Database\Eloquent\Builder',
        facadeClass: null,
        parameters: [],
        returnType: TypeRef::fromString('Illuminate\Database\Eloquent\Builder'),
        sourcePath: '/path/to/BuilderMacro.php',
        sourceLine: 20,
    );
    $registry->registerMacro($builderMacro);

    expect($registry->getMacrosForClass('Illuminate\Database\Eloquent\Builder'))->toHaveKey('whereLike');
    expect($registry->getMacrosForClass('Illuminate\Database\Query\Builder'))->toHaveKey('whereLike');
    expect($registry->getMacrosForClass('Builder'))->toHaveKey('whereLike');
});

test('MacroRegistry handles null project and corrupted files gracefully', function () {
    $tempDir = sys_get_temp_dir() . '/macro_reg_' . uniqid();
    @mkdir($tempDir . '/app', 0777, true);

    file_put_contents($tempDir . '/app/Broken.php', '<?php syntax error {{{ macro');
    file_put_contents($tempDir . '/app/NoMacro.php', '<?php class PlainClass {}');

    $mockIndex = Mockery::mock(ProjectIndex::class)->shouldIgnoreMissing();
    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $registry = new MacroRegistry($project);
    expect($registry->getMacrosForClass('App\Broken'))->toBe([]);

    $nullProjectRegistry = new MacroRegistry(null);
    expect($nullProjectRegistry->getMacrosForClass('AnyClass'))->toBe([]);

    @unlink($tempDir . '/app/Broken.php');
    @unlink($tempDir . '/app/NoMacro.php');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});
