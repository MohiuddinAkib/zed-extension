<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

class PhpAstViewAnalyzer
{
    protected Parser $parser;

    public function __construct()
    {
        $factory = new ParserFactory();
        $this->parser = method_exists($factory, 'createForHostVersion')
            ? $factory->createForHostVersion()
            : $factory->create(ParserFactory::PREFER_PHP7);
    }

    /**
     * Analyze PHP source code and extract all view calls and their passed variables.
     *
     * @param array<string, list<string>> $composerBindings
     * @param array<string, list<array<string, mixed>>> $composerClasses
     * @return array<string, array{key: string, variables: array<string, array<string, mixed>>, sources: array<int, string>}>
     */
    public function analyze(string $code, string $filePath = '', array &$composerBindings = [], array &$composerClasses = []): array
    {
        try {
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $views = [];
        $visitor = new class($views, $filePath, $composerBindings, $composerClasses) extends NodeVisitorAbstract {
            /** @var array<string, string> */
            protected array $useAliases = [];

            /** @var array<string, array<string, mixed>> */
            protected array $currentScope = [];

            /** @var array<string, array<string, mixed>> */
            protected array $currentClassProperties = [];

            protected string $currentNamespace = '';
            protected ?string $currentClass = null;
            protected ?string $currentExtends = null;
            protected ?string $currentMethod = null;

            /** @var array<string, array{viewArg: int, dataArg: int}> */
            protected array $viewStringFunctions = [];

            /** @var array<string, array<string, array{viewArg: int, dataArg: int}>> */
            protected array $viewStringMethods = [];

            public function __construct(
                public array &$views,
                public string $filePath,
                public array &$composerBindings,
                public array &$composerClasses,
            ) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                }

                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $alias = $use->getAlias()->toString();
                        $this->useAliases[$alias] = $use->name->toString();
                    }
                }

                if ($node instanceof Class_) {
                    $shortName = $node->name?->toString() ?? '';
                    $this->currentClass = $this->currentNamespace !== '' ? "{$this->currentNamespace}\\{$shortName}" : $shortName;
                    $this->currentExtends = $node->extends?->toString();
                    $this->currentClassProperties = [];

                    // 1. Extract class-level PHPDoc properties (@property, @property-read, @property-write)
                    if ($docComment = $node->getDocComment()) {
                        $docText = $docComment->getText();
                        if (preg_match_all('/@property(?:-read|-write)?\s+([\s\S]+?)\s+\$([a-zA-Z0-9_]+)/', $docText, $propMatches, PREG_SET_ORDER)) {
                            foreach ($propMatches as $pMatch) {
                                $type = $this->cleanAndQualifyDocType($pMatch[1]);
                                $propName = $pMatch[2];
                                $this->currentClassProperties[$propName] = [
                                    'name' => $propName,
                                    'type' => $type,
                                    'origin' => 'Property' . ($this->currentClass ? " ({$this->currentClass})" : ''),
                                    'line' => $node->getStartLine(),
                                    'source' => $this->filePath,
                                ];
                            }
                        }
                    }

                    // 2. Extract all public class properties
                    foreach ($node->getProperties() as $prop) {
                        if ($prop->isPublic()) {
                            $type = $this->resolveTypeNode($prop->type);
                            if ($docComment = $prop->getDocComment()) {
                                $docText = $docComment->getText();
                                if (preg_match('/@var\s+([\s\S]+?)(?:\s*\*\s*\/|\s*\$\w+|\s*$)/', $docText, $m)) {
                                    $docType = $this->cleanAndQualifyDocType($m[1]);
                                    if ($docType !== '' && $docType !== 'mixed') {
                                        $type = $docType;
                                    }
                                }
                            }

                            foreach ($prop->props as $p) {
                                $propName = $p->name->toString();
                                if (isset($this->currentClassProperties[$propName]) && ($type === 'mixed' || $type === 'string' || $type === 'array')) {
                                    $type = $this->currentClassProperties[$propName]['type'];
                                }

                                $this->currentClassProperties[$propName] = [
                                    'name' => $propName,
                                    'type' => $type,
                                    'origin' => 'Property' . ($this->currentClass ? " ({$this->currentClass})" : ''),
                                    'line' => $prop->getStartLine(),
                                    'source' => $this->filePath,
                                ];
                            }
                        }
                    }
                }

