<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Semantics\ScopeOrigin;
use App\Lsp\Semantics\SourceRange;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VariableSymbol;
use Illuminate\Support\Str;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Stillat\BladeParser\Document\Document as BladeDocument;
use Throwable;

class BladeAstAnalyzer
{
    protected Parser $phpParser;
    protected NodeFinder $nodeFinder;
    protected DocBlockParser $docBlockParser;

    public function __construct()
    {
        $this->phpParser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
        $this->docBlockParser = new DocBlockParser();
    }

    /**
     * Analyze Blade content and return legacy variable arrays for existing providers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function extractTemplateVariables(string $content, ?string $source = null): array
    {
        return array_map(
            fn (VariableSymbol $symbol): array => $symbol->toLegacyArray(),
            $this->extractTemplateSymbols($content, $source),
        );
    }

    /**
     * Analyze Blade content and return semantic variable symbols.
     *
     * @return array<string, VariableSymbol>
     */
    public function extractTemplateSymbols(string $content, ?string $source = null): array
    {
        $symbols = [];

        try {
            $doc = BladeDocument::fromText($content);

            foreach ($doc->findDirectivesByName('props') as $propsDirective) {
                $line = $propsDirective->position ? $propsDirective->position->startLine : 1;
                foreach ($this->parsePropsSymbols((string) $propsDirective->arguments, $source, $line) as $name => $symbol) {
                    $symbols[$name] = $symbol;
                }
            }

            foreach ($doc->findDirectivesByName('inject') as $injectDirective) {
                $line = $injectDirective->position ? $injectDirective->position->startLine : 1;
                $symbol = $this->parseInjectSymbol((string) $injectDirective->arguments, $source, $line);
                if ($symbol !== null) {
                    $symbols[$symbol->name] = $symbol;
                }
            }

            foreach ($doc->findDirectivesByName('php') as $phpDirective) {
                $line = $phpDirective->position ? $phpDirective->position->startLine : 1;
                $symbol = $this->parseInlinePhpSymbol((string) $phpDirective->arguments, $source, $line);
                if ($symbol !== null) {
                    $symbols[$symbol->name] = $symbol;
                }
            }

            foreach ($doc->getPhpBlocks() as $phpBlock) {
                $line = $phpBlock->position ? $phpBlock->position->startLine : 1;
                foreach ($this->parsePhpBlockSymbols((string) $phpBlock->innerContent, $source, $line) as $name => $symbol) {
                    $symbols[$name] = $symbol;
                }
            }

            foreach ($this->extractPhpDocSymbols($content, $source) as $name => $symbol) {
                $symbols[$name] = $symbol;
            }
        } catch (Throwable) {
            // Blade can be syntactically incomplete while the user is typing.
        }

        return $symbols;
    }

    /**
     * Extract @props directive symbols from Blade content.
     *
     * @return array<string, VariableSymbol>
     */
    public function extractPropsDirectiveSymbols(string $content, ?string $source = null): array
    {
        $symbols = [];

        try {
            $doc = BladeDocument::fromText($content);

            foreach ($doc->findDirectivesByName('props') as $propsDirective) {
                $line = $propsDirective->position ? $propsDirective->position->startLine : 1;
                foreach ($this->parsePropsSymbols((string) $propsDirective->arguments, $source, $line) as $name => $symbol) {
                    $symbols[$name] = $symbol;
                }
            }
        } catch (Throwable) {}

        return $symbols;
    }

    /**
     * Parse @props arguments into legacy arrays.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parsePropsDirective(string $rawArgs): array
    {
        return array_map(
            fn (VariableSymbol $symbol): array => $symbol->toLegacyArray(),
            $this->parsePropsSymbols($rawArgs),
        );
    }

    /**
     * @return array<string, VariableSymbol>
     */
    public function parsePropsSymbols(string $rawArgs, ?string $source = null, int $line = 1): array
    {
        $symbols = [];
        $expression = $this->unwrapDirectiveArguments($rawArgs);
        $parsed = $this->parseExpression($expression);

        if (!$parsed instanceof Array_) {
            return [];
        }

        $code = '<?php ' . $expression . ';';

        foreach ($parsed->items as $item) {
            if ($item === null) {
                continue;
            }

            $rawName = null;
            $defaultExpr = null;
            $hasDefault = false;

            if ($item->key === null && $item->value instanceof String_) {
                $rawName = $item->value->value;
            } elseif ($item->key instanceof String_ || $item->key instanceof Int_) {
                $rawName = (string) ($item->key instanceof String_ ? $item->key->value : $item->key->value);
                $defaultExpr = $this->nodeSource($code, $item->value);
                $hasDefault = true;
            }

            if (!is_string($rawName) || $rawName === '') {
                continue;
            }

            $name = Str::camel($rawName);
            $type = $hasDefault ? $this->inferTypeFromExprNode($item->value) : 'mixed';

            $symbols[$name] = new VariableSymbol(
                name: $name,
                type: TypeRef::fromString($type),
                origin: new ScopeOrigin('@props', $source, $line, 'Component prop from @props directive'),
                detail: 'Component prop from @props directive',
                range: SourceRange::line($line),
                defaultValue: $defaultExpr,
                metadata: [
                    'attribute' => $rawName,
                    'required' => !$hasDefault,
                    'hasDefault' => $hasDefault,
                ],
            );
        }

        return $symbols;
    }

