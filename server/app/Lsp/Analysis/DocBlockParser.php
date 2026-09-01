<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ParenthesizedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser as AstPhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

class DocBlockParser
{
    protected ParserConfig $config;
    protected Lexer $lexer;
    protected AstPhpDocParser $phpDocParser;
    protected TypeParser $typeParser;
    protected ConstExprParser $constExprParser;

    public function __construct()
    {
        $this->config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($this->config);
        $this->constExprParser = new ConstExprParser($this->config);
        $this->typeParser = new TypeParser($this->config, $this->constExprParser);
        $this->phpDocParser = new AstPhpDocParser($this->config, $this->typeParser, $this->constExprParser);
    }

    /**
     * Parse a PHPDoc docblock comment string into a PhpDocNode AST.
     */
    public function parseDocBlock(string $comment): ?PhpDocNode
    {
        $comment = trim($comment);
        if ($comment === '') {
            return null;
        }

        if (!str_starts_with($comment, '/**')) {
            $comment = "/**\n * " . str_replace("\n", "\n * ", $comment) . "\n */";
        }

        try {
            $tokens = $this->lexer->tokenize($comment);
            $tokenIterator = new TokenIterator($tokens);
            return $this->phpDocParser->parse($tokenIterator);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse a standalone type string (e.g. array{ip: string} or Collection<int, User>) into a TypeNode AST.
     */
    public function parseType(string $typeString): ?TypeNode
    {
        $typeString = trim($typeString);
        if ($typeString === '') {
            return null;
        }

        try {
            $tokens = $this->lexer->tokenize($typeString);
            $tokenIterator = new TokenIterator($tokens);
            return $this->typeParser->parse($tokenIterator);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract all @property, @property-read, and @property-write tags.
     *
     * @return array<string, string> Map of propertyName => typeString
     */
    public function extractProperties(string $comment): array
    {
        $docNode = $this->parseDocBlock($comment);
        if (!$docNode) {
            return [];
        }

        $properties = [];
        foreach ($docNode->getPropertyTagValues() as $tag) {
            $varName = ltrim($tag->propertyName, '$');
            $type = (string) $tag->type;
            if ($varName !== '') {
                $properties[$varName] = $type;
            }
        }

        foreach ($docNode->getPropertyReadTagValues() as $tag) {
            $varName = ltrim($tag->propertyName, '$');
            $type = (string) $tag->type;
            if ($varName !== '') {
                $properties[$varName] = $type;
            }
        }

        foreach ($docNode->getPropertyWriteTagValues() as $tag) {
            $varName = ltrim($tag->propertyName, '$');
            $type = (string) $tag->type;
            if ($varName !== '') {
                $properties[$varName] = $type;
            }
        }

        return $properties;
    }

    /**
     * Extract all @method tags.
     *
     * @return array<string, array{name: string, returnType: string, signature: string}>
     */
    public function extractMethods(string $comment): array
    {
        $docNode = $this->parseDocBlock($comment);
        if (!$docNode) {
            return [];
        }

        $methods = [];
        foreach ($docNode->getMethodTagValues() as $tag) {
            $methodName = $tag->methodName;
            $returnType = $tag->returnType ? (string) $tag->returnType : 'mixed';

            $params = [];
            foreach ($tag->parameters as $param) {
                $pStr = '';
                if ($param->type) {
                    $pStr .= ((string) $param->type) . ' ';
                }
                if ($param->isReference) {
                    $pStr .= '&';
                }
                if ($param->isVariadic) {
                    $pStr .= '...';
                }
                $pStr .= $param->parameterName;
                if ($param->defaultValue) {
                    $pStr .= ' = ' . (string) $param->defaultValue;
                }
                $params[] = $pStr;
            }

            $signature = '(' . implode(', ', $params) . '): ' . $returnType;

            $methods[$methodName] = [
                'name' => $methodName,
                'returnType' => $returnType,
                'signature' => $signature,
            ];
        }

        return $methods;
    }

    /**
     * Extract all @mixin tags.
     *
     * @return array<int, string>
     */
    public function extractMixins(string $comment): array
    {
        $docNode = $this->parseDocBlock($comment);
        if (!$docNode) {
            return [];
        }

        $mixins = [];
        foreach ($docNode->getMixinTagValues() as $tag) {
            $type = (string) $tag->type;
            if ($type !== '') {
                $mixins[] = ltrim($type, '\\');
            }
        }

        return $mixins;
    }

    /**
     * Extract all @var tags from docblock comment.
     *
     * @return array<string, string> Map of varName => typeString
     */
    public function extractVarTags(string $comment): array
    {
        $docNode = $this->parseDocBlock($comment);
        if (!$docNode) {
            return [];
        }

        $vars = [];
        foreach ($docNode->getVarTagValues() as $tag) {
            $varName = ltrim($tag->variableName, '$');
            $type = (string) $tag->type;
            if ($varName !== '') {
                $vars[$varName] = $type;
            }
        }

        return $vars;
    }

    /**
     * Extract all @param tags from docblock comment.
     *
     * @return array<string, string> Map of paramName => typeString
     */
    public function extractParamTags(string $comment): array
    {
        $docNode = $this->parseDocBlock($comment);
        if (!$docNode) {
            return [];
        }

        $params = [];
        foreach ($docNode->getParamTagValues() as $tag) {
            $paramName = ltrim($tag->parameterName, '$');
            $type = (string) $tag->type;
            if ($paramName !== '') {
                $params[$paramName] = $type;
            }
        }

        return $params;
    }

    /**
     * Extract keys and value types from typed array shape strings (e.g. array{ip: string, user_agent?: string}).
     *
     * @return array<string, array{name: string, type: string, optional: bool}>
     */
    public function extractArrayShapeKeys(string $typeString): array
    {
        $typeNode = $this->parseType($typeString);
        if (!$typeNode) {
            return [];
        }

        return $this->findArrayShapeKeysFromNode($typeNode);
    }

    /**
     * Recursively locate array shape keys from a TypeNode (including inside union or parenthesized types).
     *
     * @return array<string, array{name: string, type: string, optional: bool}>
     */
    protected function findArrayShapeKeysFromNode(TypeNode $node): array
    {
        if ($node instanceof ArrayShapeNode || $node instanceof ObjectShapeNode) {
            $keys = [];
            foreach ($node->items as $item) {
                $keyName = $item->keyName ? (string) $item->keyName : '';
                // Strip quotes if key was defined as 'key' or "key"
                $keyName = trim($keyName, "'\"");
                $itemType = (string) $item->valueType;

                if ($keyName !== '') {
                    $keys[$keyName] = [
                        'name' => $keyName,
                        'type' => $itemType,
                        'optional' => $item->optional,
                    ];
                }
            }
            return $keys;
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            $keys = [];
            foreach ($node->types as $subType) {
                $subKeys = $this->findArrayShapeKeysFromNode($subType);
                foreach ($subKeys as $k => $v) {
                    $keys[$k] = $v;
                }
            }
            return $keys;
        }

        if ($node instanceof NullableTypeNode || $node instanceof ParenthesizedTypeNode) {
            return $this->findArrayShapeKeysFromNode($node->type);
        }

        return [];
    }

    /**
     * Unwrap inner element type for collections and arrays (e.g. Collection<int, User> -> User, Post[] -> Post).
     */
    public function unwrapItemType(string $typeString): ?string
    {
        $typeNode = $this->parseType($typeString);
        if (!$typeNode) {
            return null;
        }

        // Collection<int, Model> or Collection<Model> or list<Model> or array<string, Model>
        if ($typeNode instanceof GenericTypeNode) {
            $typeCount = count($typeNode->genericTypes);
            if ($typeCount === 1) {
                return (string) $typeNode->genericTypes[0];
            }
            if ($typeCount >= 2) {
                // Return the value type (second type argument)
                return (string) $typeNode->genericTypes[1];
            }
        }

        // Post[] or (Post|Comment)[]
        if (str_ends_with($typeString, '[]')) {
            return substr($typeString, 0, -2);
        }

        return null;
    }

    /**
     * Extract the @return tag type string from a docblock comment.
     */
    public function extractReturnTag(string $comment): ?string
    {
        $docNode = $this->parseDocBlock($comment);
        if (!$docNode) {
            return null;
        }

        $returnTags = $docNode->getReturnTagValues();
        if (!empty($returnTags)) {
            $type = (string) $returnTags[0]->type;

            return $type !== '' ? $type : null;
        }

        return null;
    }
}

