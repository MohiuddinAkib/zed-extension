<?php

declare(strict_types=1);

use App\Lsp\Analysis\PhpMacroAstAnalyzer;

test('PhpMacroAstAnalyzer extracts closures, arrow functions, and mixins', function () {
    $code = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureHttpMacros();

        Str::macro('prefix', fn (string $str, string $prefix): string => $prefix . $str);
    }

    protected function configureHttpMacros(): void
    {
        PendingRequest::macro('withCaching', function (int $ttl = 3600): PendingRequest {
            return $this->withOptions(['cache_ttl' => $ttl]);
        });

        Http::macro('smsq', function (string $to, string $msg): PendingRequest {
            return Http::baseUrl('https://sms.api')->withHeaders(['to' => $to]);
        });
    }
}
PHP;

    $analyzer = new PhpMacroAstAnalyzer();
    $macros = $analyzer->extractFromCode($code, '/path/to/AppServiceProvider.php');

    expect($macros)->toHaveCount(3);

    $byName = collect($macros)->keyBy('name');

    // 1. withCaching
    expect($byName)->toHaveKey('withCaching');
    $withCaching = $byName['withCaching'];
    expect($withCaching->targetClass)->toBe('Illuminate\Http\Client\PendingRequest');
    expect($withCaching->facadeClass)->toBeNull();
    expect($withCaching->parameters)->toHaveCount(1);
    expect($withCaching->parameters[0]->name)->toBe('ttl');
    expect($withCaching->parameters[0]->type->displayName)->toBe('int');
    expect($withCaching->parameters[0]->defaultValue)->toBe('3600');
    expect($withCaching->parameters[0]->required)->toBeFalse();
    expect($withCaching->returnType->displayName)->toBeIn(['PendingRequest', 'Illuminate\Http\Client\PendingRequest']);
    expect($withCaching->sourceLine)->toBeGreaterThan(15);
    expect($withCaching->sourcePath)->toBe('/path/to/AppServiceProvider.php');

    // 2. smsq
    expect($byName)->toHaveKey('smsq');
    $smsq = $byName['smsq'];
    expect($smsq->targetClass)->toBe('Illuminate\Support\Facades\Http');
    expect($smsq->facadeClass)->toBe('Illuminate\Support\Facades\Http');
    expect($smsq->parameters)->toHaveCount(2);
    expect($smsq->parameters[0]->name)->toBe('to');
    expect($smsq->parameters[0]->required)->toBeTrue();
    expect($smsq->parameters[1]->name)->toBe('msg');
    expect($smsq->parameters[1]->required)->toBeTrue();

    // 3. prefix (arrow function)
    expect($byName)->toHaveKey('prefix');
    $prefix = $byName['prefix'];
    expect($prefix->targetClass)->toBe('Illuminate\Support\Str');
    expect($prefix->returnType->displayName)->toBe('string');
});

test('PhpMacroAstAnalyzer extracts methods from class mixins', function () {
    $code = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Str;

class StringMixin
{
    public function __construct() {}

    public static function helper(): void {}

    public function extractDomain(): \Closure
    {
        return function (string $url): string {
            return parse_url($url, PHP_URL_HOST) ?? '';
        };
    }

    public function directMethod(string $value, ?int $limit = 10): string
    {
        return substr($value, 0, $limit ?? 10);
    }
}

class AppServiceProvider
{
    public function boot()
    {
        Str::mixin(new StringMixin());
    }
}
PHP;

    $analyzer = new PhpMacroAstAnalyzer();
    $macros = $analyzer->extractFromCode($code, '/path/to/StringMixin.php');

    $byName = collect($macros)->keyBy('name');
    expect($byName)->not->toHaveKey('__construct');
    expect($byName)->not->toHaveKey('helper');
    expect($byName)->toHaveKey('extractDomain');
    expect($byName)->toHaveKey('directMethod');

    $extractDomain = $byName['extractDomain'];
    expect($extractDomain->targetClass)->toBe('Illuminate\Support\Str');
    expect($extractDomain->parameters)->toHaveCount(1);
    expect($extractDomain->parameters[0]->name)->toBe('url');
    expect($extractDomain->returnType->displayName)->toBe('string');

    $directMethod = $byName['directMethod'];
    expect($directMethod->targetClass)->toBe('Illuminate\Support\Str');
    expect($directMethod->parameters)->toHaveCount(2);
    expect($directMethod->parameters[0]->name)->toBe('value');
    expect($directMethod->parameters[0]->required)->toBeTrue();
    expect($directMethod->parameters[1]->name)->toBe('limit');
    expect($directMethod->parameters[1]->type->displayName)->toBe('?int');
    expect($directMethod->parameters[1]->defaultValue)->toBe('10');
    expect($directMethod->parameters[1]->required)->toBeFalse();
    expect($directMethod->returnType->displayName)->toBe('string');
});

