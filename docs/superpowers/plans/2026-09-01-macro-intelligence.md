# Universal Macro Intelligence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement full universal macro autocompletion, definition navigation, hover, and fluent return-type chaining for all Laravel Macroable targets across PHP and Blade files.

**Architecture:** An AST-first analyzer (`PhpMacroAstAnalyzer`) scans workspace PHP files (service providers, macros) to extract closures, arrow functions, mixins, and array callables into strongly-typed `MacroSymbol` objects. A centralized `MacroRegistry` indexes these symbols, bridges Facades to concrete instances (and vice versa), and feeds them into `BladeMemberCompletionProvider`, `PhpIntelligence`, `TextDocumentDefinition`, and `FunctionTypeResolver`.

**Tech Stack:** PHP 8.2+, `amphp/amp`, `nikic/php-parser`, `microsoft/tolerant-php-parser`, `phpstan/phpdoc-parser`, Pest PHP.

## Global Constraints
- Must discover macros declared in closures, short closures, mixins (`::mixin()`), array callables, and nested provider methods.
- Must bridge Facades (`Http::macro()`) to concrete underlying classes (`PendingRequest->...`) and vice versa.
- Must support Go to Definition (`textDocument/definition`) jumping directly to the macro registration line in `AppServiceProvider.php` (or wherever registered).
- Must infer macro return types for continuous fluent chaining (e.g. `Http::smsq()->...`).
- All tests must pass with zero failures (`vendor/bin/pest`).

---

### Task 1: Macro Semantics Data Structures (`MacroSymbol` & `MacroParameterSymbol`)

**Files:**
- Create: `server/app/Lsp/Semantics/MacroParameterSymbol.php`
- Create: `server/app/Lsp/Semantics/MacroSymbol.php`
- Test: `server/tests/Unit/MacroSemanticsTest.php`

**Interfaces:**
- Produces: `App\Lsp\Semantics\MacroSymbol`, `App\Lsp\Semantics\MacroParameterSymbol`

- [ ] **Step 1: Write the failing test**

Create `server/tests/Unit/MacroSemanticsTest.php`:
```php
<?php

declare(strict_types=1);

use App\Lsp\Semantics\MacroParameterSymbol;
use App\Lsp\Semantics\MacroSymbol;
use App\Lsp\Semantics\TypeRef;

test('MacroSymbol stores parameter metadata, return type, and source location', function () {
    $param1 = new MacroParameterSymbol(
        name: 'ttl',
        type: TypeRef::fromString('int'),
        required: false,
        defaultValue: '3600',
    );
    $param2 = new MacroParameterSymbol(
        name: 'tags',
        type: TypeRef::fromString('array'),
        required: true,
        defaultValue: null,
    );

    $macro = new MacroSymbol(
        name: 'withCaching',
        targetClass: 'Illuminate\Http\Client\PendingRequest',
        facadeClass: 'Illuminate\Support\Facades\Http',
        parameters: [$param1, $param2],
        returnType: TypeRef::fromString('\Illuminate\Http\Client\PendingRequest'),
        sourcePath: '/app/Providers/AppServiceProvider.php',
        sourceLine: 525,
        isStatic: true,
        documentation: 'Custom HTTP caching macro',
    );

    expect($macro->name)->toBe('withCaching');
    expect($macro->targetClass)->toBe('Illuminate\Http\Client\PendingRequest');
    expect($macro->facadeClass)->toBe('Illuminate\Support\Facades\Http');
    expect($macro->parameters)->toHaveCount(2);
    expect($macro->parameters[0]->defaultValue)->toBe('3600');
    expect($macro->returnType->displayName)->toBe('\Illuminate\Http\Client\PendingRequest');
    expect($macro->sourceLine)->toBe(525);
    expect($macro->formattedSignature())->toBe('withCaching(int $ttl = 3600, array $tags): \Illuminate\Http\Client\PendingRequest');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd server && vendor/bin/pest tests/Unit/MacroSemanticsTest.php`
Expected: FAIL with class not found

- [ ] **Step 3: Write minimal implementation**