                if ($node instanceof ClassMethod || $node instanceof Function_) {
                    $this->currentMethod = $node->name->toString();
                    $this->currentScope = [];

                    $paramNames = [];
                    foreach ($node->params as $idx => $param) {
                        if ($param->var instanceof Variable && is_string($param->var->name)) {
                            $varName = $param->var->name;
                            $paramNames[$idx] = $varName;
                            $type = $this->resolveTypeNode($param->type);
                            $this->currentScope[$varName] = [
                                'name' => $varName,
                                'type' => $type,
                                'origin' => 'Parameter' . ($this->currentMethod ? " ({$this->currentMethod})" : ''),
                                'line' => $param->getStartLine(),
                                'source' => $this->filePath,
                            ];

                            // Track constructor promoted properties: public string $name
                            if ($node instanceof ClassMethod && $node->name->toString() === '__construct' && $param->isPublic()) {
                                $propType = isset($this->currentClassProperties[$varName]) && $this->currentClassProperties[$varName]['type'] !== 'mixed'
                                    ? $this->currentClassProperties[$varName]['type']
                                    : $type;

                                $this->currentClassProperties[$varName] = [
                                    'name' => $varName,
                                    'type' => $propType,
                                    'origin' => 'Constructor Property' . ($this->currentClass ? " ({$this->currentClass})" : ''),
                                    'line' => $param->getStartLine(),
                                    'source' => $this->filePath,
                                ];
                            }
                        }
                    }

                    // Check for @param view-string $view in PHPDoc
                    if ($docComment = $node->getDocComment()) {
                        $docText = $docComment->getText();

                        if (preg_match_all('/@param\s+(?:view-string|view_string)\s+\$([a-zA-Z0-9_]+)/', $docText, $viewDocMatches, PREG_SET_ORDER)) {
                            foreach ($viewDocMatches as $vMatch) {
                                $viewParamName = $vMatch[1];
                                $viewParamIdx = array_search($viewParamName, $paramNames, true);
                                if ($viewParamIdx !== false) {
                                    $dataParamIdx = $viewParamIdx + 1;
                                    if ($node instanceof Function_) {
                                        $this->viewStringFunctions[$node->name->toString()] = [
                                            'viewArg' => (int) $viewParamIdx,
                                            'dataArg' => (int) $dataParamIdx,
                                        ];
                                    } elseif ($node instanceof ClassMethod && $this->currentClass) {
                                        $this->viewStringMethods[$this->currentClass][$node->name->toString()] = [
                                            'viewArg' => (int) $viewParamIdx,
                                            'dataArg' => (int) $dataParamIdx,
                                        ];
                                    }
                                }
                            }
                        }

                        // Collect general @param and @var annotations
                        if (preg_match_all('/@(?:param|var)\s+([\s\S]+?)\s+\$([a-zA-Z0-9_]+)/', $docText, $docMatches, PREG_SET_ORDER)) {
                            foreach ($docMatches as $match) {
                                $type = $this->cleanAndQualifyDocType($match[1]);
                                $varName = $match[2];
                                $this->currentScope[$varName] = [
                                    'name' => $varName,
                                    'type' => $type,
                                    'origin' => 'PHPDoc',
                                    'line' => $node->getStartLine(),
                                    'source' => $this->filePath,
                                ];

                                if (isset($this->currentClassProperties[$varName])) {
                                    $this->currentClassProperties[$varName]['type'] = $type;
                                }
                            }
                        }
                    }
                }

