<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Stillat\BladeParser\Document\Document as BladeDocument;
use Throwable;

class BladePhpAstAnalyzer
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
     * Extract all typed PHP AST expression elements from a Blade template.
     *
     * @return array<int, array{
     *     kind: string,
     *     name: string,
     *     rootVar: ?string,
     *     rootCall: ?string,
     *     rootCallArg: ?string,
     *     chain: string,
     *     startOffset: int,
     *     endOffset: int,
     *     startLine: int,
     *     startCol: int,
     *     endCol: int,
     *     isMethod: bool,
     *     isArrayAccess: bool
     * }>
     */
    public function extractAllExpressions(string $content): array
    {
        $expressions = [];
        $lineOffsets = $this->calculateLineOffsets($content);

        try {
            $doc = BladeDocument::fromText($content);
            $snippets = [];

            // 1. Blade Echo nodes: {{ $var->prop }}, {!! $var->prop !!}
            foreach ($doc->getEchoes() as $echo) {
                if ($echo->position) {
                    $prefixLen = str_starts_with($echo->content, '{!!') ? 3 : 2;
                    $startChar = $echo->position->startOffset;
                    $startByte = strlen(mb_substr($content, 0, $startChar));
                    $snippets[] = [
                        'code' => $echo->innerContent,
                        'offset' => $startByte + $prefixLen,
                    ];
                }
            }

            // 2. Directives with arguments: @if(...), @foreach(...), @php(...), @js(...), @json(...)
            foreach ($doc->getDirectives() as $directive) {
                if ($directive->arguments && $directive->position) {
                    $args = trim((string) $directive->arguments);
                    if (str_starts_with($args, '(') && str_ends_with($args, ')')) {
                        $args = substr($args, 1, -1);
                    }
                    $startChar = $directive->arguments->position
                        ? $directive->arguments->position->startOffset + 1
                        : $directive->position->startOffset + strlen((string) $directive->content) + 2;
                    $startByte = strlen(mb_substr($content, 0, $startChar));
                    $snippets[] = [
                        'code' => $args,
                        'offset' => $startByte,
                    ];
                }
            }

            // 3. PHP Blocks: @php ... @endphp
            foreach ($doc->getPhpBlocks() as $block) {
                if ($block->position) {
                    $startByte = strlen(mb_substr($content, 0, $block->position->startOffset));
                    $snippets[] = [
                        'code' => $block->innerContent,
                        'offset' => $startByte + 4,
                    ];
                }
            }

            // 4. Raw PHP Tags
            foreach ($doc->getPhpTags() as $tag) {
                if ($tag->position) {
                    $startByte = strlen(mb_substr($content, 0, $tag->position->startOffset));
                    $snippets[] = [
                        'code' => $tag->innerContent,
                        'offset' => $startByte + 5,
                    ];
                }
            }

            // 5. Bound HTML and Component Attributes: :title="$user->email", :user="$user"
            if (preg_match_all('/(?::|v-bind:|wire:bind:)([a-zA-Z0-9_:-]+)=([\'"])([^\'"]+)\2/i', $content, $attrMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($attrMatches[3] as $attrMatch) {
                    $attrCode = trim($attrMatch[0]);
                    $attrOffset = $attrMatch[1];
                    if ($attrCode !== '' && !str_starts_with($attrCode, '{{')) {
                        $snippets[] = [
                            'code' => $attrCode,
                            'offset' => $attrOffset,
                        ];
                    }
                }
            }

            // Parse every extracted PHP snippet into AST
            foreach ($snippets as $s) {
                $code = $s['code'];
                $baseOffset = $s['offset'];

                if (trim($code) === '') {
                    continue;
                }

                $errorHandler = new Collecting();
                $stmts = $this->phpParser->parse('<?php ' . $code . ';', $errorHandler);
                if (!$stmts) {
                    continue;
                }

                $this->traverseAndCollectAstNodes($stmts, $baseOffset, $content, $lineOffsets, $expressions);
            }
        } catch (Throwable) {
            // Silently fallback if blade syntax is mid-edit
        }

        return $expressions;
    }

    /**
     * Find expression element at specific line & character (0-indexed).
     *
     * @return array<string, mixed>|null
     */
    public function findExpressionAtPosition(string $content, int $line, int $character): ?array
    {
        $expressions = $this->extractAllExpressions($content);

        foreach ($expressions as $expr) {
            $exprLine = (int) $expr['startLine'];
            $startCol = (int) $expr['startCol'];
            $endCol = (int) $expr['endCol'];

            if ($exprLine === $line && $character >= $startCol && $character <= $endCol) {
                return $expr;
            }
        }

        return null;
    }

    /**
     * Traverse PHP AST statements and collect all member fetches, method calls, and array access nodes.
     */
    protected function traverseAndCollectAstNodes(
        array $stmts,
        int $baseOffset,
        string $fullContent,
        array $lineOffsets,
        array &$results
    ): void {
        $nodes = $this->nodeFinder->find($stmts, function (Node $node) {
            return $node instanceof PropertyFetch
                || $node instanceof NullsafePropertyFetch
                || $node instanceof MethodCall
                || $node instanceof NullsafeMethodCall
                || $node instanceof StaticCall
                || $node instanceof ClassConstFetch
                || $node instanceof ArrayDimFetch
                || $node instanceof FuncCall
                || $node instanceof Variable;
        });

        foreach ($nodes as $node) {
            $kind = 'variable';
            $name = '';
            $isMethod = false;
            $isArrayAccess = false;
            $nameNode = null;

            if ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
                $kind = 'property';
                if ($node->name instanceof Identifier) {
                    $name = $node->name->name;
                    $nameNode = $node->name;
                }
            } elseif ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
                $kind = 'method';
                $isMethod = true;
                if ($node->name instanceof Identifier) {
                    $name = $node->name->name;
                    $nameNode = $node->name;
                }
            } elseif ($node instanceof StaticCall) {
                $kind = 'static_method';
                $isMethod = true;
                if ($node->name instanceof Identifier) {
                    $name = $node->name->name;
                    $nameNode = $node->name;
                }
            } elseif ($node instanceof ClassConstFetch) {
                $kind = 'class_const';
                if ($node->name instanceof Identifier) {
                    $name = $node->name->name;
                    $nameNode = $node->name;
                }
            } elseif ($node instanceof ArrayDimFetch) {
                $kind = 'array_dim';
                $isArrayAccess = true;
                if ($node->dim instanceof String_) {
                    $name = $node->dim->value;
                    $nameNode = $node->dim;
                }
            } elseif ($node instanceof FuncCall) {
                $kind = 'func_call';
                $isMethod = true;
                if ($node->name instanceof Name) {
                    $name = $node->name->toString();
                    $nameNode = $node->name;
                }
            } elseif ($node instanceof Variable) {
                $kind = 'variable';
                if (is_string($node->name)) {
                    $name = $node->name;
                    $nameNode = $node;
                }
            }

            if ($name === '' || !$nameNode) {
                continue;
            }

            $startOffset = $baseOffset + $nameNode->getStartFilePos() - 6;
            $endOffset = $baseOffset + $nameNode->getEndFilePos() - 6;

            // Resolve target root variable/call and chain
            $chainData = $this->resolveExpressionChain($node);

            $loc = $this->offsetToLineAndCol($startOffset, $lineOffsets, $fullContent);
            $endLoc = $this->offsetToLineAndCol($endOffset, $lineOffsets, $fullContent);

            $results[] = [
                'kind' => $kind,
                'name' => $name,
                'rootVar' => $chainData['rootVar'],
                'rootCall' => $chainData['rootCall'],
                'rootCallArg' => $chainData['rootCallArg'],
                'chain' => $chainData['chain'],
                'startOffset' => $startOffset,
                'endOffset' => $endOffset,
                'startLine' => $loc['line'],
                'startCol' => $loc['col'],
                'endCol' => $endLoc['col'] + 1,
                'isMethod' => $isMethod,
                'isArrayAccess' => $isArrayAccess,
                'argCount' => property_exists($node, 'args') && is_array($node->args) ? count($node->args) : null,
            ];
        }
    }

    /**
     * Trace back the AST parent expression chain to find the root variable/call and the accumulated access chain.
     *
     * @return array{rootVar: ?string, rootCall: ?string, rootCallArg: ?string, chain: string}
     */
    protected function resolveExpressionChain(Node $node): array
    {
        $rootVar = null;
        $rootCall = null;
        $rootCallArg = null;
        $segments = [];

        $current = $node;
        if ($current instanceof PropertyFetch || $current instanceof NullsafePropertyFetch || $current instanceof MethodCall || $current instanceof NullsafeMethodCall || $current instanceof ArrayDimFetch) {
            $current = $current->var;
        } elseif ($current instanceof StaticCall) {
            if ($current->class instanceof Name) {
                $rootCall = 'class';
                $rootCallArg = $current->class->toString();
            }
            $current = null;
        } elseif ($current instanceof ClassConstFetch) {
            if ($current->class instanceof Name) {
                $rootCall = 'class';
                $rootCallArg = $current->class->toString();
            }
            $current = null;
        } elseif ($current instanceof FuncCall) {
            if ($current->name instanceof Name) {
                $rootCall = $current->name->toString();
                if (!empty($current->args) && isset($current->args[0])) {
                    $arg = $current->args[0];
                    $argVal = $arg instanceof Arg ? $arg->value : null;
                    if ($argVal instanceof String_) {
                        $rootCallArg = $argVal->value;
                    } elseif ($argVal instanceof ClassConstFetch && $argVal->class instanceof Name) {
                        $rootCallArg = $argVal->class->toString();
                    }
                }
            }
            $current = null;
        }

        while ($current !== null) {
            if ($current instanceof PropertyFetch) {
                $prop = $current->name instanceof Identifier ? $current->name->name : '';
                array_unshift($segments, "->{$prop}");
                $current = $current->var;
            } elseif ($current instanceof NullsafePropertyFetch) {
                $prop = $current->name instanceof Identifier ? $current->name->name : '';
                array_unshift($segments, "?->{$prop}");
                $current = $current->var;
            } elseif ($current instanceof MethodCall) {
                $method = $current->name instanceof Identifier ? $current->name->name : '';
                array_unshift($segments, "->{$method}()");
                $current = $current->var;
            } elseif ($current instanceof NullsafeMethodCall) {
                $method = $current->name instanceof Identifier ? $current->name->name : '';
                array_unshift($segments, "?->{$method}()");
                $current = $current->var;
            } elseif ($current instanceof StaticCall) {
                $method = $current->name instanceof Identifier ? $current->name->name : '';
                array_unshift($segments, "::{$method}()");
                if ($current->class instanceof Name) {
                    $rootCall = 'class';
                    $rootCallArg = $current->class->toString();
                }
                break;
            } elseif ($current instanceof ArrayDimFetch) {
                $key = $current->dim instanceof String_ ? $current->dim->value : '';
                array_unshift($segments, "['{$key}']");
                $current = $current->var;
            } elseif ($current instanceof Variable && is_string($current->name)) {
                $rootVar = $current->name;
                break;
            } elseif ($current instanceof FuncCall && $current->name instanceof Name) {
                $rootCall = $current->name->toString();
                if (!empty($current->args) && isset($current->args[0])) {
                    $arg = $current->args[0];
                    $argVal = $arg instanceof Arg ? $arg->value : null;
                    if ($argVal instanceof String_) {
                        $rootCallArg = $argVal->value;
                    } elseif ($argVal instanceof ClassConstFetch && $argVal->class instanceof Name) {
                        $rootCallArg = $argVal->class->toString();
                    }
                }
                break;
            } else {
                break;
            }
        }

        return [
            'rootVar' => $rootVar,
            'rootCall' => $rootCall,
            'rootCallArg' => $rootCallArg,
            'chain' => implode('', $segments),
        ];
    }

    /**
     * Compute array of line start offsets for fast offset-to-position mapping.
     */
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

    /**
     * Convert absolute file byte offset to 0-indexed line and UTF-16 character column.
     *
     * @return array{line: int, col: int}
     */
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