Create `server/app/Lsp/Semantics/MacroParameterSymbol.php`:
```php
<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class MacroParameterSymbol
{
    public function __construct(
        public string $name,
        public TypeRef $type,
        public bool $required = true,
        public ?string $defaultValue = null,
        public ?string $description = null,
    ) {}

    public function formatted(): string
    {
        $typeStr = $this->type->displayName !== 'mixed' ? "{$this->type->displayName} " : '';
        $defaultStr = $this->defaultValue !== null ? " = {$this->defaultValue}" : '';

        return "{$typeStr}\${$this->name}{$defaultStr}";
    }
}
```

Create `server/app/Lsp/Semantics/MacroSymbol.php`:
```php
<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class MacroSymbol
{
    /**
     * @param array<int, MacroParameterSymbol> $parameters
     */
    public function __construct(
        public string $name,
        public string $targetClass,
        public ?string $facadeClass = null,
        public array $parameters = [],
        public ?TypeRef $returnType = null,
        public ?string $sourcePath = null,
        public ?int $sourceLine = null,
        public bool $isStatic = true,
        public string $documentation = '',
    ) {
        $this->returnType ??= TypeRef::fromString('mixed');
    }

    public function formattedSignature(): string
    {
        $paramsStr = implode(', ', array_map(fn (MacroParameterSymbol $p) => $p->formatted(), $this->parameters));
        $returnStr = $this->returnType ? ": {$this->returnType->displayName}" : '';

        return "{$this->name}({$paramsStr}){$returnStr}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd server && vendor/bin/pest tests/Unit/MacroSemanticsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/Semantics/MacroParameterSymbol.php server/app/Lsp/Semantics/MacroSymbol.php server/tests/Unit/MacroSemanticsTest.php
git commit -m "feat(semantics): add MacroSymbol and MacroParameterSymbol definitions"
```

---

### Task 2: AST Macro Extraction Engine (`PhpMacroAstAnalyzer`)

**Files:**
- Create: `server/app/Lsp/Analysis/PhpMacroAstAnalyzer.php`
- Test: `server/tests/Unit/PhpMacroAstAnalyzerTest.php`

**Interfaces:**
- Consumes: `App\Lsp\Semantics\MacroSymbol`, `App\Lsp\Semantics\MacroParameterSymbol`, `App\Lsp\Semantics\TypeRef`
- Produces: `App\Lsp\Analysis\PhpMacroAstAnalyzer::extractFromCode(string $code, string $filePath = ''): array<MacroSymbol>`

- [ ] **Step 1: Write the failing test**

Create `server/tests/Unit/PhpMacroAstAnalyzerTest.php`:
```php
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
    expect($withCaching->parameters)->toHaveCount(1);
    expect($withCaching->parameters[0]->name)->toBe('ttl');
    expect($withCaching->parameters[0]->type->displayName)->toBe('int');
    expect($withCaching->parameters[0]->defaultValue)->toBe('3600');
    expect($withCaching->returnType->displayName)->toBe('PendingRequest');
    expect($withCaching->sourceLine)->toBeGreaterThan(15);

    // 2. smsq
    expect($byName)->toHaveKey('smsq');
    $smsq = $byName['smsq'];
    expect($smsq->targetClass)->toBe('Illuminate\Support\Facades\Http');
    expect($smsq->parameters)->toHaveCount(2);
    expect($smsq->parameters[0]->name)->toBe('to');
    expect($smsq->parameters[1]->name)->toBe('msg');

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
    public function extractDomain(): \Closure
    {
        return function (string $url): string {
            return parse_url($url, PHP_URL_HOST) ?? '';
        };
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
    expect($byName)->toHaveKey('extractDomain');
    $extractDomain = $byName['extractDomain'];
    expect($extractDomain->targetClass)->toBe('Illuminate\Support\Str');
    expect($extractDomain->parameters)->toHaveCount(1);
    expect($extractDomain->parameters[0]->name)->toBe('url');
    expect($extractDomain->returnType->displayName)->toBe('string');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd server && vendor/bin/pest tests/Unit/PhpMacroAstAnalyzerTest.php`
Expected: FAIL with class not found