                // 3. Track variable assignments in local scope
                if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                    $varName = $node->var->name;
                    $type = $this->inferTypeFromExpr($node->expr);
                    $this->currentScope[$varName] = [
                        'name' => $varName,
                        'type' => $type,
                        'origin' => 'Assignment' . ($this->currentMethod ? " in {$this->currentMethod}()" : ''),
                    ];
                }

                // 4. Match class-based composer / creator methods: compose(View $view) / create(View $view)
                if ($node instanceof ClassMethod && in_array($node->name->toString(), ['compose', 'create'], true)) {
                    if ($node->stmts !== null && $this->currentClass !== null) {
                        $methodVars = $this->extractVariablesFromMethodStmts($node->stmts);
                        if (!empty($methodVars)) {
                            $fullClass = $this->currentClass;
                            $shortClass = class_basename($fullClass);
                            $keyedMethodVars = [];
                            foreach ($methodVars as $v) {
                                $keyedMethodVars[$v['name']] = $v;
                            }
                            $this->composerClasses[$fullClass] = $keyedMethodVars;
                            $this->composerClasses[$shortClass] = $keyedMethodVars;

                            foreach ([$fullClass, $shortClass] as $cKey) {
                                if (isset($this->composerBindings[$cKey])) {
                                    foreach ($this->composerBindings[$cKey] as $tView) {
                                        $this->recordViewData($tView, $methodVars);
                                    }
                                }
                            }
                        }
                    }
                }

                // 5. Match view() function calls, View::make(), new Content(...), or custom @view-string methods
                $viewCall = $this->extractViewCall($node);
                if ($viewCall !== null) {
                    $this->recordViewData($viewCall['name'], $viewCall['variables']);
                }

                // 6. Match View::share(), View::composer(), View::creator(), view()->share(), view()->composer(), view()->creator()
                $this->extractViewShareAndComposers($node);

                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof ClassMethod || $node instanceof Function_) {
                    $this->currentScope = [];
                    $this->currentMethod = null;
                }

                if ($node instanceof Class_) {
                    $this->currentClass = null;
                    $this->currentExtends = null;
                    $this->currentClassProperties = [];
                }

                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->currentNamespace = '';
                }

                return null;
            }

            /**
             * Extract view name and variables from Call / MethodCall / StaticCall / New node.
             *
             * @return array{name: string, variables: array<int, array<string, mixed>>}|null
             */
            protected function extractViewCall(Node $node): ?array
            {
                // Pattern A: view('users.show', [...]) or view('courier::admin', [...])
                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'view') {
                    if (empty($node->args) || !isset($node->args[0])) {
                        return null;
                    }

                    $viewName = $this->extractStringValue($node->args[0]->value);
                    if ($viewName === null) {
                        return null;
                    }

                    $variables = [];
                    if (isset($node->args[1])) {
                        $variables = $this->extractVariablesFromDataArg($node->args[1]->value);
                    }

                    // If inside a Component / Mailable / Livewire, merge class public properties
                    $variables = $this->mergeWithClassProperties($variables);

                    return ['name' => $viewName, 'variables' => $variables];
                }

                // Pattern B: View::make('users.show', [...])
                if ($node instanceof StaticCall && $node->class instanceof Name && in_array($node->class->toString(), ['View', 'Illuminate\Support\Facades\View', '\Illuminate\Support\Facades\View'], true)) {
                    if ($node->name instanceof Identifier && $node->name->toString() === 'make') {
                        if (empty($node->args) || !isset($node->args[0])) {
                            return null;
                        }

                        $viewName = $this->extractStringValue($node->args[0]->value);
                        if ($viewName === null) {
                            return null;
                        }

                        $variables = [];
                        if (isset($node->args[1])) {
                            $variables = $this->extractVariablesFromDataArg($node->args[1]->value);
                        }

                        $variables = $this->mergeWithClassProperties($variables);

                        return ['name' => $viewName, 'variables' => $variables];
                    }
                }

                // Pattern C: ->with('key', $val) or ->with(['key' => $val]) chained call
                if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'with') {
                    $rootViewName = $this->findRootViewNameInChain($node->var);
                    if ($rootViewName !== null) {
                        $variables = [];
                        if (count($node->args) === 1 && isset($node->args[0])) {
                            $variables = $this->extractVariablesFromDataArg($node->args[0]->value);
                        } elseif (count($node->args) >= 2 && isset($node->args[0], $node->args[1])) {
                            $keyName = $this->extractStringValue($node->args[0]->value);
                            if ($keyName !== null) {
                                $type = $this->inferTypeFromExpr($node->args[1]->value);
                                $variables[] = [
                                    'name' => $keyName,
                                    'type' => $type,
                                    'origin' => 'with() call',
                                ];
                            }
                        }

                        $variables = $this->mergeWithClassProperties($variables);

                        return ['name' => $rootViewName, 'variables' => $variables];
                    }
                }

                // Pattern D: Laravel Mailable Content: new Content(view: 'mail.alert', markdown: 'mail.alert', with: [...])
                if ($node instanceof New_ && $node->class instanceof Name) {
                    $className = $node->class->toString();
                    if (in_array($className, ['Content', 'Illuminate\Mail\Mailables\Content', '\Illuminate\Mail\Mailables\Content'], true)) {
                        $viewName = null;
                        $withVars = [];

                        foreach ($node->args as $idx => $arg) {
                            $argName = $arg->name?->toString();

                            if ($argName === 'view' || $argName === 'markdown' || $argName === 'html' || $argName === 'text') {
                                $viewName = $this->extractStringValue($arg->value);
                            } elseif ($argName === 'with') {
                                $withVars = $this->extractVariablesFromDataArg($arg->value);
                            } elseif ($argName === null && $idx === 0) {
                                $viewName = $this->extractStringValue($arg->value);
                            }
                        }

                        if ($viewName !== null) {
                            $variables = $this->mergeWithClassProperties($withVars);
                            return ['name' => $viewName, 'variables' => $variables];
                        }
                    }
                }

                // Pattern E: Mailable / MailMessage / Notification ->view('...'), ->markdown('...'), ->text('...')
                if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                    $methodName = $node->name->toString();
                    if (in_array($methodName, ['view', 'markdown', 'text', 'html'], true) && !empty($node->args) && isset($node->args[0])) {
                        $isMailableContext = false;
                        if ($this->currentExtends && in_array(class_basename($this->currentExtends), ['Mailable', 'Notification', 'Component', 'Livewire', 'MailMessage', 'Content'], true)) {
                            $isMailableContext = true;
                        } elseif ($node->var instanceof Variable && $node->var->name === 'this') {
                            $isMailableContext = true;
                        } elseif ($this->isMailMessageOrViewChain($node->var)) {
                            $isMailableContext = true;
                        }

                        if ($isMailableContext) {
                            $viewName = $this->extractStringValue($node->args[0]->value);
                            if ($viewName !== null) {
                                $withVars = [];
                                if (isset($node->args[1])) {
                                    $withVars = $this->extractVariablesFromDataArg($node->args[1]->value);
                                }
                                $variables = $this->mergeWithClassProperties($withVars);
                                return ['name' => $viewName, 'variables' => $variables];
                            }
                        }
                    }
                }

                // Pattern E2: Static $view property on Filament / Livewire / Widget classes
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $p) {
                        if ($p->name->toString() === 'view' && $p->default instanceof Node\Scalar\String_) {
                            $viewName = $p->default->value;
                            $variables = $this->mergeWithClassProperties([]);
                            return ['name' => $viewName, 'variables' => $variables];
                        }
                    }
                }

                // Pattern F: Custom function/method annotated with @param view-string
                if ($node instanceof FuncCall && $node->name instanceof Name) {
                    $funcName = $node->name->toString();
                    if (isset($this->viewStringFunctions[$funcName])) {
                        $meta = $this->viewStringFunctions[$funcName];
                        if (isset($node->args[$meta['viewArg']])) {
                            $viewName = $this->extractStringValue($node->args[$meta['viewArg']]->value);
                            if ($viewName !== null) {
                                $variables = [];
                                if (isset($node->args[$meta['dataArg']])) {
                                    $variables = $this->extractVariablesFromDataArg($node->args[$meta['dataArg']]->value);
                                }
                                $variables = $this->mergeWithClassProperties($variables);
                                return ['name' => $viewName, 'variables' => $variables];
                            }
                        }
                    }
                }

                if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                    $methodName = $node->name->toString();
                    if ($this->currentClass && isset($this->viewStringMethods[$this->currentClass][$methodName])) {
                        $meta = $this->viewStringMethods[$this->currentClass][$methodName];
                        if (isset($node->args[$meta['viewArg']])) {
                            $viewName = $this->extractStringValue($node->args[$meta['viewArg']]->value);
                            if ($viewName !== null) {
                                $variables = [];
                                if (isset($node->args[$meta['dataArg']])) {
                                    $variables = $this->extractVariablesFromDataArg($node->args[$meta['dataArg']]->value);
                                }
                                $variables = $this->mergeWithClassProperties($variables);
                                return ['name' => $viewName, 'variables' => $variables];
                            }
                        }
                    }
                }

                return null;
            }

            /**
             * Merge class public properties (for Mailables, Components, Livewire) into the variables array.
             *
             * @param array<int, array<string, mixed>> $variables
             * @return array<int, array<string, mixed>>
             */
            protected function mergeWithClassProperties(array $variables): array
            {
                if (empty($this->currentClassProperties)) {
                    return $variables;
                }

                $indexed = [];
                // Add class properties first
                foreach ($this->currentClassProperties as $name => $prop) {
                    $indexed[$name] = $prop;
                }

                // Add Livewire $this if class is a component
                if ($this->currentClass && ($this->currentExtends === 'Component' || str_contains($this->filePath, 'Livewire'))) {
                    $indexed['this'] = [
                        'name' => 'this',
                        'type' => "\\{$this->currentClass}",
                        'origin' => 'Livewire $this',
                        'detail' => 'Livewire Component Instance',
                    ];
                }

                // Allow explicitly passed variables in view call to override class properties
                foreach ($variables as $var) {
                    $indexed[$var['name']] = $var;
                }

                return array_values($indexed);
            }

            protected function findRootViewNameInChain(Expr $expr): ?string
            {
                if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'view') {
                    if (!empty($expr->args) && isset($expr->args[0])) {
                        return $this->extractStringValue($expr->args[0]->value);
                    }
                }

                if ($expr instanceof StaticCall && $expr->class instanceof Name && in_array($expr->class->toString(), ['View', 'Illuminate\Support\Facades\View'], true)) {
                    if ($expr->name instanceof Identifier && $expr->name->toString() === 'make') {
                        if (!empty($expr->args) && isset($expr->args[0])) {
                            return $this->extractStringValue($expr->args[0]->value);
                        }
                    }
                }

                if ($expr instanceof MethodCall) {
                    if ($expr->var instanceof Variable && $expr->var->name === 'this' && $expr->name instanceof Identifier) {
                        if (in_array($expr->name->toString(), ['view', 'markdown', 'text', 'html'], true) && !empty($expr->args) && isset($expr->args[0])) {
                            return $this->extractStringValue($expr->args[0]->value);
                        }
                    }
                    return $this->findRootViewNameInChain($expr->var);
                }

                return null;
            }

            /**
             * Extract variables passed in data argument (Array or compact() call).
             *
             * @return array<int, array<string, mixed>>
             */
            protected function extractVariablesFromDataArg(Expr $expr): array
            {
                $variables = [];

                // Array: ['user' => $user, 'posts' => $posts, 'title' => 'Hello', 'count' => 10]
                if ($expr instanceof Array_) {
                    foreach ($expr->items as $item) {
                        if ($item instanceof ArrayItem && $item->key !== null) {
                            $keyName = $this->extractStringValue($item->key);
                            if ($keyName !== null) {
                                $type = $this->inferTypeFromExpr($item->value);
                                $variables[] = [
                                    'name' => $keyName,
                                    'type' => $type,
                                    'origin' => 'Array Data',
                                    'line' => $item->getStartLine(),
                                    'source' => $this->filePath,
                                ];
                            }
                        }
                    }
                }

                // compact('user', 'posts', 'title')
                if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'compact') {
                    foreach ($expr->args as $arg) {
                        $varName = $this->extractStringValue($arg->value);
                        if ($varName !== null) {
                            $type = $this->currentScope[$varName]['type'] ?? 'mixed';
                            $variables[] = [
                                'name' => $varName,
                                'type' => $type,
                                'origin' => 'compact()',
                                'line' => $arg->getStartLine(),
                                'source' => $this->filePath,
                            ];
                        }
                    }
                }

                return $variables;
            }

            public function extractVariablesFromDataArgWithScope(Expr $expr, array $scope): array
            {
                $variables = [];

                // Array: ['user' => $user, 'posts' => $posts, 'title' => 'Hello', 'count' => 10]
                if ($expr instanceof Array_) {
                    foreach ($expr->items as $item) {
                        if ($item instanceof ArrayItem && $item->key !== null) {
                            $keyName = $this->extractStringValue($item->key);
                            if ($keyName !== null) {
                                $type = $this->inferTypeFromExpr($item->value, $scope);
                                $variables[] = [
                                    'name' => $keyName,
                                    'type' => $type,
                                    'origin' => 'View Composer Data',
                                    'line' => $item->getStartLine(),
                                    'source' => $this->filePath,
                                ];
                            }
                        }
                    }
                }

                // compact('user', 'posts', 'title')
                if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'compact') {
                    foreach ($expr->args as $arg) {
                        $varName = $this->extractStringValue($arg->value);
                        if ($varName !== null) {
                            $type = $scope[$varName]['type'] ?? 'mixed';
                            $variables[] = [
                                'name' => $varName,
                                'type' => $type,
                                'origin' => 'compact()',
                                'line' => $arg->getStartLine(),
                                'source' => $this->filePath,
                            ];
                        }
                    }
                }

                return $variables;
            }

            public function extractStringValue(Expr $expr): ?string
            {
                if ($expr instanceof String_) {
                    return $expr->value;
                }

                if ($expr instanceof InterpolatedString) {
                    foreach ($expr->parts as $part) {
                        if ($part instanceof String_) {
                            return $part->value;
                        }
                    }
                }

                return null;
            }

            public function inferTypeFromExpr(Expr $expr, ?array $scope = null): string
            {
                $scope ??= $this->currentScope;

                if ($expr instanceof Variable && is_string($expr->name)) {
                    $varName = $expr->name;
                    return $scope[$varName]['type'] ?? 'mixed';
                }

                if ($expr instanceof String_) {
                    return 'string';
                }

                if ($expr instanceof Int_) {
                    return 'int';
                }

                if ($expr instanceof Float_) {
                    return 'float';
                }

                if ($expr instanceof Array_) {
                    return 'array';
                }

                if ($expr instanceof New_) {
                    if ($expr->class instanceof Name) {
                        return $this->qualifyTypeName($expr->class->toString());
                    }
                }

                if ($expr instanceof StaticCall) {
                    if ($expr->class instanceof Name && $expr->name instanceof Identifier) {
                        $className = $this->qualifyTypeName($expr->class->toString());
                        $methodName = $expr->name->toString();

                        if (in_array($methodName, ['all', 'get', 'paginate', 'cursor', 'lazy'], true)) {
                            return "\\Illuminate\\Database\\Eloquent\\Collection<int, {$className}>";
                        }

                        if (in_array($methodName, ['find', 'findOrFail', 'first', 'firstOrFail', 'create', 'make', 'query'], true)) {
                            return $className;
                        }

                        return $className;
                    }
                }

                if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
                    $methodName = $expr->name->toString();
                    if (in_array($methodName, ['paginate', 'simplePaginate', 'cursorPaginate'], true)) {
                        $parentType = $this->inferTypeFromExpr($expr->var);
                        if (preg_match('/Collection<int,\s*([^>]+)>/', $parentType, $m)) {
                            return "\\Illuminate\\Pagination\\LengthAwarePaginator<int, {$m[1]}>";
                        }
                        return '\\Illuminate\\Pagination\\LengthAwarePaginator';
                    }
                    if (in_array($methodName, ['get', 'all'], true)) {
                        $parentType = $this->inferTypeFromExpr($expr->var);
                        return "\\Illuminate\\Database\\Eloquent\\Collection<int, {$parentType}>";
                    }
                }

                if ($expr instanceof Expr\BinaryOp\Concat) {
                    return 'string';
                }

                if ($expr instanceof Expr\BinaryOp\Plus || $expr instanceof Expr\BinaryOp\Minus || $expr instanceof Expr\BinaryOp\Mul) {
                    return 'int|float';
                }

                if ($expr instanceof Expr\BinaryOp\Div) {
                    return 'float|int';
                }

                if ($expr instanceof Expr\BinaryOp\BooleanAnd || $expr instanceof Expr\BinaryOp\BooleanOr || $expr instanceof Expr\BinaryOp\Identical || $expr instanceof Expr\BinaryOp\Equal) {
                    return 'bool';
                }

                if ($expr instanceof Expr\Cast\String_) {
                    return 'string';
                }

                if ($expr instanceof Expr\Cast\Int_) {
                    return 'int';
                }

                if ($expr instanceof Expr\Cast\Bool_) {
                    return 'bool';
                }

                if ($expr instanceof Expr\Cast\Array_) {
                    return 'array';
                }

                if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
                    return '\\Closure';
                }

                if ($expr instanceof Expr\ConstFetch && $expr->name instanceof Name) {
                    $constName = strtolower($expr->name->toString());
                    if (in_array($constName, ['true', 'false'], true)) {
                        return 'bool';
                    }
                    if ($constName === 'null') {
                        return 'null';
                    }
                }

                return 'mixed';
            }

            protected function resolveTypeNode(?Node $typeNode): string
            {
                if ($typeNode === null) {
                    return 'mixed';
                }

                if ($typeNode instanceof Identifier) {
                    return $typeNode->name;
                }

                if ($typeNode instanceof Name) {
                    return $this->qualifyTypeName($typeNode->toString());
                }

                if ($typeNode instanceof NullableType) {
                    return '?' . $this->resolveTypeNode($typeNode->type);
                }

                if ($typeNode instanceof UnionType) {
                    $types = array_map(fn ($t) => $this->resolveTypeNode($t), $typeNode->types);
                    return implode('|', $types);
                }

                if ($typeNode instanceof IntersectionType) {
                    $types = array_map(fn ($t) => $this->resolveTypeNode($t), $typeNode->types);
                    return implode('&', $types);
                }

                return 'mixed';
            }

            protected function qualifyTypeName(string $name): string
            {
                $name = ltrim($name, '\\');

                // Primitive types should remain primitive
                $primitives = ['string', 'int', 'float', 'bool', 'array', 'object', 'callable', 'iterable', 'void', 'never', 'null', 'mixed'];
                if (in_array(strtolower($name), $primitives, true)) {
                    return strtolower($name);
                }

                if (str_starts_with($name, '?')) {
                    $inner = substr($name, 1);
                    return '?' . $this->qualifyTypeName($inner);
                }

                if (isset($this->useAliases[$name])) {
                    return '\\' . $this->useAliases[$name];
                }

                if (!str_contains($name, '\\')) {
                    if (isset($this->useAliases[$name])) {
                        return '\\' . $this->useAliases[$name];
                    }
                }

                return '\\' . $name;
            }

            protected function cleanAndQualifyDocType(string $raw): string
            {
                $clean = preg_replace('/^\s*\*\s?/m', '', $raw);
                $clean = trim($clean);

                $type = '';
                $depthBraces = 0;
                $depthAngles = 0;
                $depthParens = 0;
                $len = strlen($clean);

                for ($i = 0; $i < $len; $i++) {
                    $ch = $clean[$i];
                    if ($ch === '{') $depthBraces++;
                    elseif ($ch === '}') $depthBraces = max(0, $depthBraces - 1);
                    elseif ($ch === '<') $depthAngles++;
                    elseif ($ch === '>') $depthAngles = max(0, $depthAngles - 1);
                    elseif ($ch === '(') $depthParens++;
                    elseif ($ch === ')') $depthParens = max(0, $depthParens - 1);

                    if ($depthBraces === 0 && $depthAngles === 0 && $depthParens === 0) {
                        if (ctype_space($ch) && $i + 1 < $len && $clean[$i + 1] === '$') {
                            break;
                        }
                        if ($ch === '*' && $i + 1 < $len && $clean[$i + 1] === '/') {
                            break;
                        }
                    }

                    $type .= $ch;
                }

                return $this->qualifyDocType($type);
            }

            protected function splitTypeUnion(string $type): array
            {
                $parts = [];
                $current = '';
                $depthBraces = 0;
                $depthAngles = 0;
                $depthParens = 0;
                $len = strlen($type);

                for ($i = 0; $i < $len; $i++) {
                    $ch = $type[$i];
                    if ($ch === '{') $depthBraces++;
                    elseif ($ch === '}') $depthBraces = max(0, $depthBraces - 1);
                    elseif ($ch === '<') $depthAngles++;
                    elseif ($ch === '>') $depthAngles = max(0, $depthAngles - 1);
                    elseif ($ch === '(') $depthParens++;
                    elseif ($ch === ')') $depthParens = max(0, $depthParens - 1);

                    if ($ch === '|' && $depthBraces === 0 && $depthAngles === 0 && $depthParens === 0) {
                        if (trim($current) !== '') {
                            $parts[] = trim($current);
                        }
                        $current = '';
                    } else {
                        $current .= $ch;
                    }
                }

                if (trim($current) !== '') {
                    $parts[] = trim($current);
                }

                return $parts;
            }

            protected function qualifyDocType(string $type): string
            {
                $type = trim($type);
                if ($type === '') {
                    return 'mixed';
                }

                // If array shape e.g. array{ip?: string, user_agent?: string}
                if (str_starts_with($type, 'array{') || str_starts_with($type, '{')) {
                    $type = preg_replace('/\s+/', ' ', $type);
                    $type = preg_replace('/\s*:\s*/', ': ', $type);
                    $type = preg_replace('/\s*,\s*/', ', ', $type);
                    $type = preg_replace('/\{\s+/', '{', $type);
                    $type = preg_replace('/\s+\}/', '}', $type);
                    return $type;
                }

                // If union type e.g. "staging"|"production" or array{...}|null
                $unionParts = $this->splitTypeUnion($type);
                if (count($unionParts) > 1) {
                    $qualified = array_map(fn ($p) => $this->qualifyDocType($p), $unionParts);
                    return implode('|', $qualified);
                }

                // If string literal e.g. "staging" or 'production'
                if ((str_starts_with($type, '"') && str_ends_with($type, '"')) ||
                    (str_starts_with($type, "'") && str_ends_with($type, "'"))) {
                    return $type;
                }

                // If array/generic e.g. Collection<User> or array<string, mixed>
                if (preg_match('/^([a-zA-Z0-9_\\\\]+)<(.+)>$/', $type, $m)) {
                    $outer = $this->qualifyTypeName($m[1]);
                    return "{$outer}<{$m[2]}>";
                }

                if (str_ends_with($type, '[]')) {
                    $inner = substr($type, 0, -2);
                    return $this->qualifyTypeName($inner) . '[]';
                }

                return $this->qualifyTypeName($type);
            }

            public function recordViewData(string $viewName, array $variables): void
            {
                if (!isset($this->views[$viewName])) {
                    $this->views[$viewName] = [
                        'key' => $viewName,
                        'variables' => [],
                        'sources' => [],
                    ];
                }

                if ($this->filePath !== '' && !in_array($this->filePath, $this->views[$viewName]['sources'], true)) {
                    $this->views[$viewName]['sources'][] = $this->filePath;
                }

                foreach ($variables as $var) {
                    $varName = $var['name'];
                    $this->views[$viewName]['variables'][$varName] = array_merge($var, [
                        'source' => $this->filePath,
                    ]);
                }
            }

            public function extractViewShareAndComposers(Node $node): void
            {
                // 1. Static calls on View facade: View::share(...), View::composer(...), View::creator(...)
                if ($node instanceof StaticCall && $this->isViewFacade($node->class)) {
                    $methodName = $node->name instanceof Identifier ? $node->name->toString() : '';
                    if ($methodName === 'share') {
                        $this->handleViewShareCall($node);
                    } elseif (in_array($methodName, ['composer', 'creator'], true)) {
                        $this->handleViewComposerCall($node);
                    }
                }

                // 2. Method calls on view() / app('view'): view()->share(...), view()->composer(...)
                if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                    $methodName = $node->name->toString();
                    if ($methodName === 'share' && $this->isViewInstance($node->var)) {
                        $this->handleViewShareCall($node);
                    } elseif (in_array($methodName, ['composer', 'creator'], true) && $this->isViewInstance($node->var)) {
                        $this->handleViewComposerCall($node);
                    }
                }
            }

            public function isViewFacade(Node $classNode): bool
            {
                if (!$classNode instanceof Name) {
                    return false;
                }

                $raw = $classNode->toString();
                $firstPart = $classNode->getFirst();
                $resolved = isset($this->useAliases[$firstPart])
                    ? $this->useAliases[$firstPart] . substr($raw, strlen($firstPart))
                    : $raw;

                $normalized = ltrim($resolved, '\\');
                return in_array($normalized, [
                    'View',
                    'Illuminate\Support\Facades\View',
                    'Facades\View',
                ], true);
            }

            public function isViewInstance(Expr $expr): bool
            {
                if ($expr instanceof FuncCall && $expr->name instanceof Name) {
                    $name = ltrim($expr->name->toString(), '\\');
                    if ($name === 'view') {
                        return true;
                    }
                    if (in_array($name, ['app', 'resolve'], true) && !empty($expr->args) && isset($expr->args[0])) {
                        $argVal = $this->extractStringValue($expr->args[0]->value);
                        if (in_array($argVal, ['view', 'Illuminate\View\Factory', 'Illuminate\Contracts\View\Factory'], true)) {
                            return true;
                        }
                    }
                }

                if ($expr instanceof StaticCall && $this->isViewFacade($expr->class)) {
                    return true;
                }

                return false;
            }

            public function handleViewShareCall(Node $node): void
            {
                if (empty($node->args) || !isset($node->args[0])) {
                    return;
                }

                if (count($node->args) === 1) {
                    $variables = $this->extractVariablesFromDataArg($node->args[0]->value);
                    $this->recordViewData('*', $variables);
                } elseif (count($node->args) >= 2 && isset($node->args[1])) {
                    $keyName = $this->extractStringValue($node->args[0]->value);
                    if ($keyName !== null) {
                        $type = $this->inferTypeFromExpr($node->args[1]->value);
                        $variables = [[
                            'name' => $keyName,
                            'type' => $type,
                            'origin' => 'View::share()',
                            'line' => $node->getStartLine(),
                            'source' => $this->filePath,
                        ]];
                        $this->recordViewData('*', $variables);
                    }
                }
            }

            public function handleViewComposerCall(Node $node): void
            {
                if (count($node->args) < 2 || !isset($node->args[0], $node->args[1])) {
                    return;
                }

                $targetViews = [];
                $targetExpr = $node->args[0]->value;
                if ($targetExpr instanceof Array_) {
                    foreach ($targetExpr->items as $item) {
                        if ($item instanceof ArrayItem) {
                            $val = $this->extractStringValue($item->value);
                            if ($val !== null) {
                                $targetViews[] = $val;
                            }
                        }
                    }
                } else {
                    $val = $this->extractStringValue($targetExpr);
                    if ($val !== null) {
                        $targetViews[] = $val;
                    }
                }

                if (empty($targetViews)) {
                    return;
                }

                $callbackExpr = $node->args[1]->value;

                // Case A: Closure or Arrow Function
                if ($callbackExpr instanceof Node\Expr\Closure || $callbackExpr instanceof Node\Expr\ArrowFunction) {
                    $closureVariables = $this->extractVariablesFromClosure($callbackExpr);
                    foreach ($targetViews as $targetView) {
                        $this->recordViewData($targetView, $closureVariables);
                    }
                    return;
                }

                // Case B: Class string / ClassConstFetch: ProfileComposer::class or 'App\View\Composers\ProfileComposer'
                $className = $this->extractClassNameFromExpr($callbackExpr);
                if ($className !== null) {
                    $cleanClass = ltrim($className, '\\');
                    $shortClass = class_basename($cleanClass);

                    foreach ($targetViews as $targetView) {
                        $this->composerBindings[$cleanClass][] = $targetView;
                        $this->composerBindings[$shortClass][] = $targetView;
                    }

                    if (isset($this->composerClasses[$cleanClass])) {
                        foreach ($targetViews as $targetView) {
                            $this->recordViewData($targetView, $this->composerClasses[$cleanClass]);
                        }
                    } elseif (isset($this->composerClasses[$shortClass])) {
                        foreach ($targetViews as $targetView) {
                            $this->recordViewData($targetView, $this->composerClasses[$shortClass]);
                        }
                    }
                }
            }

            public function extractVariablesFromClosure(Node\Expr\Closure|Node\Expr\ArrowFunction $closure): array
            {
                $variables = [];
                $localScope = $this->currentScope;

                $stmts = $closure instanceof Node\Expr\ArrowFunction
                    ? [new Node\Stmt\Expression($closure->expr)]
                    : $closure->stmts;

                $subTraverser = new NodeTraverser();
                $subVisitor = new class($variables, $localScope, $this->filePath, $this) extends NodeVisitorAbstract {
                    public function __construct(
                        public array &$variables,
                        public array &$localScope,
                        public string $filePath,
                        public object $parentVisitor,
                    ) {}

                    public function enterNode(Node $node)
                    {
                        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                            $varName = $node->var->name;
                            $type = $this->parentVisitor->inferTypeFromExpr($node->expr, $this->localScope);
                            $this->localScope[$varName] = [
                                'name' => $varName,
                                'type' => $type,
                                'origin' => 'Closure Assignment',
                            ];
                        }

                        if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'with') {
                            if ($node->var instanceof Variable && in_array($node->var->name, ['view', 'v'], true)) {
                                if (count($node->args) === 1 && isset($node->args[0])) {
                                    $extracted = $this->parentVisitor->extractVariablesFromDataArgWithScope($node->args[0]->value, $this->localScope);
                                    foreach ($extracted as $v) {
                                        $this->variables[] = $v;
                                    }
                                } elseif (count($node->args) >= 2 && isset($node->args[0], $node->args[1])) {
                                    $keyName = $this->parentVisitor->extractStringValue($node->args[0]->value);
                                    if ($keyName !== null) {
                                        $type = $this->parentVisitor->inferTypeFromExpr($node->args[1]->value, $this->localScope);
                                        $this->variables[] = [
                                            'name' => $keyName,
                                            'type' => $type,
                                            'origin' => 'View Composer with()',
                                            'line' => $node->getStartLine(),
                                            'source' => $this->filePath,
                                        ];
                                    }
                                }
                            }
                        }

                        if ($node instanceof Assign && $node->var instanceof Node\Expr\ArrayDimFetch && $node->var->var instanceof Variable && in_array($node->var->var->name, ['view', 'v'], true)) {
                            if ($node->var->dim !== null) {
                                $keyName = $this->parentVisitor->extractStringValue($node->var->dim);
                                if ($keyName !== null) {
                                    $type = $this->parentVisitor->inferTypeFromExpr($node->expr, $this->localScope);
                                    $this->variables[] = [
                                        'name' => $keyName,
                                        'type' => $type,
                                        'origin' => 'View Composer ArrayAccess',
                                        'line' => $node->getStartLine(),
                                        'source' => $this->filePath,
                                    ];
                                }
                            }
                        }

                        return null;
                    }
                };

                $subTraverser->addVisitor($subVisitor);
                $subTraverser->traverse($stmts);

                return $variables;
            }

            public function extractVariablesFromMethodStmts(array $stmts): array
            {
                $variables = [];
                $localScope = $this->currentScope;

                $subTraverser = new NodeTraverser();
                $subVisitor = new class($variables, $localScope, $this->filePath, $this) extends NodeVisitorAbstract {
                    public function __construct(
                        public array &$variables,
                        public array &$localScope,
                        public string $filePath,
                        public object $parentVisitor,
                    ) {}

                    public function enterNode(Node $node)
                    {
                        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
                            $varName = $node->var->name;
                            $type = $this->parentVisitor->inferTypeFromExpr($node->expr, $this->localScope);
                            $this->localScope[$varName] = [
                                'name' => $varName,
                                'type' => $type,
                                'origin' => 'Composer Assignment',
                            ];
                        }

                        if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'with') {
                            if ($node->var instanceof Variable && in_array($node->var->name, ['view', 'v'], true)) {
                                if (count($node->args) === 1 && isset($node->args[0])) {
                                    $extracted = $this->parentVisitor->extractVariablesFromDataArgWithScope($node->args[0]->value, $this->localScope);
                                    foreach ($extracted as $v) {
                                        $this->variables[] = $v;
                                    }
                                } elseif (count($node->args) >= 2 && isset($node->args[0], $node->args[1])) {
                                    $keyName = $this->parentVisitor->extractStringValue($node->args[0]->value);
                                    if ($keyName !== null) {
                                        $type = $this->parentVisitor->inferTypeFromExpr($node->args[1]->value, $this->localScope);
                                        $this->variables[] = [
                                            'name' => $keyName,
                                            'type' => $type,
                                            'origin' => 'View Composer with()',
                                            'line' => $node->getStartLine(),
                                            'source' => $this->filePath,
                                        ];
                                    }
                                }
                            }
                        }

                        if ($node instanceof Assign && $node->var instanceof Node\Expr\ArrayDimFetch && $node->var->var instanceof Variable && in_array($node->var->var->name, ['view', 'v'], true)) {
                            if ($node->var->dim !== null) {
                                $keyName = $this->parentVisitor->extractStringValue($node->var->dim);
                                if ($keyName !== null) {
                                    $type = $this->parentVisitor->inferTypeFromExpr($node->expr, $this->localScope);
                                    $this->variables[] = [
                                        'name' => $keyName,
                                        'type' => $type,
                                        'origin' => 'View Composer ArrayAccess',
                                        'line' => $node->getStartLine(),
                                        'source' => $this->filePath,
                                    ];
                                }
                            }
                        }

                        return null;
                    }
                };

                $subTraverser->addVisitor($subVisitor);
                $subTraverser->traverse($stmts);

                return $variables;
            }

            public function extractClassNameFromExpr(Expr $expr): ?string
            {
                if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Name) {
                    $raw = $expr->class->toString();
                    return $this->qualifyTypeName($raw);
                }

                if ($expr instanceof String_) {
                    $val = explode('@', $expr->value)[0];
                    return $this->qualifyTypeName($val);
                }

                return null;
            }

            protected function isMailMessageOrViewChain(Node $expr): bool
            {
                if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Name) {
                    $classBase = class_basename($expr->class->toString());
                    return in_array($classBase, ['MailMessage', 'HtmlString', 'Content', 'View'], true);
                }
                if ($expr instanceof MethodCall) {
                    $mName = $expr->name instanceof Identifier ? $expr->name->toString() : '';
                    if (in_array($mName, ['line', 'action', 'subject', 'greeting', 'salutation', 'from', 'mailer', 'attach', 'with'], true)) {
                        return true;
                    }
                    return $this->isMailMessageOrViewChain($expr->var);
                }
                return false;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        // Merge any composer bindings resolved in this file
        foreach ($composerBindings as $cKey => $targetViews) {
            if (isset($composerClasses[$cKey])) {
                foreach ($targetViews as $tView) {
                    $visitor->recordViewData($tView, $composerClasses[$cKey]);
                }
            }
        }

        return $views;
    }
}