test('PhpMacroAstAnalyzer extracts mixins using class constant fetch', function () {
    $code = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Collection;

class CollectionMixin
{
    public function customFilter(): \Closure
    {
        return function (callable $callback, int $mode = 0): Collection {
            return $this->filter($callback, $mode);
        };
    }
}

Collection::mixin(CollectionMixin::class);
PHP;

    $analyzer = new PhpMacroAstAnalyzer();
    $macros = $analyzer->extractFromCode($code, '/path/to/CollectionMixin.php');

    $byName = collect($macros)->keyBy('name');
    expect($byName)->toHaveKey('customFilter');
    $customFilter = $byName['customFilter'];
    expect($customFilter->targetClass)->toBe('Illuminate\Support\Collection');
    expect($customFilter->parameters)->toHaveCount(2);
    expect($customFilter->parameters[0]->name)->toBe('callback');
    expect($customFilter->parameters[0]->type->displayName)->toBe('callable');
    expect($customFilter->parameters[1]->name)->toBe('mode');
    expect($customFilter->parameters[1]->defaultValue)->toBe('0');
});

test('PhpMacroAstAnalyzer extracts array callables referencing classes in same file', function () {
    $code = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Str;

class CustomStrMacros
{
    public function titleCase(): \Closure
    {
        return function (string $val): string {
            return ucwords($val);
        };
    }
}

Str::macro('titleCase', [CustomStrMacros::class, 'titleCase']);
PHP;

    $analyzer = new PhpMacroAstAnalyzer();
    $macros = $analyzer->extractFromCode($code, '/path/to/CustomStrMacros.php');

    expect($macros)->toHaveCount(1);
    expect($macros[0]->name)->toBe('titleCase');
    expect($macros[0]->targetClass)->toBe('Illuminate\Support\Str');
    expect($macros[0]->parameters)->toHaveCount(1);
    expect($macros[0]->parameters[0]->name)->toBe('val');
    expect($macros[0]->parameters[0]->type->displayName)->toBe('string');
    expect($macros[0]->returnType->displayName)->toBe('string');
});

test('PhpMacroAstAnalyzer handles complex types including unions and nullable values', function () {
    $code = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Collection;

Collection::macro('flexible', function (string|int $key, ?array $options = null): Collection|array {
    return [];
});
PHP;

    $analyzer = new PhpMacroAstAnalyzer();
    $macros = $analyzer->extractFromCode($code);

    expect($macros)->toHaveCount(1);
    $macro = $macros[0];
    expect($macro->name)->toBe('flexible');
    expect($macro->parameters)->toHaveCount(2);
    expect($macro->parameters[0]->name)->toBe('key');
    expect($macro->parameters[0]->type->displayName)->toBe('string|int');
    expect($macro->parameters[0]->required)->toBeTrue();
    expect($macro->parameters[1]->name)->toBe('options');
    expect($macro->parameters[1]->type->displayName)->toBe('?array');
    expect($macro->parameters[1]->defaultValue)->toBe('null');
    expect($macro->parameters[1]->required)->toBeFalse();
});

test('PhpMacroAstAnalyzer gracefully handles empty or invalid code', function () {
    $analyzer = new PhpMacroAstAnalyzer();

    expect($analyzer->extractFromCode(''))->toBe([]);
    expect($analyzer->extractFromCode('<?php echo "no macros";'))->toBe([]);
    expect($analyzer->extractFromCode('<?php invalid php syntax {{{'))->toBe([]);
});
