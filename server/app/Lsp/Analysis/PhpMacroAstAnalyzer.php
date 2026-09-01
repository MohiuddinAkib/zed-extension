<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Semantics\MacroParameterSymbol;
use App\Lsp\Semantics\MacroSymbol;
use App\Lsp\Semantics\TypeRef;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
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
                    && $node->name instanceof Identifier
                    && in_array($node->name->name, ['macro', 'mixin'], true);
            });

            foreach ($staticCalls as $call) {
                if (!$call instanceof StaticCall) {
                    continue;
                }

                $methodName = $call->name instanceof Identifier ? $call->name->name : (string) $call->name;
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
                    } elseif ($handlerArg instanceof Array_ && count($handlerArg->items) >= 2) {
                        $item0 = $handlerArg->items[0]?->value;
                        $item1 = $handlerArg->items[1]?->value;

                        if ($item1 instanceof String_) {
                            $callableMethodName = $item1->value;
                            $callableClass = '';

                            if ($item0 instanceof New_ && $item0->class instanceof Name) {
                                $callableClass = $this->resolveClassName($item0->class, $useMap);
                            } elseif ($item0 instanceof ClassConstFetch && $item0->class instanceof Name) {
                                $callableClass = $this->resolveClassName($item0->class, $useMap);
                            }

                            if ($callableClass !== '') {
                                $shortName = class_basename($callableClass);
                                $classNode = $classesInFile[$shortName] ?? null;

                                if ($classNode instanceof Class_) {
                                    $method = $classNode->getMethod($callableMethodName);
                                    if ($method !== null) {
                                        $innerFn = $this->nodeFinder->findFirst($method->stmts ?? [], function (Node $n): bool {
                                            return $n instanceof Closure || $n instanceof ArrowFunction;
                                        });

                                        if ($innerFn instanceof Closure || $innerFn instanceof ArrowFunction) {
                                            $params = $this->extractParametersFromFunction($innerFn, $useMap, $code);
                                            $returnType = $this->extractReturnTypeFromFunction($innerFn, $useMap);
                                        } else {
                                            $params = $this->extractParametersFromMethod($method, $useMap, $code);
                                            $returnType = $this->extractReturnTypeFromMethod($method, $useMap);
                                        }

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
                                }
                            }
                        }
                    }
                } elseif ($methodName === 'mixin' && count($args) >= 1) {
                    $mixinArg = $args[0]->value;
                    $mixinClass = '';

                    if ($mixinArg instanceof New_ && $mixinArg->class instanceof Name) {
                        $mixinClass = $this->resolveClassName($mixinArg->class, $useMap);
                    } elseif ($mixinArg instanceof ClassConstFetch && $mixinArg->class instanceof Name) {
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
            if ($classNode->isFullyQualified()) {
                return ltrim($nameStr, '\\');
            }
            if (isset($useMap[$nameStr])) {
                return $useMap[$nameStr];
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
                    } else {
                        $pDefault = $this->nodeToScalarString($p->default);
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
     * @return array<int, MacroParameterSymbol>
     */
    protected function extractParametersFromMethod(ClassMethod $method, array $useMap, string $code): array
    {
        $params = [];

        foreach ($method->params as $p) {
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
                    } else {
                        $pDefault = $this->nodeToScalarString($p->default);
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

    protected function extractReturnTypeFromMethod(ClassMethod $method, array $useMap): TypeRef
    {
        if ($method->returnType !== null) {
            return TypeRef::fromString($this->formatAstTypeNode($method->returnType, $useMap));
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
                        $params = $this->extractParametersFromMethod($method, $useMap, $code);
                        $returnType = $this->extractReturnTypeFromMethod($method, $useMap);
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
        if ($typeNode instanceof NullableType) {
            return '?' . $this->formatAstTypeNode($typeNode->type, $useMap);
        }
        if ($typeNode instanceof UnionType) {
            return implode('|', array_map(fn ($t) => $this->formatAstTypeNode($t, $useMap), $typeNode->types));
        }
        if ($typeNode instanceof IntersectionType) {
            return implode('&', array_map(fn ($t) => $this->formatAstTypeNode($t, $useMap), $typeNode->types));
        }
        if ($typeNode instanceof Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Name) {
            $nameStr = $typeNode->toString();
            if ($typeNode->isFullyQualified()) {
                return ltrim($nameStr, '\\');
            }
            if (isset($useMap[$nameStr])) {
                return $useMap[$nameStr];
            }
            return $nameStr;
        }

        return 'mixed';
    }

    protected function nodeToScalarString(Node $node): ?string
    {
        if ($node instanceof Int_) {
            return (string) $node->value;
        }
        if ($node instanceof Float_) {
            return (string) $node->value;
        }
        if ($node instanceof String_) {
            return "'{$node->value}'";
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return $node->name->toLowerString();
        }
        if ($node instanceof Array_) {
            return '[]';
        }

        return null;
    }
}