    /**
     * Extract @use(...) directives from Blade template content.
     *
     * @return array<string, array{alias: string, class: string, line: int}> Map of alias => info
     */
    public function extractUseDirectives(string $content): array
    {
        $uses = [];

        try {
            $doc = BladeDocument::fromText($content);
            foreach ($doc->getDirectives() as $directive) {
                if ($directive->content !== 'use') {
                    continue;
                }

                $rawArgs = $directive->arguments ? (string) $directive->arguments->innerContent : '';
                $line = $directive->position ? $directive->position->startLine : 1;

                $parsed = $this->parseUseArguments($rawArgs, $line);
                if ($parsed !== null) {
                    $uses[$parsed['alias']] = $parsed;
                }
            }
        } catch (\Throwable) {}

        // Regex fallback
        if (preg_match_all('/@use\s*\(\s*(?:[\'"]([a-zA-Z0-9_\\\\]+)[\'"]|([a-zA-Z0-9_\\\\]+)::class)(?:\s*,\s*[\'"]([a-zA-Z0-9_]+)[\'"])?\s*\)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $targetClass = !empty($m[1]) ? $m[1] : $m[2];
                $alias = !empty($m[3]) ? $m[3] : class_basename($targetClass);
                if (!isset($uses[$alias])) {
                    $uses[$alias] = [
                        'alias' => $alias,
                        'class' => '\\' . ltrim($targetClass, '\\'),
                        'line' => 1,
                    ];
                }
            }
        }

        return $uses;
    }

    /**
     * @return array{alias: string, class: string, line: int}|null
     */
    protected function parseUseArguments(string $rawArgs, int $line): ?array
    {
        $args = trim($rawArgs);
        if ($args === '') {
            return null;
        }

        try {
            $stmts = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php dummy(' . $args . ');');
            if (!empty($stmts) && $stmts[0] instanceof \PhpParser\Node\Stmt\Expression) {
                $expr = $stmts[0]->expr;
                if ($expr instanceof \PhpParser\Node\Expr\FuncCall && !empty($expr->args)) {
                    $firstArg = $expr->args[0]->value;
                    $className = null;

                    if ($firstArg instanceof String_) {
                        $className = $firstArg->value;
                    } elseif ($firstArg instanceof \PhpParser\Node\Expr\ClassConstFetch && $firstArg->class instanceof \PhpParser\Node\Name) {
                        $className = $firstArg->class->toString();
                    }

                    if ($className !== null) {
                        $alias = null;
                        if (isset($expr->args[1])) {
                            $secondArg = $expr->args[1]->value;
                            if ($secondArg instanceof String_) {
                                $alias = $secondArg->value;
                            }
                        }

                        $alias ??= class_basename($className);

                        return [
                            'alias' => $alias,
                            'class' => '\\' . ltrim($className, '\\'),
                            'line' => $line,
                        ];
                    }
                }
            }
        } catch (\Throwable) {}

        if (preg_match('/^(?:[\'"]([a-zA-Z0-9_\\\\]+)[\'"]|([a-zA-Z0-9_\\\\]+)::class)(?:\s*,\s*[\'"]([a-zA-Z0-9_]+)[\'"])?$/', $args, $m)) {
            $targetClass = !empty($m[1]) ? $m[1] : $m[2];
            $alias = !empty($m[3]) ? $m[3] : class_basename($targetClass);
            return [
                'alias' => $alias,
                'class' => '\\' . ltrim($targetClass, '\\'),
                'line' => $line,
            ];
        }

        return null;
    }

    public function cleanDocType(string $raw): string
    {
        $clean = preg_replace('/^\s*\*\s?/m', '', $raw);
        $clean = trim((string) $clean);

        if (str_contains($clean, '{')) {
            $clean = preg_replace('/\s+/', ' ', $clean);
            $clean = preg_replace('/\s*:\s*/', ': ', (string) $clean);
            $clean = preg_replace('/\s*,\s*/', ', ', (string) $clean);
            $clean = preg_replace('/\{\s+/', '{', (string) $clean);
            $clean = preg_replace('/\s+\}/', '}', (string) $clean);
        }

        return (string) $clean;
    }