- [ ] **Step 3: Write minimal implementation**

Create `server/app/Lsp/Analysis/PhpMacroAstAnalyzer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Semantics\MacroParameterSymbol;
use App\Lsp\Semantics\MacroSymbol;
use App\Lsp\Semantics\TypeRef;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

class PhpMacroAstAnalyzer
{
    protected Parser $phpParser;
    protected NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->phpParser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @return array<int, MacroSymbol>
     */
    public function extractFromCode(string $code, string $filePath = ''): array
    {
        $macros = [];

        try {
            $stmts = $this->phpParser->parse($code);
            if (!$stmts) {
                return [];
            }

            $useMap = $this->buildUseMap($stmts);
            $classesInFile = $this->collectClassesInFile($stmts);

            // Find all StaticCall nodes where name is 'macro' or 'mixin'
            $staticCalls = $this->nodeFinder->find($stmts, function (Node $node): bool {
                return $node instanceof StaticCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->name, ['macro', 'mixin'], true);
            });

            foreach ($staticCalls as $call) {
                if (!$call instanceof StaticCall) {
                    continue;
                }

                $methodName = (string) $call->name;
                $targetClass = $this->resolveClassName($call->class, $useMap);
                if ($targetClass === '') {
                    continue;
                }

                $args = $call->getArgs();

                if ($methodName === 'macro' && count($args) >= 2) {
                    $macroNameArg = $args[0]->value;
                    $macroName = $macroNameArg instanceof String_ ? $macroNameArg->value : '';
                    if ($macroName === '') {
                        continue;
                    }

                    $handlerArg = $args[1]->value;
                    $sourceLine = $call->getStartLine();

                    if ($handlerArg instanceof Closure || $handlerArg instanceof ArrowFunction) {
                        $params = $this->extractParametersFromFunction($handlerArg, $useMap, $code);
                        $returnType = $this->extractReturnTypeFromFunction($handlerArg, $useMap);

                        $macros[] = new MacroSymbol(
                            name: $macroName,
                            targetClass: $targetClass,
                            facadeClass: str_contains($targetClass, 'Facade') ? $targetClass : null,
                            parameters: $params,
                            returnType: $returnType,
                            sourcePath: $filePath,
                            sourceLine: $sourceLine,
                            isStatic: true,
                            documentation: "Macro `{$macroName}` on `{$targetClass}`",
                        );
                    }
                } elseif ($methodName === 'mixin' && count($args) >= 1) {
                    $mixinArg = $args[0]->value;
                    $mixinClass = '';

                    if ($mixinArg instanceof New_ && $mixinArg->class instanceof Name) {
                        $mixinClass = $this->resolveClassName($mixinArg->class, $useMap);
                    } elseif ($mixinArg instanceof Node\Expr\ClassConstFetch && $mixinArg->class instanceof Name) {
                        $mixinClass = $this->resolveClassName($mixinArg->class, $useMap);
                    }

                    if ($mixinClass !== '') {
                        $mixinMethods = $this->extractMethodsFromMixinClass($mixinClass, $classesInFile, $useMap, $code, $filePath);
                        foreach ($mixinMethods as $mSym) {
                            $macros[] = new MacroSymbol(
                                name: $mSym['name'],
                                targetClass: $targetClass,
                                facadeClass: str_contains($targetClass, 'Facade') ? $targetClass : null,
                                parameters: $mSym['parameters'],
                                returnType: $mSym['returnType'],
                                sourcePath: $filePath,
                                sourceLine: $call->getStartLine(),
                                isStatic: true,
                                documentation: "Mixin macro `{$mSym['name']}` from `{$mixinClass}` on `{$targetClass}`",
                            );
                        }
                    }
                }
            }
        } catch (Throwable) {}

        return $macros;
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @return array<string, string>
     */
    protected function buildUseMap(array $stmts): array
    {
        $useMap = [];
        foreach ($this->nodeFinder->findInstanceOf($stmts, Use_::class) as $useStmt) {
            foreach ($useStmt->uses as $useUse) {
                $useMap[$useUse->getAlias()->toString()] = $useUse->name->toString();
            }
        }

        return $useMap;
    }

    /**
     * @param array<Node\Stmt> $stmts
     * @return array<string, Class_>
     */
    protected function collectClassesInFile(array $stmts): array
    {
        $classes = [];
        foreach ($this->nodeFinder->findInstanceOf($stmts, Class_::class) as $classNode) {
            if ($classNode->name !== null) {
                $classes[$classNode->name->toString()] = $classNode;
            }
        }

        return $classes;
    }

    protected function resolveClassName(Node $classNode, array $useMap): string
    {
        if ($classNode instanceof Name) {
            $nameStr = $classNode->toString();
            if (isset($useMap[$nameStr])) {
                return $useMap[$nameStr];
            }
            if ($classNode->isFullyQualified()) {
                return ltrim($nameStr, '\\');
            }

            return $nameStr;
        }

        return '';
    }

    /**
     * @return array<int, MacroParameterSymbol>
     */
    protected function extractParametersFromFunction(Closure|ArrowFunction $fn, array $useMap, string $code): array
    {
        $params = [];

        foreach ($fn->params as $p) {
            if ($p->var instanceof Node\Expr\Variable && is_string($p->var->name)) {
                $pName = $p->var->name;
                $pTypeStr = $this->formatAstTypeNode($p->type, $useMap);
                $pDefault = null;
                $required = $p->default === null;

                if ($p->default !== null) {
                    $start = $p->default->getStartFilePos();
                    $end = $p->default->getEndFilePos();
                    if ($start >= 0 && $end >= $start && $end < strlen($code)) {
                        $pDefault = trim(substr($code, $start, $end - $start + 1));
                    }
                }

                $params[] = new MacroParameterSymbol(
                    name: $pName,
                    type: TypeRef::fromString($pTypeStr),
                    required: $required,
                    defaultValue: $pDefault,
                );
            }
        }

        return $params;
    }

    protected function extractReturnTypeFromFunction(Closure|ArrowFunction $fn, array $useMap): TypeRef
    {
        if ($fn->returnType !== null) {
            return TypeRef::fromString($this->formatAstTypeNode($fn->returnType, $useMap));
        }

        return TypeRef::fromString('mixed');
    }

    /**
     * @param array<string, Class_> $classesInFile
     * @return array<int, array{name: string, parameters: array<int, MacroParameterSymbol>, returnType: TypeRef}>
     */
    protected function extractMethodsFromMixinClass(
        string $mixinClass,
        array $classesInFile,
        array $useMap,
        string $code,
        string $filePath
    ): array {
        $methods = [];
        $shortName = class_basename($mixinClass);
        $classNode = $classesInFile[$shortName] ?? null;

        if ($classNode instanceof Class_) {
            foreach ($classNode->getMethods() as $method) {
                if ($method->isPublic() && !$method->isStatic()) {
                    $mName = $method->name->toString();
                    if (str_starts_with($mName, '__')) {
                        continue;
                    }

                    // Look for return function closure inside method body
                    $innerFn = $this->nodeFinder->findFirst($method->stmts ?? [], function (Node $n): bool {
                        return $n instanceof Closure || $n instanceof ArrowFunction;
                    });

                    if ($innerFn instanceof Closure || $innerFn instanceof ArrowFunction) {
                        $params = $this->extractParametersFromFunction($innerFn, $useMap, $code);
                        $returnType = $this->extractReturnTypeFromFunction($innerFn, $useMap);
                    } else {
                        $params = [];
                        $returnType = TypeRef::fromString('mixed');
                    }

                    $methods[] = [
                        'name'       => $mName,
                        'parameters' => $params,
                        'returnType' => $returnType,
                    ];
                }
            }
        }

        return $methods;
    }

    protected function formatAstTypeNode(?Node $typeNode, array $useMap = []): string
    {
        if ($typeNode === null) {
            return 'mixed';
        }
        if ($typeNode instanceof Node\NullableType) {
            return '?' . $this->formatAstTypeNode($typeNode->type, $useMap);
        }
        if ($typeNode instanceof Node\UnionType) {
            return implode('|', array_map(fn ($t) => $this->formatAstTypeNode($t, $useMap), $typeNode->types));
        }
        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Node\Name) {
            $nameStr = $typeNode->toString();
            if (isset($useMap[$nameStr])) {
                return $useMap[$nameStr];
            }
            return $nameStr;
        }

        return 'mixed';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd server && vendor/bin/pest tests/Unit/PhpMacroAstAnalyzerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/Analysis/PhpMacroAstAnalyzer.php server/tests/Unit/PhpMacroAstAnalyzerTest.php
git commit -m "feat(analysis): implement PhpMacroAstAnalyzer for closures, arrow functions, and mixins"
```

