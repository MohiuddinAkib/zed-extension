<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladePhp;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\ComponentRegistry;
use App\Lsp\Analysis\DocBlockParser;
use App\Lsp\Analysis\FunctionTypeResolver;
use App\Lsp\Analysis\SemanticIndex;
use App\Lsp\Contracts\DiagnosticProvider;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\Facades\FacadeMap;
use App\Lsp\Features\Functions\GlobalFunctionRegistry;
use App\Lsp\Project;
use Illuminate\Container\Container;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Stillat\BladeParser\Document\Document as BladeDocument;
use Throwable;

class BladeSemanticDiagnosticAnalyzer implements DiagnosticProvider
{
    protected BladePhpAstAnalyzer $astAnalyzer;

    protected BladeAstAnalyzer $bladeAnalyzer;

    protected BladeScopeResolver $scopeResolver;

    protected GlobalFunctionRegistry $functionRegistry;

    protected BladeMemberHoverProvider $hoverHelper;

    protected ComponentRegistry $componentRegistry;

    protected DocBlockParser $docBlockParser;

    protected SemanticIndex $semanticIndex;

    protected FunctionTypeResolver $functionTypeResolver;

    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
        ?SemanticIndex $semanticIndex = null,
        ?FunctionTypeResolver $functionTypeResolver = null,
    ) {
        $this->semanticIndex = $semanticIndex ?? $this->resolveSemanticIndex();
        $this->functionRegistry = new GlobalFunctionRegistry($this->project);
        $this->docBlockParser = new DocBlockParser;
        $this->functionTypeResolver = $functionTypeResolver ?? new FunctionTypeResolver($this->project, $this->functionRegistry, $this->docBlockParser, $this->semanticIndex);
        $this->bladeAnalyzer = new BladeAstAnalyzer($this->project, $this->functionTypeResolver);
        $this->astAnalyzer = new BladePhpAstAnalyzer;
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
        $this->hoverHelper = new BladeMemberHoverProvider($this->project, $this->semanticIndex, $this->functionTypeResolver);
        $this->componentRegistry = new ComponentRegistry($this->project);
    }

    protected function resolveSemanticIndex(): SemanticIndex
    {
        $container = Container::getInstance();

        if ($container->bound(SemanticIndex::class)) {
            return $container->make(SemanticIndex::class);
        }

        return new SemanticIndex($this->project);
    }


    /**
     * Get semantic diagnostics for the Blade document (parameter counts, method signatures, component props).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $this->ensureAutoloaderRegistered();

        $diagnostics = [];
        $viewKey = $this->resolveViewKey($document->uri);
        $expressions = $this->astAnalyzer->extractAllExpressions($document->content);

        foreach ($expressions as $expr) {
            $kind = $expr['kind'] ?? '';
            $name = $expr['name'] ?? '';
            $argCount = $expr['argCount'] ?? null;
            $startLine = (int) $expr['startLine'];
            $startCol = (int) $expr['startCol'];
            $endCol = (int) $expr['endCol'];

            $range = [
                'start' => ['line' => $startLine, 'character' => $startCol],
                'end'   => ['line' => $startLine, 'character' => $endCol],
            ];

            // 0. Validate Variable Access (Undefined Variables)
            if ($kind === 'variable' && is_string($name) && $name !== '') {
                $diag = $this->validateVariable($document, $name, $expr, $range, $viewKey);
                if ($diag !== null) {
                    $diagnostics[] = $diag;
                }

                continue;
            }

            // 1. Validate Global & Custom Function Calls
            if ($kind === 'func_call' && $argCount !== null) {

                $diag = $this->validateFunctionCall($name, $argCount, $range);
                if ($diag !== null) {
                    $diagnostics[] = $diag;
                }

                continue;
            }

            // 2. Validate Static Method Calls (e.g. Js::from(), Str::slug(), Post::find())
            if ($kind === 'static_method' && $argCount !== null) {
                $rootClass = $expr['rootCallArg'] ?? '';
                if ($rootClass !== '') {
                    $diag = $this->validateStaticCall($document, $rootClass, $name, $argCount, $range);
                    if ($diag !== null) {
                        $diagnostics[] = $diag;
                    }
                }

                continue;
            }

            // 3. Validate Instance Method Calls (e.g. $user->posts(), $__env->make())
            if ($kind === 'method' && $argCount !== null) {
                $varName = $expr['rootVar'] ?? '';
                $rootCall = $expr['rootCall'] ?? null;
                $rootCallArg = $expr['rootCallArg'] ?? null;
                $chain = $expr['chain'] ?? '';

                $scope = $this->scopeResolver->resolveAtPosition($document, $startLine, $startCol, $viewKey);
                $variables = $scope->legacyVariables();

                $rootType = null;
                if ($rootCall !== null && $rootCall !== 'class') {
                    $argCount = trim($rootCallArg ?? '') === '' ? 0 : 1;
                    $rootType = $this->functionTypeResolver->resolve($rootCall, $rootCallArg, $document, $argCount);
                } elseif ($varName !== '' && isset($variables[$varName])) {
                    $rootType = $variables[$varName]['type'] ?? null;
                }


                if ($rootType && $rootType !== 'mixed') {
                    $targetType = $this->resolveChainedType($rootType, $chain);
                    if ($targetType && $targetType !== 'mixed') {
                        $diag = $this->validateInstanceMethodCall($targetType, $name, $argCount, $range);
                        if ($diag !== null) {
                            $diagnostics[] = $diag;
                        }
                    }
                }
            }
        }

        // 4. Validate Blade Component Tags (<x-alert ...>)
        $componentDiagnostics = $this->validateComponentTags($document);
        foreach ($componentDiagnostics as $cDiag) {
            $diagnostics[] = $cDiag;
        }

        return $diagnostics;
    }

    protected function validateFunctionCall(string $name, int $argCount, array $range): ?array
    {
        $fnInfo = $this->functionRegistry->get($name);
        if ($fnInfo === null) {
            return null;
        }

        $sig = $fnInfo['signature'];
        [$minReq, $maxParams, $isVariadic] = $this->parseSignatureParamCounts($sig, $name);

        if ($argCount < $minReq) {
            return [
                'range'    => $range,
                'severity' => 1, // Error
                'message'  => "Too few arguments to function {$name}(), {$argCount} passed and at least {$minReq} expected.\n\nSignature: {$sig}",
                'source'   => 'laravel-lsp',
            ];
        }

        if (!$isVariadic && $maxParams !== null && $argCount > $maxParams) {
            return [
                'range'    => $range,
                'severity' => 1, // Error
                'message'  => "Too many arguments to function {$name}(), {$argCount} passed and at most {$maxParams} expected.\n\nSignature: {$sig}",
                'source'   => 'laravel-lsp',
            ];
        }

        return null;
    }

    protected function validateStaticCall(Document $document, string $rawClass, string $method, int $argCount, array $range): ?array
    {
        $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
        $targetClass = null;

        if (isset($importedUses[$rawClass])) {
            $targetClass = $importedUses[$rawClass]['class'];
        } elseif (FacadeMap::isFacadeOrAlias($rawClass)) {
            $targetClass = FacadeMap::resolve($rawClass);
        } else {
            $targetClass = '\\' . ltrim($rawClass, '\\');
        }

        $cleanClass = ltrim($targetClass, '\\');

        try {
            if (class_exists($cleanClass) || interface_exists($cleanClass)) {
                $ref = new ReflectionClass($cleanClass);
                if ($ref->hasMethod($method)) {
                    $m = $ref->getMethod($method);
                    $numReq = $m->getNumberOfRequiredParameters();
                    $numMax = $m->getNumberOfParameters();
                    $isVariadic = $m->isVariadic();
                    $sig = $this->formatMethodSignature($m, $cleanClass);

                    if ($argCount < $numReq) {
                        return [
                            'range'    => $range,
                            'severity' => 1, // Error
                            'message'  => "Too few arguments to method {$cleanClass}::{$method}(), {$argCount} passed and at least {$numReq} expected.\n\nSignature: {$sig}",
                            'source'   => 'laravel-lsp',
                        ];
                    }

                    if (!$isVariadic && $argCount > $numMax) {
                        return [
                            'range'    => $range,
                            'severity' => 1, // Error
                            'message'  => "Too many arguments to method {$cleanClass}::{$method}(), {$argCount} passed and at most {$numMax} expected.\n\nSignature: {$sig}",
                            'source'   => 'laravel-lsp',
                        ];
                    }
                }
            }

            // If Facade proxy
            if (FacadeMap::isFacadeOrAlias($rawClass)) {
                $accessor = FacadeMap::resolveAccessor($rawClass);
                if ($accessor && class_exists(ltrim($accessor, '\\'))) {
                    $refAcc = new ReflectionClass(ltrim($accessor, '\\'));
                    if ($refAcc->hasMethod($method)) {
                        $m = $refAcc->getMethod($method);
                        $numReq = $m->getNumberOfRequiredParameters();
                        $numMax = $m->getNumberOfParameters();
                        $isVariadic = $m->isVariadic();
                        $sig = $this->formatMethodSignature($m, $rawClass);

                        if ($argCount < $numReq) {
                            return [
                                'range'    => $range,
                                'severity' => 1, // Error
                                'message'  => "Too few arguments to method {$rawClass}::{$method}(), {$argCount} passed and at least {$numReq} expected.\n\nSignature: {$sig}",
                                'source'   => 'laravel-lsp',
                            ];
                        }
                    }
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    protected function validateInstanceMethodCall(string $targetType, string $method, int $argCount, array $range): ?array
    {
        $cleanClass = ltrim(preg_replace('/\|null|\?/', '', $targetType), '\\');

        try {
            if (class_exists($cleanClass) || interface_exists($cleanClass)) {
                $ref = new ReflectionClass($cleanClass);
                if ($ref->hasMethod($method)) {
                    $m = $ref->getMethod($method);
                    $numReq = $m->getNumberOfRequiredParameters();
                    $numMax = $m->getNumberOfParameters();
                    $isVariadic = $m->isVariadic();
                    $sig = $this->formatMethodSignature($m, $cleanClass);

                    if ($argCount < $numReq) {
                        return [
                            'range'    => $range,
                            'severity' => 1, // Error
                            'message'  => "Too few arguments to method {$cleanClass}::{$method}(), {$argCount} passed and at least {$numReq} expected.\n\nSignature: {$sig}",
                            'source'   => 'laravel-lsp',
                        ];
                    }

                    if (!$isVariadic && $argCount > $numMax) {
                        return [
                            'range'    => $range,
                            'severity' => 1, // Error
                            'message'  => "Too many arguments to method {$cleanClass}::{$method}(), {$argCount} passed and at most {$numMax} expected.\n\nSignature: {$sig}",
                            'source'   => 'laravel-lsp',
                        ];
                    }
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Validate component tags for missing required props.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function validateComponentTags(Document $document): array
    {
        $diagnostics = [];
        $lines = explode("\n", $document->content);

        try {
            $doc = BladeDocument::fromText($document->content);
            foreach ($doc->getComponents() as $comp) {
                $tagName = $comp->name;
                if (!$tagName) {
                    continue;
                }

                $componentSymbol = $this->componentRegistry->getComponent($tagName);
                if ($componentSymbol === null) {
                    continue;
                }

                $pos = $comp->position;
                $startLine = $pos ? max(0, $pos->startLine - 1) : 0;
                $startCol = $pos ? max(0, $pos->startColumn - 1) : 0;
                $endCol = $startCol + strlen("<x-{$tagName}");

                $tagAttrs = [];
                if ($comp->parameters) {
                    foreach ($comp->parameters as $param) {
                        $pName = (string) ($param->name ?? '');
                        $tagAttrs[$pName] = true;
                        $tagAttrs[ltrim($pName, ':')] = true;
                    }
                }

                // Check required props
                foreach ($componentSymbol->props as $prop) {
                    if ($prop->required && !$prop->hasDefault) {
                        $pName = $prop->name;
                        if (!isset($tagAttrs[$pName]) && !isset($tagAttrs[":{$pName}"])) {
                            $diagnostics[] = [
                                'range' => [
                                    'start' => ['line' => $startLine, 'character' => $startCol],
                                    'end'   => ['line' => $startLine, 'character' => $endCol],
                                ],
                                'severity' => 1, // Error
                                'message'  => "Missing required prop ':{$pName}' on component <x-{$tagName}>.\n\nProp definition: @props(['{$pName}'])",
                                'source'   => 'laravel-lsp',
                            ];
                        }
                    }
                }
            }
        } catch (Throwable) {
        }

        return $diagnostics;
    }

    /**
     * @return array{0: int, 1: ?int, 2: bool} [minReq, maxParams, isVariadic]
     */
    protected function parseSignatureParamCounts(string $signature, string $funcName): array
    {
        if (function_exists($funcName)) {
            try {
                $ref = new ReflectionFunction($funcName);

                return [
                    $ref->getNumberOfRequiredParameters(),
                    $ref->getNumberOfParameters(),
                    $ref->isVariadic(),
                ];
            } catch (Throwable) {
            }
        }

        if (preg_match('/\(([^)]*)\)/', $signature, $m)) {
            $paramStr = trim($m[1]);
            if ($paramStr === '') {
                return [0, 0, false];
            }

            $params = explode(',', $paramStr);
            $minReq = 0;
            $maxParams = count($params);
            $isVariadic = str_contains($paramStr, '...');

            foreach ($params as $p) {
                $trimmed = trim($p);
                if ($trimmed !== '' && !str_contains($trimmed, '=') && !str_starts_with($trimmed, '?') && !str_contains($trimmed, '...')) {
                    $minReq++;
                }
            }

            return [$minReq, $isVariadic ? null : $maxParams, $isVariadic];
        }

        return [0, null, false];
    }

    protected function formatMethodSignature(ReflectionMethod $method, string $className): string
    {
        $params = [];
        foreach ($method->getParameters() as $p) {
            $pType = $p->getType() ? (string) $p->getType() . ' ' : '';
            $pStr = "{$pType}\${$p->getName()}";
            if ($p->isDefaultValueAvailable()) {
                $default = $p->getDefaultValue();
                $pStr .= ' = ' . (is_array($default) ? '[]' : (is_null($default) ? 'null' : (is_bool($default) ? ($default ? 'true' : 'false') : (is_string($default) ? "'{$default}'" : (string) $default))));
            }
            $params[] = $pStr;
        }

        $ret = $method->hasReturnType() ? ': ' . (string) $method->getReturnType() : ': mixed';

        return 'public ' . ($method->isStatic() ? 'static ' : '') . "function {$method->getName()}(" . implode(', ', $params) . "){$ret}";
    }

    protected function validateVariable(Document $document, string $name, array $expr, array $range, string $viewKey): ?array
    {
        if (!$this->project->boolean('bladeUndefinedVariableDiagnostics', true)) {
            return null;
        }

        // 1. Skip guarded variables (isset, empty, null-coalesce left side)
        if (!empty($expr['isGuarded'])) {
            return null;
        }

        // 2. Skip assignment targets ($var = ..., catch ($e), foreach target)
        if (!empty($expr['isAssignment'])) {
            return null;
        }

        // 3. Skip closure & arrow function parameter usages inside closure body
        if (!empty($expr['isClosureParam'])) {
            return null;
        }

        // 4. Skip PHP superglobals and $this
        if (in_array($name, ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'this'], true)) {
            return null;
        }

        // 5. Resolve Scope at the exact variable position
        $startLine = (int) $range['start']['line'];
        $startCol = (int) $range['start']['character'];
        $scope = $this->scopeResolver->resolveAtPosition($document, $startLine, $startCol, $viewKey);

        // 6. Check if scope contains the variable
        if ($scope->hasVariable($name)) {
            return null;
        }

        // 7. Allow component globals in component views
        if ($this->scopeResolver->isComponentView($document->uri, $viewKey, $document->content)) {
            if (in_array($name, ['attributes', 'slot', '__data', 'component'], true)) {
                return null;
            }
        }

        // 8. Return LSP Error diagnostic (Severity 1 = Red Squiggly)
        return [
            'range'    => $range,
            'severity' => 1,
            'source'   => 'blade-variable',
            'message'  => "Undefined variable: \${$name}",
        ];
    }

    protected function resolveChainedType(string $rootType, string $chain): string
    {

        if ($chain === '') {
            return $rootType;
        }

        $currentType = $rootType;
        if (preg_match_all('/(?:->|\?->|\[\s*[\'"]?)([a-zA-Z0-9_]+)(?:[\'"]?\s*\]|\([^\)]*\))?/', $chain, $m)) {
            foreach ($m[1] as $member) {
                $details = $this->hoverHelper->resolveMemberDetails($currentType, $member, false, 'item');
                if ($details && !empty($details['type'])) {
                    $currentType = $details['type'];
                } else {
                    break;
                }
            }
        }

        return $currentType;
    }

    protected function resolveViewKey(string $uri): string
    {
        $path = str_replace('\\', '/', $uri);
        if (preg_match('/resources\/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return basename($path, '.blade.php');
    }

    protected function ensureAutoloaderRegistered(): void
    {
        if ($this->autoloaderRegistered) {
            return;
        }

        $basePath = rtrim($this->project->path(), '/\\');
        $autoloadPath = "{$basePath}/vendor/autoload.php";
        if (file_exists($autoloadPath)) {
            try {
                @include_once $autoloadPath;
                $this->autoloaderRegistered = true;
            } catch (Throwable) {
            }
        }
    }
}
