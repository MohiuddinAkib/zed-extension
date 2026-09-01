<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

class PhpViewVariableRenameAnalyzer
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
     * Find if cursor in PHP source code is on a view variable key/string.
     *
     * @return array{
     *     name: string,
     *     viewNames: array<int, string>,
     *     range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     * }|null
     */
    public function findTargetAtPosition(string $code, int $lineNumber, int $character): ?array
    {
        $viewPasses = $this->extractViewPasses($code);
        $lineOffsets = $this->calculateLineOffsets($code);

        foreach ($viewPasses as $pass) {
            foreach ($pass['variables'] as $var) {
                $startLoc = $this->offsetToLineAndCol($var['startOffset'], $lineOffsets, $code);
                $endLoc = $this->offsetToLineAndCol($var['endOffset'], $lineOffsets, $code);

                $startLine = $startLoc['line'];
                $startCol = $startLoc['col'];
                $endCol = $endLoc['col'] + 1;

                if ($startLine === $lineNumber && $character >= $startCol && $character <= $endCol) {
                    return [
                        'name' => $var['name'],
                        'viewNames' => $pass['views'],
                        'range' => [
                            'start' => ['line' => $startLine, 'character' => $startCol],
                            'end' => ['line' => $startLine, 'character' => $endCol],
                        ],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Find text edits in PHP source code for a specific target variable and target view(s).
     *
     * @param  array<int, string>|string|null  $targetViews
     * @return array<int, array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}>
     */
    public function findEditsForViewVariable(string $code, string $targetVarName, array|string|null $targetViews, string $newName): array
    {
        $viewPasses = $this->extractViewPasses($code);
        $lineOffsets = $this->calculateLineOffsets($code);
        $edits = [];
        $seen = [];

        $cleanNewName = ltrim($newName, '$');
        $targetViewList = is_string($targetViews) ? [$targetViews] : ($targetViews ?? []);

        foreach ($viewPasses as $pass) {
            $viewsInPass = $pass['views'];

            // Check if this view pass matches any target view
            $matches = empty($targetViewList) || in_array('*', $viewsInPass, true);
            if (!$matches) {
                foreach ($targetViewList as $tView) {
                    foreach ($viewsInPass as $pView) {
                        if ($this->matchesViewKey($pView, $tView)) {
                            $matches = true;
                            break 2;
                        }
                    }
                }
            }

            if (!$matches) {
                continue;
            }

            foreach ($pass['variables'] as $var) {
                if ($var['name'] !== $targetVarName) {
                    continue;
                }

                $replacementText = $var['isMethod'] ? 'with' . ucfirst($cleanNewName) : $cleanNewName;

                $startLoc = $this->offsetToLineAndCol($var['startOffset'], $lineOffsets, $code);
                $endLoc = $this->offsetToLineAndCol($var['endOffset'], $lineOffsets, $code);

                $startLine = $startLoc['line'];
                $startCol = $startLoc['col'];
                $endCol = $endLoc['col'] + 1;

                $key = "{$startLine}:{$startCol}";
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $edits[] = [
                        'range' => [
                            'start' => ['line' => $startLine, 'character' => $startCol],
                            'end' => ['line' => $startLine, 'character' => $endCol],
                        ],
                        'newText' => $replacementText,
                    ];
                }
            }
        }

        return $edits;
    }

    /**
     * Extract all view passes with their views and variables from PHP source code.
     *
     * @return array<int, array{
     *     views: array<int, string>,
     *     variables: array<int, array{name: string, startOffset: int, endOffset: int, isMethod: bool}>
     * }>
     */
    public function extractViewPasses(string $code): array
    {
        try {
            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $passes = [];

        $traverser = new NodeTraverser();
        $visitor = new class($passes, $this) extends NodeVisitorAbstract {
            public function __construct(
                public array &$passes,
                public PhpViewVariableRenameAnalyzer $analyzer,
            ) {}

            public function enterNode(Node $node)
            {
                // 1. view('view.name', [...])
                if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'view') {
                    if (!empty($node->args) && isset($node->args[0])) {
                        $viewName = $this->analyzer->extractStringValue($node->args[0]->value);
                        if ($viewName !== null) {
                            $vars = [];
                            if (isset($node->args[1])) {
                                $vars = $this->analyzer->extractVariablesFromDataExpr($node->args[1]->value);
                            }
                            if (!empty($vars)) {
                                $this->passes[] = [
                                    'views' => [$viewName],
                                    'variables' => $vars,
                                ];
                            }
                        }
                    }
                }

                // 2. View::make('view.name', [...])
                if ($node instanceof StaticCall && $node->class instanceof Name && $this->analyzer->isViewFacade($node->class)) {
                    $methodName = $node->name instanceof Identifier ? $node->name->toString() : '';
                    if ($methodName === 'make' && !empty($node->args) && isset($node->args[0])) {
                        $viewName = $this->analyzer->extractStringValue($node->args[0]->value);
                        if ($viewName !== null) {
                            $vars = [];
                            if (isset($node->args[1])) {
                                $vars = $this->analyzer->extractVariablesFromDataExpr($node->args[1]->value);
                            }
                            if (!empty($vars)) {
                                $this->passes[] = [
                                    'views' => [$viewName],
                                    'variables' => $vars,
                                ];
                            }
                        }
                    }
                }

                // 3. ->with('key', $val) or ->with([...]) or ->withUser(...) chained on view call
                if ($node instanceof MethodCall && $node->name instanceof Identifier) {
                    $methodName = $node->name->toString();

                    if ($methodName === 'with') {
                        $rootViewName = $this->analyzer->findRootViewNameInChain($node->var);
                        if ($rootViewName !== null) {
                            $vars = [];
                            if (count($node->args) === 1 && isset($node->args[0])) {
                                $vars = $this->analyzer->extractVariablesFromDataExpr($node->args[0]->value);
                            } elseif (count($node->args) >= 2 && isset($node->args[0])) {
                                $keyNode = $node->args[0]->value;
                                if ($keyNode instanceof String_) {
                                    $vars[] = [
                                        'name' => $keyNode->value,
                                        'startOffset' => $keyNode->getStartFilePos() + 1,
                                        'endOffset' => $keyNode->getEndFilePos() - 1,
                                        'isMethod' => false,
                                    ];
                                }
                            }
                            if (!empty($vars)) {
                                $this->passes[] = [
                                    'views' => [$rootViewName],
                                    'variables' => $vars,
                                ];
                            }
                        }
                    } elseif (str_starts_with($methodName, 'with') && strlen($methodName) > 4) {
                        $rootViewName = $this->analyzer->findRootViewNameInChain($node->var);
                        if ($rootViewName !== null) {
                            $keyName = lcfirst(substr($methodName, 4));
                            $this->passes[] = [
                                'views' => [$rootViewName],
                                'variables' => [[
                                    'name' => $keyName,
                                    'startOffset' => $node->name->getStartFilePos(),
                                    'endOffset' => $node->name->getEndFilePos(),
                                    'isMethod' => true,
                                ]],
                            ];
                        }
                    }
                }

                // 4. View::share('key', $val) / View::share(['key' => $val]) / view()->share(...)
                if (($node instanceof StaticCall && $this->analyzer->isViewFacade($node->class)) ||
                    ($node instanceof MethodCall && $this->analyzer->isViewInstance($node->var))) {
                    $methodName = $node->name instanceof Identifier ? $node->name->toString() : '';
                    if ($methodName === 'share') {
                        $vars = [];
                        if (count($node->args) === 1 && isset($node->args[0])) {
                            $vars = $this->analyzer->extractVariablesFromDataExpr($node->args[0]->value);
                        } elseif (count($node->args) >= 2 && isset($node->args[0])) {
                            $keyNode = $node->args[0]->value;
                            if ($keyNode instanceof String_) {
                                $vars[] = [
                                    'name' => $keyNode->value,
                                    'startOffset' => $keyNode->getStartFilePos() + 1,
                                    'endOffset' => $keyNode->getEndFilePos() - 1,
                                    'isMethod' => false,
                                ];
                            }
                        }
                        if (!empty($vars)) {
                            $this->passes[] = [
                                'views' => ['*'],
                                'variables' => $vars,
                            ];
                        }
                    } elseif (in_array($methodName, ['composer', 'creator'], true)) {
                        // 5. View::composer('viewName', function ($view) { ... })
                        if (count($node->args) >= 2 && isset($node->args[0], $node->args[1])) {
                            $targetViews = $this->analyzer->extractViewNamesFromArg($node->args[0]->value);
                            $callbackExpr = $node->args[1]->value;

                            if ($callbackExpr instanceof Node\Expr\Closure || $callbackExpr instanceof Node\Expr\ArrowFunction) {
                                $vars = $this->analyzer->extractVariablesFromComposerClosure($callbackExpr);
                                if (!empty($vars) && !empty($targetViews)) {
                                    $this->passes[] = [
                                        'views' => $targetViews,
                                        'variables' => $vars,
                                    ];
                                }
                            }
                        }
                    }
                }

                // 6. Class-based Composer method: public function compose(View $view)
                if ($node instanceof Node\Stmt\ClassMethod && in_array($node->name->toString(), ['compose', 'create'], true)) {
                    if ($node->stmts !== null) {
                        $vars = $this->analyzer->extractVariablesFromComposerStmts($node->stmts);
                        if (!empty($vars)) {
                            $this->passes[] = [
                                'views' => ['*'], // Will match any view bound to this composer
                                'variables' => $vars,
                            ];
                        }
                    }
                }

                // 7. Mailable Content: new Content(view: 'view.name', with: ['key' => $val])
                if ($node instanceof New_ && $node->class instanceof Name) {
                    $className = $node->class->toString();
                    if (in_array($className, ['Content', 'Illuminate\Mail\Mailables\Content', '\Illuminate\Mail\Mailables\Content'], true)) {
                        $viewName = null;
                        $withVars = [];
                        foreach ($node->args as $idx => $arg) {
                            $argName = $arg->name?->toString();
                            if ($argName === 'view' || $argName === 'markdown' || $argName === 'html' || $argName === 'text' || ($argName === null && $idx === 0)) {
                                $viewName = $this->analyzer->extractStringValue($arg->value);
                            } elseif ($argName === 'with') {
                                $withVars = $this->analyzer->extractVariablesFromDataExpr($arg->value);
                            }
                        }
                        if ($viewName !== null && !empty($withVars)) {
                            $this->passes[] = [
                                'views' => [$viewName],
                                'variables' => $withVars,
                            ];
                        }
                    }
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $passes;
    }

    /**
     * @return array<int, array{name: string, startOffset: int, endOffset: int, isMethod: bool}>
     */
    public function extractVariablesFromDataExpr(Expr $expr): array
    {
        $variables = [];

        // Array: ['user' => $user, 'posts' => $posts]
        if ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if ($item instanceof ArrayItem && $item->key instanceof String_) {
                    $variables[] = [
                        'name' => $item->key->value,
                        'startOffset' => $item->key->getStartFilePos() + 1,
                        'endOffset' => $item->key->getEndFilePos() - 1,
                        'isMethod' => false,
                    ];
                }
            }
        }

        // compact('user', 'posts')
        if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'compact') {
            foreach ($expr->args as $arg) {
                if ($arg->value instanceof String_) {
                    $variables[] = [
                        'name' => $arg->value->value,
                        'startOffset' => $arg->value->getStartFilePos() + 1,
                        'endOffset' => $arg->value->getEndFilePos() - 1,
                        'isMethod' => false,
                    ];
                }
            }
        }

        return $variables;
    }

    /**
     * @return array<int, array{name: string, startOffset: int, endOffset: int, isMethod: bool}>
     */
    public function extractVariablesFromComposerClosure(Node\Expr\Closure|Node\Expr\ArrowFunction $closure): array
    {
        $stmts = $closure instanceof Node\Expr\ArrowFunction
            ? [new Node\Stmt\Expression($closure->expr)]
            : $closure->stmts;

        return $this->extractVariablesFromComposerStmts($stmts);
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     * @return array<int, array{name: string, startOffset: int, endOffset: int, isMethod: bool}>
     */
    public function extractVariablesFromComposerStmts(array $stmts): array
    {
        $variables = [];
        $traverser = new NodeTraverser();
        $visitor = new class($variables, $this) extends NodeVisitorAbstract {
            public function __construct(
                public array &$variables,
                public PhpViewVariableRenameAnalyzer $analyzer,
            ) {}

            public function enterNode(Node $node)
            {
                if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === 'with') {
                    if (count($node->args) === 1 && isset($node->args[0])) {
                        $extracted = $this->analyzer->extractVariablesFromDataExpr($node->args[0]->value);
                        foreach ($extracted as $v) {
                            $this->variables[] = $v;
                        }
                    } elseif (count($node->args) >= 2 && isset($node->args[0])) {
                        $keyNode = $node->args[0]->value;
                        if ($keyNode instanceof String_) {
                            $this->variables[] = [
                                'name' => $keyNode->value,
                                'startOffset' => $keyNode->getStartFilePos() + 1,
                                'endOffset' => $keyNode->getEndFilePos() - 1,
                                'isMethod' => false,
                            ];
                        }
                    }
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $variables;
    }

    /**
     * @return array<int, string>
     */
    public function extractViewNamesFromArg(Expr $expr): array
    {
        $views = [];
        if ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if ($item instanceof ArrayItem) {
                    $val = $this->extractStringValue($item->value);
                    if ($val !== null) {
                        $views[] = $val;
                    }
                }
            }
        } else {
            $val = $this->extractStringValue($expr);
            if ($val !== null) {
                $views[] = $val;
            }
        }

        return $views;
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

    public function findRootViewNameInChain(Expr $expr): ?string
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toString() === 'view') {
            if (!empty($expr->args) && isset($expr->args[0])) {
                return $this->extractStringValue($expr->args[0]->value);
            }
        }

        if ($expr instanceof StaticCall && $expr->class instanceof Name && $this->isViewFacade($expr->class)) {
            if ($expr->name instanceof Identifier && $expr->name->toString() === 'make') {
                if (!empty($expr->args) && isset($expr->args[0])) {
                    return $this->extractStringValue($expr->args[0]->value);
                }
            }
        }

        if ($expr instanceof MethodCall) {
            return $this->findRootViewNameInChain($expr->var);
        }

        return null;
    }

    public function isViewFacade(Node $classNode): bool
    {
        if (!$classNode instanceof Name) {
            return false;
        }

        $raw = ltrim($classNode->toString(), '\\');
        return in_array($raw, [
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
        }

        if ($expr instanceof StaticCall && $this->isViewFacade($expr->class)) {
            return true;
        }

        return false;
    }

    public function matchesViewKey(string $pattern, string $viewKey): bool
    {
        if ($pattern === '*' || $pattern === $viewKey) {
            return true;
        }

        if ($viewKey === '') {
            return false;
        }

        if (str_contains($pattern, '*')) {
            return \Illuminate\Support\Str::is($pattern, $viewKey);
        }

        return false;
    }

    protected function calculateLineOffsets(string $content): array
    {
        $offsets = [0];
        $pos = 0;
        while (($pos = strpos($content, "\n", $pos)) !== false) {
            $pos++;
            $offsets[] = $pos;
        }
        return $offsets;
    }

    protected function offsetToLineAndCol(int $offset, array $lineOffsets, string $fullContent = ''): array
    {
        $low = 0;
        $high = count($lineOffsets) - 1;
        $line = 0;

        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            if ($lineOffsets[$mid] <= $offset) {
                $line = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        $lineStartByte = $lineOffsets[$line];
        $byteOffsetInLine = max(0, $offset - $lineStartByte);

        if ($fullContent !== '') {
            $lineEndByte = $lineOffsets[$line + 1] ?? strlen($fullContent);
            $lineContent = substr($fullContent, $lineStartByte, $lineEndByte - $lineStartByte);
            $col = \App\Lsp\Support\Utf16Position::byteOffsetToUtf16Column($lineContent, $byteOffsetInLine);
        } else {
            $col = $byteOffsetInLine;
        }

        return ['line' => $line, 'col' => max(0, $col)];
    }
}