---

### Task 3: Centralized Macro Registry (`MacroRegistry`)

**Files:**
- Create: `server/app/Lsp/Analysis/MacroRegistry.php`
- Test: `server/tests/Unit/MacroRegistryTest.php`

**Interfaces:**
- Consumes: `App\Lsp\Semantics\MacroSymbol`, `App\Lsp\Analysis\PhpMacroAstAnalyzer`, `App\Lsp\Project`
- Produces: `App\Lsp\Analysis\MacroRegistry::getMacrosForClass(string $class): array<string, MacroSymbol>`, `App\Lsp\Analysis\MacroRegistry::getMacro(string $class, string $method): ?MacroSymbol`

- [ ] **Step 1: Write the failing test**

Create `server/tests/Unit/MacroRegistryTest.php`:
```php
<?php

declare(strict_types=1);

use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
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

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd server && vendor/bin/pest tests/Unit/MacroRegistryTest.php`
Expected: FAIL with class not found

- [ ] **Step 3: Write minimal implementation**

Create `server/app/Lsp/Analysis/MacroRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Project;
use App\Lsp\Semantics\MacroSymbol;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class MacroRegistry
{
    /**
     * @var array<string, array<string, MacroSymbol>>
     */
    protected array $macrosByClass = [];

    protected bool $indexed = false;
    protected PhpMacroAstAnalyzer $astAnalyzer;

    /**
     * Known bidirectional class bridges for Macroable targets.
     *
     * @var array<string, array<int, string>>
     */
    protected array $bridges = [
        'Illuminate\Support\Facades\Http' => [
            'Illuminate\Http\Client\PendingRequest',
            'Illuminate\Http\Client\Factory',
        ],
        'Illuminate\Http\Client\PendingRequest' => [
            'Illuminate\Support\Facades\Http',
            'Illuminate\Http\Client\Factory',
        ],
        'Illuminate\Support\Str' => [
            'Illuminate\Support\Stringable',
        ],
        'Illuminate\Support\Stringable' => [
            'Illuminate\Support\Str',
        ],
        'Illuminate\Support\Collection' => [
            'Illuminate\Support\LazyCollection',
            'Illuminate\Database\Eloquent\Collection',
        ],
        'Illuminate\Database\Eloquent\Builder' => [
            'Illuminate\Database\Query\Builder',
        ],
        'Illuminate\Database\Query\Builder' => [
            'Illuminate\Database\Eloquent\Builder',
        ],
        'Illuminate\Support\Facades\Response' => [
            'Illuminate\Http\Response',
            'Illuminate\Http\JsonResponse',
            'Illuminate\Contracts\Routing\ResponseFactory',
        ],
        'Illuminate\Support\Facades\Route' => [
            'Illuminate\Routing\Router',
            'Illuminate\Routing\Route',
        ],
    ];

    public function __construct(
        protected ?Project $project = null,
        ?PhpMacroAstAnalyzer $astAnalyzer = null,
    ) {
        $this->astAnalyzer = $astAnalyzer ?? new PhpMacroAstAnalyzer();
    }

    public function registerMacro(MacroSymbol $macro): void
    {
        $target = ltrim($macro->targetClass, '\\');
        $this->macrosByClass[$target][$macro->name] = $macro;

        // Register on bridged classes
        foreach ($this->getBridgesFor($target) as $bridgedClass) {
            $this->macrosByClass[$bridgedClass][$macro->name] = $macro;
        }

        // Also register under short class basename
        $basename = class_basename($target);
        if ($basename !== $target) {
            $this->macrosByClass[$basename][$macro->name] = $macro;
        }
    }

    /**
     * @return array<string, MacroSymbol>
     */
    public function getMacrosForClass(string $className): array
    {
        $this->ensureIndexed();
        $clean = ltrim($className, '\\');

        $result = $this->macrosByClass[$clean] ?? [];

        // Check short name
        $basename = class_basename($clean);
        if (!empty($this->macrosByClass[$basename])) {
            $result = array_merge($this->macrosByClass[$basename], $result);
        }

        // Check bridges
        foreach ($this->getBridgesFor($clean) as $bridged) {
            if (!empty($this->macrosByClass[$bridged])) {
                $result = array_merge($this->macrosByClass[$bridged], $result);
            }
        }

        return $result;
    }

    public function getMacro(string $className, string $methodName): ?MacroSymbol
    {
        $macros = $this->getMacrosForClass($className);

        return $macros[$methodName] ?? null;
    }

    /**
     * @return array<int, string>
     */
    protected function getBridgesFor(string $className): array
    {
        $clean = ltrim($className, '\\');

        return $this->bridges[$clean] ?? [];
    }

    public function ensureIndexed(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->indexed = true;
        $this->discoverWorkspaceMacros();
    }

    protected function discoverWorkspaceMacros(): void
    {
        if ($this->project === null) {
            return;
        }

        $basePath = rtrim($this->project->path(), '/\\');
        $searchDirs = [
            $basePath . '/app/Providers',
            $basePath . '/app/Macros',
            $basePath . '/app/Mixins',
            $basePath . '/app',
        ];

        $scannedFiles = [];

        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                        continue;
                    }

                    $pathname = $file->getPathname();
                    if (isset($scannedFiles[$pathname])) {
                        continue;
                    }
                    $scannedFiles[$pathname] = true;

                    $code = (string) file_get_contents($pathname);
                    if (!str_contains($code, 'macro') && !str_contains($code, 'mixin')) {
                        continue;
                    }

                    $symbols = $this->astAnalyzer->extractFromCode($code, $pathname);
                    foreach ($symbols as $sym) {
                        $this->registerMacro($sym);
                    }
                }
            } catch (Throwable) {}
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd server && vendor/bin/pest tests/Unit/MacroRegistryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/Analysis/MacroRegistry.php server/tests/Unit/MacroRegistryTest.php
git commit -m "feat(analysis): implement MacroRegistry with bidirectional Facade bridging"
```