    protected function parseInjectSymbol(string $rawArgs, ?string $source, int $line): ?VariableSymbol
    {
        $args = $this->parseDirectiveCallArguments($rawArgs);

        if (count($args) < 2) {
            return null;
        }

        $varArg = $args[0]->value ?? null;
        $classArg = $args[1]->value ?? null;

        if (!$varArg instanceof String_ || !$classArg instanceof String_) {
            return null;
        }

        $name = $varArg->value;
        $type = '\\' . ltrim($classArg->value, '\\');

        return new VariableSymbol(
            name: $name,
            type: TypeRef::fromString($type),
            origin: new ScopeOrigin('@inject', $source, $line, "Injected Service: {$type}"),
            detail: "Injected Service: {$type}",
            range: SourceRange::line($line),
        );
    }

    protected function parseInlinePhpSymbol(string $rawArgs, ?string $source, int $line): ?VariableSymbol
    {
        $parsed = $this->parseExpression($this->unwrapDirectiveArguments($rawArgs));

        if (!$parsed instanceof Assign || !$parsed->var instanceof Variable || !is_string($parsed->var->name)) {
            return null;
        }

        return new VariableSymbol(
            name: $parsed->var->name,
            type: TypeRef::fromString($this->inferTypeFromExprNode($parsed->expr)),
            origin: new ScopeOrigin('@php', $source, $line, 'Locally defined @php variable'),
            detail: 'Locally defined @php variable',
            range: SourceRange::line($line),
        );
    }

    /**
     * @return array<string, VariableSymbol>
     */
    protected function parsePhpBlockSymbols(string $code, ?string $source, int $startLine): array
    {
        $symbols = [];
        $stmts = $this->parsePhpStatements($code);

        if ($stmts === []) {
            return [];
        }

        $assignments = $this->nodeFinder->find($stmts, fn (Node $node): bool => $node instanceof Assign);

        foreach ($assignments as $assignment) {
            if (!$assignment instanceof Assign || !$assignment->var instanceof Variable || !is_string($assignment->var->name)) {
                continue;
            }

            $line = $this->lineForPhpNode($code, $assignment, $startLine);
            $symbols[$assignment->var->name] = new VariableSymbol(
                name: $assignment->var->name,
                type: TypeRef::fromString($this->inferTypeFromExprNode($assignment->expr)),
                origin: new ScopeOrigin('@php', $source, $line, 'Locally defined @php variable'),
                detail: 'Locally defined @php variable',
                range: SourceRange::line($line),
            );
        }

        return $symbols;
    }

