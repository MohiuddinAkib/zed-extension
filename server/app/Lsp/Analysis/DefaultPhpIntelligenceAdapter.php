<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Contracts\PhpIntelligenceAdapter;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\Facades\FacadeMap;
use App\Lsp\Project;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VirtualDocument;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use Illuminate\Container\Container;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

class DefaultPhpIntelligenceAdapter implements PhpIntelligenceAdapter
{
    protected Parser $phpParser;

    protected NodeFinder $nodeFinder;

    protected DocBlockParser $docBlockParser;

    protected BladePhpAstAnalyzer $bladePhpAstAnalyzer;

    protected SemanticIndex $semanticIndex;

    protected BladeMemberHoverProvider $hoverHelper;

    protected FunctionTypeResolver $functionTypeResolver;

    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
        ?SemanticIndex $semanticIndex = null,
        ?FunctionTypeResolver $functionTypeResolver = null,
    ) {
        $this->phpParser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder;
        $this->docBlockParser = new DocBlockParser;
        $this->bladePhpAstAnalyzer = new BladePhpAstAnalyzer;
        $this->semanticIndex = $semanticIndex ?? $this->resolveSemanticIndex();
        $this->hoverHelper = new BladeMemberHoverProvider($this->project, $this->semanticIndex);
        $this->functionTypeResolver = $functionTypeResolver ?? new FunctionTypeResolver($this->project, semanticIndex: $this->semanticIndex);
    }

    protected function resolveSemanticIndex(): SemanticIndex
    {
        $container = Container::getInstance();

        if ($container->bound(SemanticIndex::class)) {
            return $container->make(SemanticIndex::class);
        }

        return new SemanticIndex($this->project);
    }

    public function completion(VirtualDocument $document, array $position): array
    {
        $vLine = $position['line'] ?? 0;
        $vChar = $position['character'] ?? 0;

        $lines = explode("\n", $document->phpCode);
        $lineText = $lines[$vLine] ?? '';
        $textBeforeCursor = Utf16Position::substr($lineText, 0, $vChar);

        $memberPrefix = '';
        $targetType = null;
        $isArrayAccess = false;

        // 1. Try AST-based expression resolution
        if (preg_match('/([a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]*)$/', $textBeforeCursor, $m)) {
            $classOrAlias = $m[1];
            $memberPrefix = $m[2];
            $targetClass = FacadeMap::resolve($classOrAlias);
            $accessorClass = FacadeMap::resolveAccessor($classOrAlias);
            if (!$targetClass) {
                $targetClass = '\\' . ltrim($classOrAlias, '\\');
            }
            $members = $this->resolveMembersForType($targetClass);
            if ($accessorClass && $accessorClass !== $targetClass) {
                $members = array_merge($members, $this->resolveMembersForType($accessorClass));
            }
            $completions = [];
            foreach ($members as $mItem) {
                $name = $mItem['name'];
                if ($memberPrefix !== '' && !str_starts_with(strtolower($name), strtolower($memberPrefix))) {
                    continue;
                }
                $completions[] = [
                    'label'         => $name,
                    'kind'          => $mItem['kind'],
                    'detail'        => $mItem['detail'] ?? '',
                    'documentation' => [
                        'kind'  => 'markdown',
                        'value' => $mItem['documentation'] ?? '',
                    ],
                    'insertText' => $name,
                ];
            }

            return $completions;
        } elseif (preg_match('/(?:->|\?->)([a-zA-Z0-9_]*)$/', $textBeforeCursor, $m)) {
            $memberPrefix = $m[1];
            $leftText = substr($textBeforeCursor, 0, strlen($textBeforeCursor) - strlen($m[0]));
            $errorHandler = new Collecting;
            $stmts = $this->phpParser->parse('<?php ' . trim($leftText) . ';', $errorHandler);
            if ($stmts) {
                $lastNode = end($stmts);
                if ($lastNode instanceof Node\Stmt\Expression) {
                    $targetType = $this->resolveNodeType($lastNode->expr, $document);
                }
            }
        } elseif (preg_match('/\[([\'"]?)([a-zA-Z0-9_]*)$/', $textBeforeCursor, $m)) {
            $isArrayAccess = true;
            $memberPrefix = $m[2];
            $leftText = substr($textBeforeCursor, 0, strlen($textBeforeCursor) - strlen($m[0]));
            $errorHandler = new Collecting;
            $stmts = $this->phpParser->parse('<?php ' . trim($leftText) . ';', $errorHandler);
            if ($stmts) {
                $lastNode = end($stmts);
                if ($lastNode instanceof Node\Stmt\Expression) {
                    $targetType = $this->resolveNodeType($lastNode->expr, $document);
                }
            }
        }

        // 2. Fallback to regex resolution if AST was incomplete
        if (!$targetType) {
            if (preg_match('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $textBeforeCursor, $matches)) {
                $varName = $matches[1];
                $chain = $matches[2];
                $memberPrefix = $matches[3];

                $varType = 'mixed';
                if ($document->scope && isset($document->scope->variables[$varName])) {
                    $varType = $document->scope->variables[$varName]->type->displayName;
                } elseif (preg_match('/@var\s+([^\s]+)\s+\$' . preg_quote($varName, '/') . '/', $document->phpCode, $docM)) {
                    $varType = $docM[1];
                }

                if ($varType !== 'mixed') {
                    $targetType = $this->resolveChainedType($varType, $chain);
                }
            } elseif (preg_match('/(?:app|resolve)\s*\(\s*([\'"][a-zA-Z0-9_.\/\\\\-]+[\'"]|[a-zA-Z0-9_\\\\]+::class)\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $textBeforeCursor, $matches)) {
                $rawBinding = $matches[1];
                $chain = $matches[2];
                $memberPrefix = $matches[3];
                $bindingKey = str_ends_with($rawBinding, '::class') ? substr($rawBinding, 0, -7) : trim($rawBinding, '\'"');
                $rootType = $this->semanticIndex->containerBindingType($bindingKey);
                if ($rootType) {
                    $targetType = $this->resolveChainedType($rootType, $chain);
                }
            }
        }

        if (!$targetType || $targetType === 'mixed') {
            return [];
        }

        $this->ensureAutoloaderRegistered();
        $members = $this->resolveMembersForType($targetType);

        $completions = [];
        foreach ($members as $m) {
            $name = $m['name'];
            if ($memberPrefix !== '' && !str_starts_with(strtolower($name), strtolower($memberPrefix))) {
                continue;
            }

            $completions[] = [
                'label'         => $name,
                'kind'          => $m['kind'],
                'detail'        => $m['detail'] ?? '',
                'documentation' => [
                    'kind'  => 'markdown',
                    'value' => $m['documentation'] ?? '',
                ],
                'insertText' => $name,
            ];
        }

        return $completions;
    }

    public function resolveNodeType(Node $node, VirtualDocument $document): ?string
    {
        if ($node instanceof Variable && is_string($node->name)) {
            $varName = $node->name;
            if ($document->scope && isset($document->scope->variables[$varName])) {
                return $document->scope->variables[$varName]->type->displayName;
            }
            if (preg_match('/@var\s+([^\s]+)\s+\$' . preg_quote($varName, '/') . '/', $document->phpCode, $docM)) {
                return $docM[1];
            }
            if ($attrType = $this->resolveAttributeDecoratedVariableType($varName, $document->phpCode)) {
                return $attrType;
            }

            return null;
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $fnName = $node->name->toString();
            $arguments = [];
            foreach ($node->args as $arg) {
                $arguments[] = $this->nodeSource($document->phpCode, $arg->value) ?? '$dynamic';
            }

            return $this->functionTypeResolver->resolveCall($fnName, $arguments, $document);
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            $methodName = $node->name instanceof Identifier ? $node->name->name : '';
            $targetClass = FacadeMap::resolve($className)
                ?? FacadeMap::resolveAccessor($className)
                ?? ('\\' . ltrim($className, '\\'));

            if ($targetClass === '\\Illuminate\\Support\\Js' && $methodName === 'from') {
                return '\\Illuminate\\Support\\Js';
            }
            if ($targetClass === '\\Illuminate\\Support\\Str' && in_array($methodName, ['of', 'string'], true)) {
                return '\\Illuminate\\Support\\Stringable';
            }
            if (in_array($targetClass, ['\\Illuminate\\Support\\Facades\\Auth', '\\Illuminate\\Auth\\AuthManager'], true) && $methodName === 'user') {
                return '\\App\\Models\\User';
            }

            $details = $this->hoverHelper->resolveMemberDetails($targetClass, $methodName, false, $className);

            return $details['type'] ?? $targetClass;
        }

        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            return $node->class->toString();
        }

        if ($node instanceof PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) {
            $parentType = $this->resolveNodeType($node->var, $document);
            if ($parentType) {
                $propName = $node->name instanceof Identifier ? $node->name->name : '';
                $details = $this->hoverHelper->resolveMemberDetails($parentType, $propName, false, 'item');

                return $details['type'] ?? null;
            }

            return null;
        }

        if ($node instanceof MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $parentType = $this->resolveNodeType($node->var, $document);
            if ($parentType) {
                $methodName = $node->name instanceof Identifier ? $node->name->name : '';
                if (in_array($methodName, ['first', 'sole', 'last'], true)) {
                    return $this->docBlockParser->unwrapItemType($parentType) ?? $parentType;
                }
                if (in_array($methodName, ['where', 'filter', 'reject', 'sortBy', 'sortByDesc', 'take', 'skip', 'limit'], true)) {
                    return $parentType;
                }
                if ($methodName === 'get' || $methodName === 'all') {
                    $itemType = $this->docBlockParser->unwrapItemType($parentType) ?? $parentType;

                    return "\\Illuminate\\Support\\Collection<int, {$itemType}>";
                }
                if (str_contains($parentType, 'Fluent')) {
                    $arguments = [];
                    foreach ($node->args as $arg) {
                        $arguments[] = $this->nodeSource($document->phpCode, $arg->value) ?? '$dynamic';
                    }
                    $resolver = new DataPathResolver($this->project, functionTypeResolver: $this->functionTypeResolver);
                    $type = $resolver->inferFluentMethodReturnType(
                        TypeRef::fromString($parentType),
                        $methodName,
                        $arguments,
                        new \App\Lsp\Document($document->virtualUri(), $document->phpCode),
                    );

                    if ((string) $type !== 'mixed') {
                        return $type->displayName;
                    }
                }
                $details = $this->hoverHelper->resolveMemberDetails($parentType, $methodName, false, 'item');

                return $details['type'] ?? null;
            }

            return null;
        }

        if ($node instanceof Node\Expr\ArrayDimFetch) {
            $parentType = $this->resolveNodeType($node->var, $document);
            if ($parentType) {
                if ($node->dim instanceof Node\Scalar\String_) {
                    $key = $node->dim->value;
                    $shapeType = TypeRef::fromString($parentType);
                    if ($shapeType->isShape() && ($member = $shapeType->getShapeMember($key))) {
                        return $member->displayName;
                    }
                    $details = $this->hoverHelper->resolveMemberDetails($parentType, $key, true, 'item');

                    return $details['type'] ?? null;
                }

                return $this->docBlockParser->unwrapItemType($parentType);
            }

            return null;
        }

        return null;
    }

    protected function resolveAttributeDecoratedVariableType(string $varName, string $phpCode): ?string
    {
        if (!preg_match('/#\[\s*\\\\?([a-zA-Z0-9_\\\\]+)\s*(?:\(([^)]*)\))?\s*\]\s*(?:(?:public|protected|private|readonly|\s)+\s+)?(?:([a-zA-Z0-9_\\\\]+)\s+)?\$' . preg_quote($varName, '/') . '\b/', $phpCode, $attrM)) {
            return null;
        }

        $attrName = $attrM[1];
        $attrArg = !empty($attrM[2]) ? trim($attrM[2], '\'"') : null;
        $typeHint = !empty($attrM[3]) ? $attrM[3] : null;

        $attrRegistry = new AttributeIntelligenceRegistry($this->project);
        $injectedType = $attrRegistry->resolveInjectedType($attrName, $attrArg);
        if ($injectedType && $injectedType !== 'mixed') {
            return $injectedType;
        }

        return $typeHint;
    }

    protected function nodeSource(string $code, Node $node): ?string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return null;
        }

        return substr($code, $start, $end - $start + 1);
    }

    public function hover(VirtualDocument $document, array $position): ?array
    {
        $vLine = $position['line'] ?? 0;
        $vChar = $position['character'] ?? 0;

        $astExpr = $this->bladePhpAstAnalyzer->findExpressionAtPosition($document->phpCode, $vLine, $vChar);
        if ($astExpr === null || $astExpr['kind'] === 'variable') {
            return null;
        }

        $varName = $astExpr['rootVar'] ?? '';
        $rootCall = $astExpr['rootCall'] ?? null;
        $rootCallArg = $astExpr['rootCallArg'] ?? null;
        $chain = $astExpr['chain'];
        $memberName = $astExpr['name'];
        $isArrayAccess = $astExpr['isArrayAccess'];

        $rootType = 'mixed';
        $accessorType = null;
        if ($rootCall === 'class') {
            $rootClass = $rootCallArg ?: $varName;
            $rootType = FacadeMap::resolve($rootClass)
                ?? FacadeMap::resolveAccessor($rootClass)
                ?? ('\\' . ltrim($rootClass, '\\'));
            $accessorType = FacadeMap::resolveAccessor($rootClass);
        } elseif ($rootCall !== null) {
            $argCount = $astExpr['rootCallArgCount'] ?? (trim($rootCallArg ?? '') === '' ? 0 : 1);
            $rootType = $this->functionTypeResolver->resolve($rootCall, $rootCallArg, $document, $argCount);
        } elseif ($varName !== '' && $document->scope && isset($document->scope->variables[$varName])) {

            $rootType = $document->scope->variables[$varName]->type->displayName;
        } elseif ($varName !== '' && preg_match('/@var\s+([^\s]+)\s+\$' . preg_quote($varName, '/') . '/', $document->phpCode, $docM)) {
            $rootType = $docM[1];
        }

        if (!$rootType || $rootType === 'mixed') {
            return null;
        }

        $targetType = $this->resolveChainedType($rootType, $chain);
        if (!$targetType || $targetType === 'mixed') {
            return null;
        }

        $this->ensureAutoloaderRegistered();

        $memberData = $this->hoverHelper->resolveMemberDetails($targetType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), null, null);
        if ($memberData === null && $accessorType !== null && $accessorType !== $targetType) {
            $memberData = $this->hoverHelper->resolveMemberDetails($accessorType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), null, null);
        }

        if ($memberData === null) {
            return null;
        }

        $title = $memberData['title'];
        $type = $memberData['type'];
        $origin = $memberData['origin'];
        $isMethod = $memberData['isMethod'] ?? false;
        $signature = $memberData['signature'] ?? '';

        $phpCode = "<?php\n";
        if ($isMethod) {
            $phpCode .= "public function {$memberName}{$signature};";
        } elseif ($isArrayAccess) {
            $phpCode .= "{$type} \${$memberName};";
        } else {
            $phpCode .= "public {$type} \${$memberName};";
        }

        $markdown = "**{$title}**\n\n"
            . "```php\n{$phpCode}\n```\n\n"
            . "*Origin:* `{$origin}`\n";

        // Map virtual range back to Blade range
        $virtualRange = [
            'start' => ['line' => $astExpr['startLine'], 'character' => $astExpr['startCol']],
            'end'   => ['line' => $astExpr['startLine'], 'character' => $astExpr['endCol']],
        ];
        $bladeRange = $document->sourceMap->virtualRangeToBlade($virtualRange);

        return [
            'contents' => [
                'kind'  => 'markdown',
                'value' => $markdown,
            ],
            'range' => $bladeRange,
        ];
    }

    public function definition(VirtualDocument $document, array $position): array
    {
        $vLine = $position['line'] ?? 0;
        $vChar = $position['character'] ?? 0;

        $astExpr = $this->bladePhpAstAnalyzer->findExpressionAtPosition($document->phpCode, $vLine, $vChar);
        if ($astExpr === null || $astExpr['kind'] === 'variable') {
            return [];
        }

        $varName = $astExpr['rootVar'] ?? '';
        $rootCall = $astExpr['rootCall'] ?? null;
        $rootCallArg = $astExpr['rootCallArg'] ?? null;
        $chain = $astExpr['chain'];
        $memberName = $astExpr['name'];
        $isArrayAccess = $astExpr['isArrayAccess'];

        $rootType = 'mixed';
        $accessorType = null;
        if ($rootCall === 'class') {
            $rootClass = $rootCallArg ?: $varName;
            $rootType = FacadeMap::resolve($rootClass)
                ?? FacadeMap::resolveAccessor($rootClass)
                ?? ('\\' . ltrim($rootClass, '\\'));
            $accessorType = FacadeMap::resolveAccessor($rootClass);
        } elseif ($rootCall !== null) {
            $argCount = $astExpr['rootCallArgCount'] ?? (trim($rootCallArg ?? '') === '' ? 0 : 1);
            $rootType = $this->functionTypeResolver->resolve($rootCall, $rootCallArg, $document, $argCount);
        } elseif ($varName !== '' && $document->scope && isset($document->scope->variables[$varName])) {

            $rootType = $document->scope->variables[$varName]->type->displayName;
        }

        if (!$rootType || $rootType === 'mixed') {
            return [];
        }

        $targetType = $this->resolveChainedType($rootType, $chain);
        if (!$targetType || $targetType === 'mixed') {
            return [];
        }

        $memberData = $this->hoverHelper->resolveMemberDetails($targetType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), null, null);
        if ($memberData === null && $accessorType !== null && $accessorType !== $targetType) {
            $memberData = $this->hoverHelper->resolveMemberDetails($accessorType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), null, null);
        }
        if (!$memberData || empty($memberData['source'])) {
            return [];
        }

        $source = $memberData['source'];
        $targetLine = (int) ($memberData['line'] ?? 1);
        $basePath = rtrim($this->project->path(), '/\\');
        $absPath = str_starts_with($source, '/') ? $source : "{$basePath}/{$source}";

        if (!file_exists($absPath)) {
            return [];
        }

        $targetUri = FileUri::fromPath($absPath);

        $virtualRange = [
            'start' => ['line' => $astExpr['startLine'], 'character' => $astExpr['startCol']],
            'end'   => ['line' => $astExpr['startLine'], 'character' => $astExpr['endCol']],
        ];
        $bladeRange = $document->sourceMap->virtualRangeToBlade($virtualRange);

        return [
            [
                'uri'   => (string) $targetUri,
                'range' => [
                    'start' => ['line' => max(0, $targetLine - 1), 'character' => 0],
                    'end'   => ['line' => max(0, $targetLine - 1), 'character' => 0],
                ],
                'originSelectionRange' => $bladeRange,
            ],
        ];
    }

    public function signatureHelp(VirtualDocument $document, array $position): ?array
    {
        $vLine = $position['line'] ?? 0;
        $vChar = $position['character'] ?? 0;

        $lines = explode("\n", $document->phpCode);
        $lineText = $lines[$vLine] ?? '';
        $textBeforeCursor = Utf16Position::substr($lineText, 0, $vChar);

        if (preg_match('/(?:(\$[a-zA-Z0-9_]+|[a-zA-Z0-9_\\\\]+::[a-zA-Z0-9_]+|app\([^)]*\)|[a-zA-Z0-9_]+)->)?([a-zA-Z0-9_]+)\s*\(([^)]*)$/', $textBeforeCursor, $m)) {
            $caller = $m[1] ?? '';
            $methodName = $m[2];
            $argsText = $m[3];

            $activeParam = 0;
            $depth = 0;
            $quote = null;
            for ($i = 0; $i < strlen($argsText); $i++) {
                $ch = $argsText[$i];
                if ($quote !== null) {
                    if ($ch === $quote && ($i === 0 || $argsText[$i - 1] !== '\\')) {
                        $quote = null;
                    }

                    continue;
                }
                if ($ch === "'" || $ch === '"') {
                    $quote = $ch;

                    continue;
                }
                if ($ch === '(' || $ch === '[' || $ch === '{') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                    $depth = max(0, $depth - 1);
                } elseif ($ch === ',' && $depth === 0) {
                    $activeParam++;
                }
            }

            $targetType = null;
            if ($caller !== '') {
                $targetType = $this->resolveChainedType($caller, '');
                if (!$targetType && isset($document->scope->variables[ltrim($caller, '$')])) {
                    $targetType = $document->scope->variables[ltrim($caller, '$')]->type->displayName;
                }
            }

            if ($targetType) {
                $this->ensureAutoloaderRegistered();
                $cleanType = ltrim(preg_replace('/\|null|\?/', '', $targetType), '\\');
                if (class_exists($cleanType) || interface_exists($cleanType)) {
                    try {
                        $ref = new ReflectionClass($cleanType);
                        if ($ref->hasMethod($methodName)) {
                            $method = $ref->getMethod($methodName);
                            $params = [];
                            $paramLabels = [];
                            foreach ($method->getParameters() as $p) {
                                $pType = $p->getType() ? (string) $p->getType() . ' ' : '';
                                $pStr = "{$pType}\${$p->getName()}";
                                if ($p->isDefaultValueAvailable()) {
                                    $pStr .= ' = ' . json_encode($p->getDefaultValue());
                                }
                                $params[] = [
                                    'label' => $pStr,
                                ];
                                $paramLabels[] = $pStr;
                            }
                            $retType = $method->hasReturnType() ? ': ' . (string) $method->getReturnType() : '';
                            $label = "public function {$methodName}(" . implode(', ', $paramLabels) . "){$retType}";

                            return [
                                'signatures' => [
                                    [
                                        'label'         => $label,
                                        'documentation' => [
                                            'kind'  => 'markdown',
                                            'value' => (string) ($method->getDocComment() ?: ''),
                                        ],
                                        'parameters' => $params,
                                    ],
                                ],
                                'activeSignature' => 0,
                                'activeParameter' => $activeParam,
                            ];
                        }
                    } catch (Throwable) {
                    }
                }
            }
        }

        return null;
    }

    public function diagnostics(VirtualDocument $document): array
    {
        $errorHandler = new Collecting;
        $this->phpParser->parse($document->phpCode, $errorHandler);

        $diagnostics = [];
        foreach ($errorHandler->getErrors() as $error) {
            $vLine = max(0, $error->getStartLine() - 1);
            $vCol = max(0, $error->getStartColumn($document->phpCode) - 1);

            $bladePos = $document->sourceMap->virtualToBladePosition($vLine, $vCol);
            if ($bladePos !== null) {
                $diagnostics[] = [
                    'range' => [
                        'start' => ['line' => $bladePos['line'], 'character' => $bladePos['character']],
                        'end'   => ['line' => $bladePos['line'], 'character' => $bladePos['character'] + 1],
                    ],
                    'severity' => 1, // Error
                    'message'  => $error->getRawMessage(),
                    'source'   => 'Blade PHP',
                ];
            }
        }

        return $diagnostics;
    }

    protected function resolveChainedType(string $rootType, string $chain): string
    {
        if ($chain === '') {
            return $rootType;
        }

        $currentType = $rootType;
        if (preg_match_all('/(?:->|\?->|\[\s*[\'"]?)([a-zA-Z0-9_]+)(?:[\'"]?\s*\]|\([^\)]*\))?/', $chain, $m)) {
            foreach ($m[1] as $member) {
                if (str_contains($currentType, 'Fluent')) {
                    if ($member === 'string') {
                        $currentType = '\\Illuminate\\Support\\Stringable';
                        continue;
                    }
                    if ($member === 'collection') {
                        $currentType = '\\Illuminate\\Support\\Collection';
                        continue;
                    }
                    if ($member === 'date') {
                        $currentType = '\\Illuminate\\Support\\Carbon';
                        continue;
                    }
                    if ($member === 'array' || $member === 'only' || $member === 'except' || $member === 'toArray' || $member === 'jsonSerialize') {
                        $currentType = 'array';
                        continue;
                    }
                    if ($member === 'integer') {
                        $currentType = 'int';
                        continue;
                    }
                    if ($member === 'boolean' || $member === 'has') {
                        $currentType = 'bool';
                        continue;
                    }
                    if ($member === 'toJson') {
                        $currentType = 'string';
                        continue;
                    }
                    if ($member === 'set') {
                        continue;
                    }
                    if ($member === 'object') {
                        $currentType = 'object';
                        continue;
                    }
                }

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

    /**
     * @return array<string, array{name: string, kind: int, detail: string, documentation: string}>
     */
    protected function resolveMembersForType(string $type): array
    {
        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');
        $baseClass = preg_replace('/<.*>$/', '', $cleanType);
        $baseClass = ltrim(preg_replace('/\[\]$/', '', $baseClass), '\\');
        $members = [];

        if (!class_exists($baseClass) && !interface_exists($baseClass) && !enum_exists($baseClass)) {
            return $members;
        }

        try {
            $reflection = new ReflectionClass($baseClass);

            $classesToSearch = [$reflection];
            $seenClasses = [$baseClass => true];

            $curr = $reflection;
            while ($curr) {
                if ($docComment = $curr->getDocComment()) {
                    foreach ($this->docBlockParser->extractMixins($docComment) as $mixinClass) {
                        if (!isset($seenClasses[$mixinClass]) && (class_exists($mixinClass) || interface_exists($mixinClass))) {
                            $seenClasses[$mixinClass] = true;
                            $classesToSearch[] = new ReflectionClass($mixinClass);
                        }
                    }
                }
                $parent = $curr->getParentClass();
                if ($parent && !isset($seenClasses[$parent->getName()])) {
                    $seenClasses[$parent->getName()] = true;
                    $classesToSearch[] = $parent;
                }
                $curr = $parent;
            }

            foreach ($classesToSearch as $targetRef) {
                // Public Properties
                foreach ($targetRef->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                    $pName = $prop->getName();
                    if (!isset($members[$pName])) {
                        $pType = $prop->getType() ? (string) $prop->getType() : 'mixed';
                        $members[$pName] = [
                            'name'          => $pName,
                            'kind'          => 10, // Property
                            'detail'        => $pType,
                            'documentation' => "```php\npublic {$pType} \${$pName};\n```",
                        ];
                    }
                }

                // Public Methods
                foreach ($targetRef->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $mName = $method->getName();
                    if (str_starts_with($mName, '__') && $mName !== '__toString') {
                        continue;
                    }

                    if (!isset($members[$mName])) {
                        $params = [];
                        foreach ($method->getParameters() as $param) {
                            $paramStr = '';
                            if ($param->hasType()) {
                                $paramStr .= (string) $param->getType() . ' ';
                            }
                            $paramStr .= '$' . $param->getName();
                            $params[] = $paramStr;
                        }
                        $returnType = $method->hasReturnType() ? (string) $method->getReturnType() : 'mixed';
                        $signature = '(' . implode(', ', $params) . '): ' . $returnType;

                        $members[$mName] = [
                            'name'          => $mName,
                            'kind'          => 2, // Method
                            'detail'        => $signature,
                            'documentation' => "```php\npublic function {$mName}{$signature};\n```",
                        ];
                    }
                }
            }
        } catch (Throwable) {
        }

        return $members;
    }

    protected function ensureAutoloaderRegistered(): void
    {
        if ($this->autoloaderRegistered) {
            return;
        }

        $basePath = $this->project->path();
        $autoloader = $basePath . '/vendor/autoload.php';

        if (file_exists($autoloader)) {
            try {
                require_once $autoloader;
                $this->autoloaderRegistered = true;
            } catch (Throwable) {
            }
        }
    }

    public function references(VirtualDocument $document, array $position, bool $includeDeclaration = true): array
    {
        $vLine = $position['line'] ?? 0;
        $vChar = $position['character'] ?? 0;

        $lines = explode("\n", $document->phpCode);
        $lineText = $lines[$vLine] ?? '';

        // Find token under cursor
        $token = null;
        $isVar = false;

        // Check if cursor is on variable
        if (preg_match_all('/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/', $lineText, $vMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($vMatches[0] as $idx => $vMatch) {
                $start = $vMatch[1];
                $end = $start + strlen($vMatch[0]);
                if ($vChar >= $start && $vChar <= $end) {
                    $token = $vMatches[1][$idx][0];
                    $isVar = true;
                    break;
                }
            }
        }

        // Check if cursor is on identifier/method/prop/class
        if ($token === null && preg_match_all('/(?<!\$)\b([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/', $lineText, $idMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($idMatches[0] as $idMatch) {
                $start = $idMatch[1];
                $end = $start + strlen($idMatch[0]);
                if ($vChar >= $start && $vChar <= $end) {
                    $token = $idMatch[0];
                    break;
                }
            }
        }

        if ($token === null || in_array($token, ['php', 'use', 'as', 'null', 'true', 'false', 'var'], true)) {
            return [];
        }

        $references = [];

        foreach ($lines as $lineIdx => $lineStr) {
            $pattern = $isVar
                ? '/\$' . preg_quote($token, '/') . '\b/'
                : '/\b' . preg_quote($token, '/') . '\b/';

            if (preg_match_all($pattern, $lineStr, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $charOffset = $match[1];
                    $tokenLen = strlen($match[0]);

                    if (!$includeDeclaration && (str_contains($lineStr, '/** @var') || str_contains($lineStr, "\${$token} = null;"))) {
                        continue;
                    }

                    $references[] = [
                        'uri' => $document->bladeUri,
                        'range' => [
                            'start' => ['line' => $lineIdx, 'character' => $charOffset],
                            'end'   => ['line' => $lineIdx, 'character' => $charOffset + $tokenLen],
                        ],
                    ];
                }
            }
        }

        return $references;
    }
}