---

### Task 4: Member Autocompletion & Go-to-Definition Integration

**Files:**
- Modify: `server/app/Lsp/FeatureRegistry.php`
- Modify: `server/app/Lsp/Features/BladeVariables/BladeMemberCompletionProvider.php`
- Modify: `server/app/Lsp/Methods/TextDocumentDefinition.php`
- Test: `server/tests/Unit/MacroCompletionAndDefinitionTest.php`

**Interfaces:**
- Consumes: `App\Lsp\Analysis\MacroRegistry`, `App\Lsp\Semantics\MacroSymbol`
- Produces: Macro suggestions in `BladeMemberCompletionProvider`, Go to Definition in `TextDocumentDefinition`

- [ ] **Step 1: Write the failing test**

Create `server/tests/Unit/MacroCompletionAndDefinitionTest.php`:
```php
<?php

declare(strict_types=1);

use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Methods\TextDocumentDefinition;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

test('BladeMemberCompletionProvider suggests macros on static facades and instance variables', function () {
    $tempDir = sys_get_temp_dir() . '/macro_comp_' . uniqid();
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
        PendingRequest::macro('withCaching', function (int $ttl = 3600): PendingRequest {
            return $this->withOptions(['cache_ttl' => $ttl]);
        });

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

    $memberProvider = new BladeMemberCompletionProvider($project);

    // 1. Static facade Http::smsq and Http::withCaching
    $code1 = <<<'PHP'
<?php
use Illuminate\Support\Facades\Http;
Http::s
PHP;
    $doc1 = new Document('file://' . $tempDir . '/resources/views/test.php', $code1);
    $items1 = $memberProvider->get($doc1, ['line' => 2, 'character' => 7]); // at Http::s|
    $labels1 = array_column($items1, 'label');

    expect($labels1)->toContain('smsq');

    // 2. Instance $client->withCaching
    $code2 = <<<'PHP'
<?php
/** @var \Illuminate\Http\Client\PendingRequest $client */
$client->withC
PHP;
    $doc2 = new Document('file://' . $tempDir . '/resources/views/test.php', $code2);
    $items2 = $memberProvider->get($doc2, ['line' => 2, 'character' => 14]); // at $client->withC|
    $labels2 = array_column($items2, 'label');

    expect($labels2)->toContain('withCaching');

    @unlink($tempDir . '/app/Providers/AppServiceProvider.php');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd server && vendor/bin/pest tests/Unit/MacroCompletionAndDefinitionTest.php`