    /**
     * @return array<string, VariableSymbol>
     */
    protected function extractPhpDocSymbols(string $content, ?string $source): array
    {
        $symbols = [];

        try {
            $doc = BladeDocument::fromText($content);

            // 1. Blade comment docblocks {{-- /** @var ... */ --}}
            foreach ($doc->getComments() as $comment) {
                $text = (string) $comment->content;
                $line = $comment->position ? $comment->position->startLine : 1;
                $vars = $this->docBlockParser->extractVarTags($text);
                foreach ($vars as $name => $rawType) {
                    $type = $this->cleanDocType($rawType);
                    $symbols[$name] = new VariableSymbol(
                        name: $name,
                        type: TypeRef::fromString($type),
                        origin: new ScopeOrigin('PHPDoc', $source, $line, "PHPDoc typed: {$type}"),
                        detail: "PHPDoc typed: {$type}",
                        range: SourceRange::line($line),
                    );
                }
            }

            // 2. PHP blocks and tags with docblocks
            $phpSnippets = array_merge(
                $doc->getPhpBlocks()->all(),
                $doc->getPhpTags()->all()
            );

            foreach ($phpSnippets as $block) {
                $code = (string) $block->content;
                $baseLine = $block->position ? $block->position->startLine : 1;
                if (preg_match_all('/\/\*\*[\s\S]*?\*\//', $code, $docMatches, PREG_OFFSET_CAPTURE)) {
                    foreach ($docMatches[0] as $docMatch) {
                        $docText = $docMatch[0];
                        $offset = $docMatch[1];
                        $line = $baseLine + substr_count(substr($code, 0, $offset), "\n");
                        $vars = $this->docBlockParser->extractVarTags($docText);
                        foreach ($vars as $name => $rawType) {
                            $type = $this->cleanDocType($rawType);
                            $symbols[$name] = new VariableSymbol(
                                name: $name,
                                type: TypeRef::fromString($type),
                                origin: new ScopeOrigin('PHPDoc', $source, $line, "PHPDoc typed: {$type}"),
                                detail: "PHPDoc typed: {$type}",
                                range: SourceRange::line($line),
                            );
                        }
                    }
                }
            }
        } catch (Throwable) {}

        // Fallback for standalone inline @var comments
        if (empty($symbols) && preg_match_all('/\/\*\*[\s\S]*?@var\s+([\s\S]+?)\s+\$([a-zA-Z0-9_]+)[\s\S]*?\*\//', $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $docText = $match[0][0];
                $offset = $match[0][1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $vars = $this->docBlockParser->extractVarTags($docText);
                foreach ($vars as $name => $rawType) {
                    $type = $this->cleanDocType($rawType);
                    $symbols[$name] = new VariableSymbol(
                        name: $name,
                        type: TypeRef::fromString($type),
                        origin: new ScopeOrigin('PHPDoc', $source, $line, "PHPDoc typed: {$type}"),
                        detail: "PHPDoc typed: {$type}",
                        range: SourceRange::line($line),
                    );
                }
            }
        }

        return $symbols;
    }

    /**
     * @return list<Arg>
     */
    protected function parseDirectiveCallArguments(string $rawArgs): array
    {
        $expression = '__blade_directive__(' . $this->unwrapDirectiveArguments($rawArgs) . ')';
        $parsed = $this->parseExpression($expression);

        return $parsed instanceof FuncCall ? $parsed->args : [];
    }

    protected function parseExpression(string $expression): ?Expr
    {
        $stmts = $this->parsePhpStatements($expression . ';');
        $stmt = $stmts[0] ?? null;

        return $stmt instanceof Expression ? $stmt->expr : null;
    }

    /**
     * @return list<Node>
     */
    protected function parsePhpStatements(string $code): array
    {
        try {
            $stmts = $this->phpParser->parse('<?php ' . $code, new Collecting());
        } catch (Throwable) {
            return [];
        }

        return $stmts ?? [];
    }

    protected function unwrapDirectiveArguments(string $rawArgs): string
    {
        $rawArgs = trim($rawArgs);

        if (str_starts_with($rawArgs, '(') && str_ends_with($rawArgs, ')')) {
            return trim(substr($rawArgs, 1, -1));
        }

        return $rawArgs;
    }

    protected function inferTypeFromExprNode(Expr $expr): string
    {
        if ($expr instanceof String_) {
            return 'string';
        }

        if ($expr instanceof Int_) {
            return 'int';
        }

        if ($expr instanceof Float_) {
            return 'float';
        }

        if ($expr instanceof ConstFetch) {
            $value = strtolower($expr->name->toString());
            return match ($value) {
                'true', 'false' => 'bool',
                'null' => 'null',
                default => 'mixed',
            };
        }

        if ($expr instanceof Array_) {
            return 'array';
        }

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            return '\\' . ltrim($expr->class->toString(), '\\');
        }

        if ($expr instanceof ArrowFunction || $expr instanceof ClosureExpr) {
            return '\\Closure';
        }

        if ($expr instanceof Match_) {
            $types = [];
            foreach ($expr->arms as $arm) {
                $types[] = TypeRef::fromString($this->inferTypeFromExprNode($arm->body));
            }

            return (string) TypeRef::union($types);
        }

        if ($expr instanceof Ternary) {
            $types = [];
            if ($expr->if !== null) {
                $types[] = TypeRef::fromString($this->inferTypeFromExprNode($expr->if));
            }
            $types[] = TypeRef::fromString($this->inferTypeFromExprNode($expr->else));

            return (string) TypeRef::union($types);
        }

        if ($expr instanceof ClassConstFetch && $expr->name instanceof Identifier && strtolower($expr->name->name) === 'class') {
            $class = $expr->class instanceof Name ? '\\' . ltrim($expr->class->toString(), '\\') : 'object';
            return "class-string<{$class}>";
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            return match ($expr->name->toString()) {
                'now', 'today' => '\\Illuminate\\Support\\Carbon',
                'collect' => '\\Illuminate\\Support\\Collection',
                default => 'mixed',
            };
        }

        return 'mixed';
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

    protected function lineForPhpNode(string $code, Node $node, int $startLine): int
    {
        $offset = $node->getStartFilePos();

        if ($offset < 6) {
            return $startLine;
        }

        return $startLine + substr_count(substr('<?php ' . $code, 0, $offset), "\n");
    }
}
