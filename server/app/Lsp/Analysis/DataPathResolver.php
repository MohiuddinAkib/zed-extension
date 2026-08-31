<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use Illuminate\Container\Container;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

class DataPathResolver
{
    protected ?Parser $phpParser = null;

    public function __construct(
        protected ?Project $project = null,
        protected ?DocBlockParser $docBlockParser = null,
        protected ?BladeScopeResolver $scopeResolver = null,
        protected ?BladeAstAnalyzer $bladeAnalyzer = null,
        protected ?FunctionTypeResolver $functionTypeResolver = null,
        protected ?SemanticIndex $semanticIndex = null,
    ) {
        $this->docBlockParser ??= new DocBlockParser();
        $this->functionTypeResolver ??= new FunctionTypeResolver($this->project);
        $this->bladeAnalyzer ??= new BladeAstAnalyzer($this->project, $this->functionTypeResolver);
        $this->scopeResolver ??= $this->project !== null ? new BladeScopeResolver($this->project, $this->bladeAnalyzer) : null;
        $this->semanticIndex ??= $this->resolveSemanticIndex();
    }

    protected function resolveSemanticIndex(): ?SemanticIndex
    {
        if ($this->project === null) {
            return null;
        }

        try {
            $container = Container::getInstance();
            if ($container->bound(SemanticIndex::class)) {
                return $container->make(SemanticIndex::class);
            }

            return new SemanticIndex($this->project);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve available segment keys for a given TypeRef.
     *
     * @return array<string, array{name: string, type: TypeRef, isOptional: bool}>
     */
    public function resolveKeysForType(TypeRef $typeRef): array
    {
        $keys = [];

        // 1. Array shape or Object shape
        if ($typeRef->isShape()) {
            foreach ($typeRef->shape as $name => $memberType) {
                $keys[$name] = [
                    'name'       => $name,
                    'type'       => $memberType,
                    'isOptional' => $memberType->nullable,
                ];
            }

            return $keys;
        }

        // 2. Union types
        if ($typeRef->isUnion()) {
            foreach ($typeRef->children as $child) {
                if ($child->nullable || ($child->kind === 'scalar' && $child->displayName === 'null')) {
                    continue;
                }
                foreach ($this->resolveKeysForType($child) as $k => $v) {
                    $keys[$k] = $v;
                }
            }

            return $keys;
        }

        // 3. Generic types (Fluent<...>, Collection<...>, list<...>, array<...>)
        if ($typeRef->isGeneric()) {
            $base = preg_replace('/<.*>$/', '', $typeRef->displayName);
            $cleanBase = ltrim($base, '\\');

            if ($cleanBase === 'Illuminate\Support\Fluent' && !empty($typeRef->children)) {
                return $this->resolveKeysForType($typeRef->children[0]);
            }

            if (in_array($cleanBase, ['Illuminate\Support\Collection', 'Collection', 'Illuminate\Support\LazyCollection', 'LazyCollection', 'array', 'list'], true) && !empty($typeRef->children)) {
                $itemType = count($typeRef->children) > 1 ? $typeRef->children[1] : $typeRef->children[0];
                return $this->resolveKeysForType($itemType);
            }
        }

        // 4. Named Class or Model
        $className = ltrim($typeRef->displayName, '\\');
        $cleanClass = preg_replace('/<.*>$/', '', $className);

        // Check if Eloquent Model
        if ($this->semanticIndex !== null) {
            $models = $this->semanticIndex->models();
            if (isset($models[$cleanClass])) {
                $modelData = $models[$cleanClass];
                foreach ($modelData['attributes'] ?? [] as $attr) {
                    $attrName = $attr['name'] ?? '';
                    if ($attrName !== '') {
                        $attrType = $attr['cast'] ?? $attr['type'] ?? 'mixed';
                        $keys[$attrName] = [
                            'name'       => $attrName,
                            'type'       => TypeRef::fromString($attrType),
                            'isOptional' => false,
                        ];
                    }
                }
                foreach ($modelData['relations'] ?? [] as $rel) {
                    $relName = $rel['name'] ?? '';
                    if ($relName !== '') {
                        $relRelated = $rel['related'] ?? 'Model';
                        $keys[$relName] = [
                            'name'       => $relName,
                            'type'       => TypeRef::fromString('\\' . ltrim($relRelated, '\\')),
                            'isOptional' => false,
                        ];
                    }
                }

                if (!empty($keys)) {
                    return $keys;
                }
            }
        }

        // Reflection & DocBlocks
        if (class_exists($cleanClass) || interface_exists($cleanClass)) {
            try {
                $ref = new ReflectionClass($cleanClass);
                foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                    $pName = $prop->getName();
                    $pTypeStr = $prop->hasType() ? (string) $prop->getType() : 'mixed';
                    $keys[$pName] = [
                        'name'       => $pName,
                        'type'       => TypeRef::fromString($pTypeStr),
                        'isOptional' => false,
                    ];
                }

                if ($doc = $ref->getDocComment()) {
                    $docProps = $this->docBlockParser->extractProperties($doc);
                    foreach ($docProps as $pName => $pTypeStr) {
                        $keys[$pName] = [
                            'name'       => $pName,
                            'type'       => TypeRef::fromString($pTypeStr),
                            'isOptional' => false,
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        return $keys;
    }

    /**
     * Traverse nested path segments along a TypeRef.
     *
     * @param  array<int, string>  $segments
     */
    public function traversePath(TypeRef $rootType, array $segments): ?TypeRef
    {
        $current = $rootType;

        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                return null;
            }

            // Wildcard traversal on collection/list
            if ($seg === '*') {
                if ($current->isGeneric() && !empty($current->children)) {
                    $current = count($current->children) > 1 ? $current->children[1] : $current->children[0];
                    continue;
                }

                return null;
            }

            $keys = $this->resolveKeysForType($current);
            if (!isset($keys[$seg])) {
                return null;
            }

            $current = $keys[$seg]['type'];
        }

        return $current;
    }

    /**
     * Generate segment-aware completion items for a data-path.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCompletionsForPath(
        TypeRef $rootType,
        string $pathBeforeCursor,
        string $pathAfterCursor,
        int $lineNumber,
        int $stringStartChar,
    ): array {
        $lastDotPos = strrpos($pathBeforeCursor, '.');
        if ($lastDotPos !== false) {
            $prefixPath = substr($pathBeforeCursor, 0, $lastDotPos);
            $segmentPrefix = substr($pathBeforeCursor, $lastDotPos + 1);
            $segmentStartOffset = $lastDotPos + 1;
            $prefixSegments = explode('.', $prefixPath);
        } else {
            $prefixPath = '';
            $segmentPrefix = $pathBeforeCursor;
            $segmentStartOffset = 0;
            $prefixSegments = [];
        }

        // Validate segments for malformed paths like 'profile..name'
        foreach ($prefixSegments as $seg) {
            if (trim($seg) === '') {
                return [];
            }
        }

        // Traverse prefix segments
        $targetType = empty($prefixSegments) ? $rootType : $this->traversePath($rootType, $prefixSegments);
        if ($targetType === null) {
            return [];
        }

        $availableKeys = $this->resolveKeysForType($targetType);
        if (empty($availableKeys)) {
            return [];
        }

        // Extract segment suffix (characters after cursor belonging to current segment)
        preg_match('/^[a-zA-Z0-9_\-]*/', $pathAfterCursor, $suffixMatch);
        $segmentSuffix = $suffixMatch[0] ?? '';

        $segmentStartChar = $stringStartChar + Utf16Position::length(substr($pathBeforeCursor, 0, $segmentStartOffset));
        $segmentEndChar = $stringStartChar + Utf16Position::length($pathBeforeCursor) + Utf16Position::length($segmentSuffix);

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $segmentStartChar],
            'end'   => ['line' => $lineNumber, 'character' => $segmentEndChar],
        ];

        $completions = [];
        $lowSegPrefix = strtolower($segmentPrefix);

        foreach ($availableKeys as $keyName => $info) {
            if ($lowSegPrefix !== '' && !str_starts_with(strtolower($keyName), $lowSegPrefix)) {
                continue;
            }

            $valType = $info['type'];
            $isOpt = $info['isOptional'];
            $fullPath = $prefixPath !== '' ? "{$prefixPath}.{$keyName}" : $keyName;
            $detail = $valType->displayName . ($isOpt ? ' (optional)' : '');

            $doc = "**`{$fullPath}`**\n\n*Type:* `{$valType->displayName}`" . ($isOpt ? "\n\n*Optional Key*" : '');

            $completions[] = [
                'label'         => $keyName,
                'kind'          => 10, // Property
                'detail'        => $detail,
                'documentation' => [
                    'kind'  => 'markdown',
                    'value' => $doc,
                ],
                'textEdit' => [
                    'range'   => $range,
                    'newText' => $keyName,
                ],
                'filterText' => $keyName,
                'sortText'   => ($isOpt ? '1_' : '0_') . $keyName,
            ];
        }

        return $completions;
    }

    /**
     * Infer the TypeRef of an expression code string in the context of a document.
     */
    public function inferExpressionType(string $exprCode, Document $document, ?array $position = null): ?TypeRef
    {
        $exprCode = trim($exprCode);
        if ($exprCode === '') {
            return null;
        }

        // 1. If it's a variable: $var
        if (preg_match('/^\$([a-zA-Z0-9_]+)$/', $exprCode, $m)) {
            $varName = $m[1];
            return $this->inferVariableType($varName, $document, $position);
        }

        // 2. If it's an inline array literal: [ ... ]
        if (str_starts_with($exprCode, '[') && str_ends_with($exprCode, ']')) {
            return $this->inferInlineArrayType($exprCode, $document, $position);
        }

        // 3. If it's a fluent call: fluent($inner)
        if (preg_match('/^fluent\s*\((.+)\)$/s', $exprCode, $m)) {
            return $this->inferExpressionType($m[1], $document, $position);
        }

        // 4. Try parsing expression with PhpParser and BladeAstAnalyzer
        try {
            $this->phpParser ??= (new ParserFactory())->createForHostVersion();
            $stmts = $this->phpParser->parse('<?php $__temp = ' . $exprCode . ';');
            if ($stmts && isset($stmts[0]) && $stmts[0] instanceof Expression && $stmts[0]->expr instanceof Assign) {
                $typeStr = $this->bladeAnalyzer->inferTypeFromExprNode($stmts[0]->expr->expr);
                if ($typeStr && $typeStr !== 'mixed') {
                    return TypeRef::fromString($typeStr);
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Infer the TypeRef of a variable in the context of a document.
     */
    public function inferVariableType(string $varName, Document $document, ?array $position = null): ?TypeRef
    {
        // A. In Blade files: query BladeScopeResolver
        if (str_ends_with($document->uri, '.blade.php') && $this->scopeResolver !== null) {
            $line = $position['line'] ?? 0;
            $char = $position['character'] ?? 0;
            $viewKey = $this->resolveViewKey($document->uri);
            $scope = $this->scopeResolver->resolveAtPosition($document, (int) $line, (int) $char, $viewKey);
            if (isset($scope->variables[$varName])) {
                $type = $scope->variables[$varName]->type;
                if ($type && (string) $type !== 'mixed') {
                    return $type;
                }
            }
        }

        // B. Check docblocks (@var, @param) in document content
        $docType = $this->findDocBlockTypeForVariable($varName, $document, $position);
        if ($docType !== null) {
            return $docType;
        }

        // C. Check preceding assignments: $varName = <expr>
        $assignedType = $this->findAssignedTypeForVariable($varName, $document, $position);
        if ($assignedType !== null) {
            return $assignedType;
        }

        return null;
    }

    /**
     * Infer TypeRef from an inline array literal string.
     */
    public function inferInlineArrayType(string $arrayCode, Document $document, ?array $position = null): ?TypeRef
    {
        try {
            $this->phpParser ??= (new ParserFactory())->createForHostVersion();
            $stmts = $this->phpParser->parse('<?php $__temp = ' . $arrayCode . ';');
            if ($stmts && isset($stmts[0]) && $stmts[0] instanceof Expression && $stmts[0]->expr instanceof Assign) {
                $rhs = $stmts[0]->expr->expr;
                if ($rhs instanceof Array_) {
                    $typeStr = $this->bladeAnalyzer->inferTypeFromExprNode($rhs);
                    if ($typeStr && $typeStr !== 'mixed') {
                        return TypeRef::fromString($typeStr);
                    }
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Locate docblock comments defining variable types.
     */
    protected function findDocBlockTypeForVariable(string $varName, Document $document, ?array $position = null): ?TypeRef
    {
        $content = $document->content;

        if (preg_match_all('/\/\*\*[\s\S]*?\*\//', $content, $matches)) {
            foreach (array_reverse($matches[0]) as $docComment) {
                $varTags = $this->docBlockParser->extractVarTags($docComment);
                if (isset($varTags[$varName])) {
                    return TypeRef::fromString($varTags[$varName]);
                }
                if (isset($varTags['']) && count($varTags) === 1) {
                    return TypeRef::fromString($varTags['']);
                }

                $paramTags = $this->docBlockParser->extractParamTags($docComment);
                if (isset($paramTags[$varName])) {
                    return TypeRef::fromString($paramTags[$varName]);
                }
            }
        }

        return null;
    }

    /**
     * Locate preceding assignment statements to a variable and infer their type.
     */
    protected function findAssignedTypeForVariable(string $varName, Document $document, ?array $position = null): ?TypeRef
    {
        $lines = explode("\n", $document->content);
        $maxLine = is_array($position) && is_int($position['line'] ?? null) ? $position['line'] : count($lines);

        $linesToSearch = array_slice($lines, 0, $maxLine + 1);
        $codeToSearch = implode("\n", $linesToSearch);

        try {
            $this->phpParser ??= (new ParserFactory())->createForHostVersion();
            $code = str_starts_with(trim($codeToSearch), '<?php') ? $codeToSearch : '<?php ' . $codeToSearch . ';';
            $stmts = $this->phpParser->parse($code);
            if ($stmts) {
                // Find all assignments to $varName
                $lastRhs = null;
                $this->findLastAssignment($stmts, $varName, $lastRhs);
                if ($lastRhs !== null) {
                    $typeStr = $this->bladeAnalyzer->inferTypeFromExprNode($lastRhs);
                    if ($typeStr && $typeStr !== 'mixed') {
                        return TypeRef::fromString($typeStr);
                    }
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    protected function findLastAssignment(array $nodes, string $varName, ?Node &$lastRhs): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Assign && $node->var instanceof Variable && $node->var->name === $varName) {
                $lastRhs = $node->expr;
            }
            if ($node instanceof Expression && $node->expr instanceof Assign && $node->expr->var instanceof Variable && $node->expr->var->name === $varName) {
                $lastRhs = $node->expr->expr;
            }
            if ($node instanceof Node) {
                $subNodes = [];
                foreach ($node->getSubNodeNames() as $subName) {
                    $sub = $node->$subName;
                    if (is_array($sub)) {
                        $subNodes = array_merge($subNodes, $sub);
                    } elseif ($sub instanceof Node) {
                        $subNodes[] = $sub;
                    }
                }
                if (!empty($subNodes)) {
                    $this->findLastAssignment($subNodes, $varName, $lastRhs);
                }
            }
        }
    }

    /**
     * Resolve the dot-notation view key from the file URI.
     */
    protected function resolveViewKey(string $uri): string
    {
        $path = str_replace('\\', '/', $uri);

        try {
            if ($this->project !== null) {
                $views = $this->project->index->views();
                $matched = $views->first(function ($view) use ($path) {
                    $viewPath = str_replace('\\', '/', $view['path'] ?? '');
                    return $viewPath !== '' && str_ends_with($path, $viewPath);
                });
                if ($matched && !empty($matched['key'])) {
                    return $matched['key'];
                }
            }
        } catch (Throwable) {
        }

        if (preg_match('/resources\/views\/vendor\/([^\/]+)\/(.+)\.blade\.php$/', $path, $matches)) {
            return "{$matches[1]}::" . str_replace('/', '.', $matches[2]);
        }

        if (preg_match('/resources\/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return basename($path, '.blade.php');
    }
}