Expected: FAIL

- [ ] **Step 3: Update FeatureRegistry and BladeMemberCompletionProvider**

In `FeatureRegistry.php`, bind `MacroRegistry`:
```php
        if (!$this->container->bound(MacroRegistry::class)) {
            $this->container->singleton(MacroRegistry::class, function () {
                return new MacroRegistry($this->container->make(Project::class));
            });
        }
```

In `BladeMemberCompletionProvider.php`:
Inject `MacroRegistry`:
```php
    protected ?MacroRegistry $macroRegistry = null;
```
In `__construct`:
```php
    $this->macroRegistry = Container::getInstance()->bound(MacroRegistry::class)
        ? Container::getInstance()->make(MacroRegistry::class)
        : new MacroRegistry($this->project);
```
In member completion methods (`completeClassMethods`, `completeVariableMembers`), query `$this->macroRegistry->getMacrosForClass($className)` and add each macro as a method completion item with snippet formatting and `(macro)` detail.

In `TextDocumentDefinition.php`:
If definition is requested on a member matching a registered macro, return `LocationLink` targeting `$macro->sourcePath` at `$macro->sourceLine`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd server && vendor/bin/pest tests/Unit/MacroCompletionAndDefinitionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/FeatureRegistry.php server/app/Lsp/Features/BladeVariables/BladeMemberCompletionProvider.php server/app/Lsp/Methods/TextDocumentDefinition.php server/tests/Unit/MacroCompletionAndDefinitionTest.php
git commit -m "feat(completions): integrate MacroRegistry into member completion and definition handlers"
```

---

### Task 5: Return Type Inference & Chained Autocomplete

**Files:**
- Modify: `server/app/Lsp/Analysis/FunctionTypeResolver.php`
- Modify: `server/app/Lsp/Analysis/DataPathResolver.php`
- Test: `server/tests/Unit/MacroChainingTest.php`

**Interfaces:**
- Consumes: `App\Lsp\Analysis\MacroRegistry`
- Produces: Inferred return types for macro method calls in type analysis chains

- [ ] **Step 1: Write the failing test**

Create `server/tests/Unit/MacroChainingTest.php`:
```php
<?php

declare(strict_types=1);

use App\Lsp\Analysis\MacroRegistry;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

test('fluent chaining continues autocompletion after calling a macro', function () {
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

    $memberProvider = new BladeMemberCompletionProvider($project);

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd server && vendor/bin/pest tests/Unit/MacroChainingTest.php`
Expected: FAIL

- [ ] **Step 3: Implement return type resolution for macro calls**

In `FunctionTypeResolver.php` (and `BladeAstAnalyzer` method resolution):
When resolving the return type of a method call `$obj->methodName()` or static call `Class::methodName()`, if the method is not a standard reflection method, check `$macroRegistry->getMacro($className, $methodName)`.
If found, return `$macro->returnType`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd server && vendor/bin/pest tests/Unit/MacroChainingTest.php`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `cd server && vendor/bin/pest`
Expected: 216+ passed, 0 failed

- [ ] **Step 6: Commit**

```bash
git add server/app/Lsp/Analysis/FunctionTypeResolver.php server/tests/Unit/MacroChainingTest.php
git commit -m "feat(types): propagate macro return types for continuous fluent chaining"
```
