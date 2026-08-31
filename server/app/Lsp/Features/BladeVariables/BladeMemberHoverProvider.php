<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladePhpAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\DocBlockParser;
use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Features\AppBindings\AppBindingContainerTypeMap;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

class BladeMemberHoverProvider implements HoverProvider
{
    protected BladeAstAnalyzer $bladeAnalyzer;
    protected BladePhpAstAnalyzer $bladePhpAstAnalyzer;
    protected BladeScopeResolver $scopeResolver;
    protected DocBlockParser $docBlockParser;
    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
    ) {
        $this->bladeAnalyzer = new BladeAstAnalyzer();
        $this->bladePhpAstAnalyzer = new BladePhpAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
        $this->docBlockParser = new DocBlockParser();
    }

    /**
     * Provide hover information for object members and array keys in Blade templates.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function get(Document $document, array $position): ?array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return null;
        }

        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $this->ensureAutoloaderRegistered();

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';

        $astExpr = $this->bladePhpAstAnalyzer->findExpressionAtPosition($document->content, $lineNumber, $character);
        if ($astExpr !== null && $astExpr['kind'] !== 'variable') {
            $varName = $astExpr['rootVar'] ?? '';
            $rootCall = $astExpr['rootCall'] ?? null;
            $rootCallArg = $astExpr['rootCallArg'] ?? null;
            $chain = $astExpr['chain'];
            $memberName = $astExpr['name'];
            $isArrayAccess = $astExpr['isArrayAccess'];
            $range = [
                'start' => ['line' => $lineNumber, 'character' => $astExpr['startCol']],
                'end' => ['line' => $lineNumber, 'character' => $astExpr['endCol']],
            ];
        } else {
            $fallback = $this->findMemberAtPosition($line, $character);
            if ($fallback === null) {
                return $this->findClassTokenHover($document, $line, $character, $lineNumber);
            }
            $varName = $fallback['varName'] ?? '';
            $rootCall = $fallback['rootCall'] ?? null;
            $rootCallArg = $fallback['rootCallArg'] ?? null;
            $chain = $fallback['chain'];
            $memberName = $fallback['memberName'];
            $isArrayAccess = $fallback['isArrayAccess'];
            $range = [
                'start' => ['line' => $lineNumber, 'character' => $fallback['start']],
                'end' => ['line' => $lineNumber, 'character' => $fallback['end']],
            ];
        }

        $viewKey = $this->resolveViewKey($document->uri);
        $variables = $this->collectVariablesForView($document, $viewKey, $position);

        $rootType = 'mixed';
        $accessorType = null;
        $varSource = null;
        $varLine = null;

        if ($rootCall === 'class') {
            $rootClass = $rootCallArg ?: $varName;
            $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
            if (isset($importedUses[$rootClass])) {
                $rootType = $importedUses[$rootClass]['class'];
            } elseif (\App\Lsp\Features\Facades\FacadeMap::isFacadeOrAlias($rootClass)) {
                $rootType = \App\Lsp\Features\Facades\FacadeMap::resolve($rootClass);
                $accessorType = \App\Lsp\Features\Facades\FacadeMap::resolveAccessor($rootClass);
            } else {
                $rootType = '\\' . ltrim($rootClass, '\\');
            }
        } elseif ($rootCall !== null) {
            $rootType = match ($rootCall) {
                'app', 'resolve' => $rootCallArg ? AppBindingContainerTypeMap::resolveType($rootCallArg) : '\Illuminate\Foundation\Application',
                'auth' => '\Illuminate\Auth\AuthManager',
                'request' => '\Illuminate\Http\Request',
                'session' => '\Illuminate\Session\SessionManager',
                'now', 'today' => '\Illuminate\Support\Carbon',
                default => null,
            };
        } elseif ($varName !== '' && isset($variables[$varName])) {
            $rootType = $variables[$varName]['type'] ?? 'mixed';
            $rootType = $this->qualifyType($rootType, $document);
            $varSource = $variables[$varName]['source'] ?? null;
            $varLine = $variables[$varName]['line'] ?? null;
        }

        if (!$rootType || $rootType === 'mixed') {
            $classHover = $this->findClassTokenHover($document, $line, $character, $lineNumber);
            if ($classHover !== null) {
                return $classHover;
            }
            return null;
        }

        $targetType = $this->resolveChainedType($rootType, $chain);
        if (!$targetType || $targetType === 'mixed') {
            return null;
        }

        $memberData = $this->resolveMemberDetails($targetType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), $varSource, $varLine);
        if ($memberData === null && $accessorType !== null && $accessorType !== $targetType) {
            $memberData = $this->resolveMemberDetails($accessorType, $memberName, $isArrayAccess, $varName ?: ($rootCallArg ?? ($rootCall ?? 'expr')), $varSource, $varLine);
        }

        if ($memberData === null) {
            $classHover = $this->findClassTokenHover($document, $line, $character, $lineNumber);
            if ($classHover !== null) {
                return $classHover;
            }
            return null;
        }

        $title = $memberData['title'];
        $type = $memberData['type'];
        $origin = $memberData['origin'];
        $doc = $memberData['documentation'] ?? '';
        $isMethod = $memberData['isMethod'] ?? false;
        $signature = $memberData['signature'] ?? '';
        $source = $memberData['source'] ?? null;
        $sourceLine = $memberData['line'] ?? null;

        // Build syntax highlighted PHP block
        $phpCode = "<?php\n";
        if ($isMethod) {
            $phpCode .= "public function {$memberName}{$signature};";
        } elseif ($isArrayAccess) {
            $phpCode .= "{$type} \${$memberName};";
        } else {
            $phpCode .= "public {$type} \${$memberName};";
        }

        $markdown = "**{$title}**\n\n"
            . "```php\n{$phpCode}\n```\n\n";

        if (!$isMethod) {
            if ($isArrayAccess) {
                $markdown .= "`@var {$type} \${$varName}['{$memberName}']`\n\n";
            } else {
                $markdown .= "`@var {$type} \${$memberName}`\n\n";
            }
        }

        $markdown .= "*Origin:* `{$origin}`  \n";

        if (!empty($source)) {
            $basePath = rtrim($this->project->path(), '/\\');
            $sourceStr = is_array($source) ? implode(', ', $source) : (string) $source;
            if (!empty($sourceLine) && !is_array($source)) {
                $absPath = str_starts_with($source, '/') ? $source : "{$basePath}/{$source}";
                $fileUri = FileUri::fromPath($absPath);
                $markdown .= "*Source:* [{$source}:{$sourceLine}]({$fileUri}#L{$sourceLine})\n";
            } else {
                $markdown .= "*Source:* `{$sourceStr}`\n";
            }
        } elseif ($doc !== '') {
            $markdown .= "\n{$doc}\n";
        }

        return [
            'range' => $range,
            'contents' => [
                'kind' => 'markdown',
                'value' => trim($markdown),
            ],
        ];
    }

    /**
     * Find member or array key under cursor across any chain depth.
     *
     * @return array{varName: string, rootCall: ?string, rootCallArg: ?string, chain: string, memberName: string, isArrayAccess: bool, start: int, end: int}|null
     */
    protected function findMemberAtPosition(string $line, int $character): ?array
    {
        // 1. Match app('binding') or resolve('binding') chained calls
        if (preg_match_all('/(?:app|resolve)\s*\(\s*([\'"][a-zA-Z0-9_.\/\\\\-]+[\'"]|[a-zA-Z0-9_\\\\]+::class)\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?|\[[^\]]*\])+)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawBinding = $m[1][0];
                $bindingKey = str_ends_with($rawBinding, '::class') ? substr($rawBinding, 0, -7) : trim($rawBinding, '\'"');
                $fullChain = $m[2][0];
                $chainOffset = $m[2][1];

                if (preg_match_all('/(?:(->|\?->)([a-zA-Z0-9_]+)|\[\s*([\'"]?)([a-zA-Z0-9_]+)\3\s*\])/', $fullChain, $segments, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    $accumulatedChain = '';
                    foreach ($segments as $seg) {
                        $isObject = !empty($seg[1][0]);
                        $memberName = $isObject ? $seg[2][0] : $seg[4][0];
                        $memberOffset = $chainOffset + ($isObject ? $seg[2][1] : $seg[4][1]);
                        $memberEnd = $memberOffset + strlen($memberName);

                        if ($character >= $memberOffset && $character <= $memberEnd) {
                            return [
                                'varName' => '',
                                'rootCall' => 'app',
                                'rootCallArg' => $bindingKey,
                                'chain' => $accumulatedChain,
                                'memberName' => $memberName,
                                'isArrayAccess' => !$isObject,
                                'start' => $memberOffset,
                                'end' => $memberEnd,
                            ];
                        }

                        $accumulatedChain .= $seg[0][0];
                    }
                }
            }
        }

        // 2. Match helpers (auth(), request(), session(), now(), today())
        if (preg_match_all('/(auth|request|session|now|today)\s*\(\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?|\[[^\]]*\])+)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $helperName = $m[1][0];
                $fullChain = $m[2][0];
                $chainOffset = $m[2][1];

                if (preg_match_all('/(?:(->|\?->)([a-zA-Z0-9_]+)|\[\s*([\'"]?)([a-zA-Z0-9_]+)\3\s*\])/', $fullChain, $segments, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    $accumulatedChain = '';
                    foreach ($segments as $seg) {
                        $isObject = !empty($seg[1][0]);
                        $memberName = $isObject ? $seg[2][0] : $seg[4][0];
                        $memberOffset = $chainOffset + ($isObject ? $seg[2][1] : $seg[4][1]);
                        $memberEnd = $memberOffset + strlen($memberName);

                        if ($character >= $memberOffset && $character <= $memberEnd) {
                            return [
                                'varName' => '',
                                'rootCall' => $helperName,
                                'rootCallArg' => null,
                                'chain' => $accumulatedChain,
                                'memberName' => $memberName,
                                'isArrayAccess' => !$isObject,
                                'start' => $memberOffset,
                                'end' => $memberEnd,
                            ];
                        }

                        $accumulatedChain .= $seg[0][0];
                    }
                }
            }
        }

        // 3. Match variable access chains
        if (preg_match_all('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?|\[[^\]]*\])+)/', $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $varName = $m[1][0];
                $fullChain = $m[2][0];
                $chainOffset = $m[2][1];

                if (preg_match_all('/(?:(->|\?->)([a-zA-Z0-9_]+)|\[\s*([\'"]?)([a-zA-Z0-9_]+)\3\s*\])/', $fullChain, $segments, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    $accumulatedChain = '';
                    foreach ($segments as $seg) {
                        $isObject = !empty($seg[1][0]);
                        $memberName = $isObject ? $seg[2][0] : $seg[4][0];
                        $memberOffset = $chainOffset + ($isObject ? $seg[2][1] : $seg[4][1]);
                        $memberEnd = $memberOffset + strlen($memberName);

                        if ($character >= $memberOffset && $character <= $memberEnd) {
                            return [
                                'varName' => $varName,
                                'rootCall' => null,
                                'rootCallArg' => null,
                                'chain' => $accumulatedChain,
                                'memberName' => $memberName,
                                'isArrayAccess' => !$isObject,
                                'start' => $memberOffset,
                                'end' => $memberEnd,
                            ];
                        }

                        $accumulatedChain .= $seg[0][0];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve detailed member information for hover.
     *
     * @return array<string, mixed>|null
     */
    public function resolveMemberDetails(string $type, string $memberName, bool $isArrayAccess, string $varName, mixed $varSource = null, mixed $varLine = null): ?array
    {
        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');
        $baseClass = preg_replace('/<.*>$/', '', $cleanType);
        $baseClass = ltrim(preg_replace('/\[\]$/', '', $baseClass), '\\');

        // 1. Loop variable: $loop->index, $loop->iteration, $loop->first, $loop->last, etc.
        if ($varName === 'loop' || $cleanType === 'stdClass' || $cleanType === 'object' || $baseClass === 'stdClass' || $baseClass === 'object') {
            $loopProps = [
                'index' => ['type' => 'int', 'doc' => 'The index of the current loop iteration (starts at 0).'],
                'iteration' => ['type' => 'int', 'doc' => 'The current loop iteration (starts at 1).'],
                'remaining' => ['type' => 'int', 'doc' => 'The iterations remaining in the loop.'],
                'count' => ['type' => 'int', 'doc' => 'The total number of items in the array being iterated.'],
                'first' => ['type' => 'bool', 'doc' => 'Whether this is the first iteration through the loop.'],
                'last' => ['type' => 'bool', 'doc' => 'Whether this is the last iteration through the loop.'],
                'even' => ['type' => 'bool', 'doc' => 'Whether this is an even iteration through the loop.'],
                'odd' => ['type' => 'bool', 'doc' => 'Whether this is an odd iteration through the loop.'],
                'depth' => ['type' => 'int', 'doc' => 'The nesting level of the current loop.'],
                'parent' => ['type' => '?object', 'doc' => 'When in a nested loop, the parent loop variable.'],
            ];
            if (isset($loopProps[$memberName])) {
                $p = $loopProps[$memberName];
                return [
                    'title' => "\$loop->{$memberName}",
                    'type' => $p['type'],
                    'origin' => 'Blade Loop Property',
                    'documentation' => $p['doc'],
                    'isMethod' => false,
                    'source' => null,
                    'line' => null,
                ];
            }
        }

        // 2. Array shape: array{ip: string, user_agent: string}
        $shapeKeys = $this->docBlockParser->extractArrayShapeKeys($cleanType);
        if (isset($shapeKeys[$memberName])) {
            $propType = $shapeKeys[$memberName]['type'];
            return [
                'title' => "\${$varName}['{$memberName}']",
                'type' => $propType,
                'origin' => 'Array Shape',
                'isMethod' => false,
                'source' => $varSource,
                'line' => $varLine,
            ];
        }

        // 3. Eloquent Model Attributes & Relations from Project Index
        $models = $this->project->index->models();
        if (isset($models[$baseClass])) {
            $modelData = $models[$baseClass];
            $modelPath = !empty($modelData['path']) ? $this->relativePath($modelData['path']) : null;
            $modelLine = (int) ($modelData['line'] ?? 1);
            $shortName = class_basename($baseClass);

            foreach ($modelData['attributes'] ?? [] as $attr) {
                if (($attr['name'] ?? '') === $memberName) {
                    $attrType = $attr['cast'] ?? $attr['type'] ?? 'mixed';
                    return [
                        'title' => "{$baseClass}::\${$memberName}",
                        'type' => $attrType,
                        'origin' => "Eloquent Attribute ({$shortName})",
                        'isMethod' => false,
                        'source' => $modelPath,
                        'line' => $modelLine,
                    ];
                }
            }

            foreach ($modelData['relations'] ?? [] as $rel) {
                if (($rel['name'] ?? '') === $memberName) {
                    $relType = $rel['type'] ?? 'Relation';
                    $relRelated = $rel['related'] ?? 'Model';
                    return [
                        'title' => "{$baseClass}::\${$memberName}",
                        'type' => '\\' . ltrim($relRelated, '\\'),
                        'origin' => "Eloquent Relation ({$relType} -> {$relRelated})",
                        'isMethod' => false,
                        'source' => $modelPath,
                        'line' => $modelLine,
                    ];
                }
            }
        }

        if (!class_exists($baseClass) && !interface_exists($baseClass) && !enum_exists($baseClass)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($baseClass);
            $classFile = $reflection->getFileName() ? $this->relativePath($reflection->getFileName()) : null;
            $classLine = $reflection->getStartLine() ?: 1;

            $classesToSearch = [$reflection];
            $seenClasses = [$baseClass => true];

            // Collect class hierarchy and @mixin classes
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
                $targetFile = $targetRef->getFileName() ? $this->relativePath($targetRef->getFileName()) : $classFile;
                $targetLine = $targetRef->getStartLine() ?: 1;

                // 3. Class PHPDoc @property, @property-read, @property-write, @method
                if ($docComment = $targetRef->getDocComment()) {
                    $docProps = $this->docBlockParser->extractProperties($docComment);
                    if (isset($docProps[$memberName])) {
                        $pType = $docProps[$memberName];
                        return [
                            'title' => "{$targetRef->getName()}::\${$memberName}",
                            'type' => $pType,
                            'origin' => 'Property (' . $targetRef->getShortName() . ')',
                            'isMethod' => false,
                            'source' => $targetFile,
                            'line' => $targetLine,
                        ];
                    }

                    $docMethods = $this->docBlockParser->extractMethods($docComment);
                    if (isset($docMethods[$memberName])) {
                        $mInfo = $docMethods[$memberName];
                        return [
                            'title' => "{$targetRef->getName()}::{$memberName}()",
                            'type' => $mInfo['returnType'],
                            'origin' => 'Method (' . $targetRef->getShortName() . ')',
                            'isMethod' => true,
                            'signature' => $mInfo['signature'],
                            'source' => $targetFile,
                            'line' => $targetLine,
                        ];
                    }
                }

                // 4. Backed Enum properties (value, name)
                if ($targetRef->isEnum()) {
                    if ($memberName === 'value') {
                        return [
                            'title' => "{$targetRef->getName()}::\$value",
                            'type' => 'string|int',
                            'origin' => 'Backed Enum Property',
                            'isMethod' => false,
                            'source' => $targetFile,
                            'line' => $targetLine,
                        ];
                    }
                    if ($memberName === 'name') {
                        return [
                            'title' => "{$targetRef->getName()}::\$name",
                            'type' => 'string',
                            'origin' => 'Enum Case Name',
                            'isMethod' => false,
                            'source' => $targetFile,
                            'line' => $targetLine,
                        ];
                    }
                }

                // 5. Public Properties
                if ($targetRef->hasProperty($memberName)) {
                    $prop = $targetRef->getProperty($memberName);
                    if ($prop->isPublic()) {
                        $pType = $prop->getType() ? (string) $prop->getType() : 'mixed';
                        $source = $prop->getDeclaringClass()->getFileName();
                        $relSource = $source ? $this->relativePath($source) : $targetFile;
                        $propLine = $targetLine;
                        if ($source && file_exists($source)) {
                            $fileLines = file($source) ?: [];
                            foreach ($fileLines as $lIdx => $lText) {
                                if (preg_match('/(?:public|var)\s+(?:[^\$]+\s+)?\$' . preg_quote($memberName, '/') . '\b/', $lText)) {
                                    $propLine = $lIdx + 1;
                                    break;
                                }
                            }
                        }
                        return [
                            'title' => "{$prop->getDeclaringClass()->getName()}::\${$memberName}",
                            'type' => $pType,
                            'origin' => 'Property (' . $prop->getDeclaringClass()->getShortName() . ')',
                            'isMethod' => false,
                            'source' => $relSource,
                            'line' => $propLine,
                        ];
                    }
                }

                // 6. Public Methods
                if ($targetRef->hasMethod($memberName)) {
                    $method = $targetRef->getMethod($memberName);
                    if ($method->isPublic()) {
                        $params = [];
                        foreach ($method->getParameters() as $param) {
                            $paramStr = '';
                            if ($param->hasType()) {
                                $paramStr .= (string) $param->getType() . ' ';
                            }
                            if ($param->isPassedByReference()) {
                                $paramStr .= '&';
                            }
                            if ($param->isVariadic()) {
                                $paramStr .= '...';
                            }
                            $paramStr .= '$' . $param->getName();
                            if ($param->isDefaultValueAvailable()) {
                                $paramStr .= ' = ' . json_encode($param->getDefaultValue());
                            }
                            $params[] = $paramStr;
                        }
                        $returnType = $method->hasReturnType() ? (string) $method->getReturnType() : 'mixed';
                        $paramSignature = '(' . implode(', ', $params) . '): ' . $returnType;
                        $source = $method->getDeclaringClass()->getFileName();
                        $relSource = $source ? $this->relativePath($source) : $targetFile;
                        $sourceLine = $method->getStartLine() ?: $targetLine;

                        return [
                            'title' => "{$method->getDeclaringClass()->getName()}::{$memberName}()",
                            'type' => $returnType,
                            'origin' => ($method->isStatic() ? 'Static Method (' : 'Method (') . $method->getDeclaringClass()->getShortName() . ')',
                            'isMethod' => true,
                            'signature' => $paramSignature,
                            'source' => $relSource,
                            'line' => $sourceLine,
                        ];
                    }
                }

                // 7. Class Constants & Enum Cases
                if ($targetRef->hasConstant($memberName)) {
                    $const = $targetRef->getReflectionConstant($memberName);
                    if ($const && $const->isPublic()) {
                        $constVal = $targetRef->getConstant($memberName);
                        $valStr = is_scalar($constVal) ? var_export($constVal, true) : (is_array($constVal) ? '[]' : 'mixed');
                        $cType = is_object($constVal) ? ('\\' . get_class($constVal)) : gettype($constVal);
                        $source = $targetRef->getFileName();
                        $relSource = $source ? $this->relativePath($source) : $targetFile;

                        return [
                            'title' => "{$targetRef->getName()}::{$memberName}",
                            'type' => $cType,
                            'origin' => $targetRef->isEnum() ? 'Enum Case' : 'Class Constant',
                            'isMethod' => false,
                            'documentation' => "Constant value: `{$valStr}`",
                            'source' => $relSource,
                            'line' => $targetLine,
                        ];
                    }
                }
            }
        } catch (Throwable) {}

        return null;
    }

    /**
     * Hover support for class tokens and @use directives.
     */
    public function findClassTokenHover(Document $document, string $line, int $character, int $lineNumber): ?array
    {
        // 1. Hover on @use('App\Models\Post', 'BlogPost') directive string or alias
        if (preg_match('/@use\s*\(\s*([\'"]([a-zA-Z0-9_\\\\]+)[\'"]|[a-zA-Z0-9_\\\\]+::class)(?:\s*,\s*[\'"]([a-zA-Z0-9_]+)[\'"])?\s*\)/', $line, $m, PREG_OFFSET_CAPTURE)) {
            $fullStart = $m[0][1];
            $fullEnd = $fullStart + strlen($m[0][0]);
            if ($character >= $fullStart && $character <= $fullEnd) {
                $rawClass = !empty($m[2][0]) ? $m[2][0] : trim(str_replace('::class', '', $m[1][0]));
                $alias = !empty($m[3][0]) ? $m[3][0] : class_basename($rawClass);
                return [
                    'contents' => [
                        'kind' => 'markdown',
                        'value' => "### Blade Class Import\n\n```blade\n@use('{$rawClass}'" . ($alias !== class_basename($rawClass) ? ", '{$alias}'" : '') . ")\n```\n\nImports `{$rawClass}` into template scope as `{$alias}`.",
                    ],
                    'range' => [
                        'start' => ['line' => $lineNumber, 'character' => $fullStart],
                        'end' => ['line' => $lineNumber, 'character' => $fullEnd],
                    ],
                ];
            }
        }

        // 2. Hover on Facade or @use alias token (e.g. Js, Str, Auth, AirFlight, Status)
        if (preg_match_all('/(?:\b([a-zA-Z0-9_\\\\]+)::|\b([A-Z][a-zA-Z0-9_]*)\b)/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $fullMatch) {
                $start = $fullMatch[1];
                $end = $start + strlen($fullMatch[0]);
                if ($character >= $start && $character <= $end) {
                    $token = !empty($matches[1][$idx][0]) ? $matches[1][$idx][0] : $matches[2][$idx][0];
                    if ($token === '') continue;

                    $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
                    if (isset($importedUses[$token])) {
                        $uInfo = $importedUses[$token];
                        return [
                            'contents' => [
                                'kind' => 'markdown',
                                'value' => "### Imported Class `{$token}`\n\n*Class:* `{$uInfo['class']}`\n*Imported on line:* {$uInfo['line']}\n\n```blade\n@use('{$uInfo['class']}', '{$token}')\n```",
                            ],
                            'range' => [
                                'start' => ['line' => $lineNumber, 'character' => $start],
                                'end' => ['line' => $lineNumber, 'character' => $end],
                            ],
                        ];
                    }

                    if (\App\Lsp\Features\Facades\FacadeMap::isFacadeOrAlias($token)) {
                        $fqcn = \App\Lsp\Features\Facades\FacadeMap::resolve($token);
                        $desc = \App\Lsp\Features\Facades\FacadeMap::description($token);
                        return [
                            'contents' => [
                                'kind' => 'markdown',
                                'value' => "### {$token} (Laravel Facade)\n\n*FQCN:* `{$fqcn}`\n\n{$desc}",
                            ],
                            'range' => [
                                'start' => ['line' => $lineNumber, 'character' => $start],
                                'end' => ['line' => $lineNumber, 'character' => $end],
                            ],
                        ];
                    }
                }
            }
        }

        // 3. Hover on Global & Custom Helper Functions (e.g. route(), view(), count(), custom_helper())
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $fMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($fMatches[1] as $fMatch) {
                $fnName = $fMatch[0];
                $fStart = $fMatch[1];
                $fEnd = $fStart + strlen($fnName);
                if ($character >= $fStart && $character <= $fEnd) {
                    $fnRegistry = new \App\Lsp\Features\Functions\GlobalFunctionRegistry($this->project);
                    $fnInfo = $fnRegistry->get($fnName);
                    if ($fnInfo) {
                        $origin = isset($fnInfo['source']) ? 'User Defined Helper' : (str_contains($fnInfo['doc'] ?? '', 'Laravel') ? 'Laravel Helper' : 'Global Function');
                        return [
                            'contents' => [
                                'kind' => 'markdown',
                                'value' => "### `{$fnInfo['signature']}`\n\n{$fnInfo['doc']}\n\n*Origin:* `{$origin}`",
                            ],
                            'range' => [
                                'start' => ['line' => $lineNumber, 'character' => $fStart],
                                'end' => ['line' => $lineNumber, 'character' => $fEnd],
                            ],
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve chained types such as $ticket->status?->value or $device['meta']['ip'].
     */
    protected function resolveChainedType(string $rootType, string $chain): string
    {
        if ($chain === '') {
            return $rootType;
        }

        $currentType = $rootType;
        if (preg_match_all('/(?:->|\?->|\[\s*[\'"]?)([a-zA-Z0-9_]+)(?:[\'"]?\s*\]|\([^\)]*\))?/', $chain, $m)) {
            foreach ($m[1] as $member) {
                // If currentType is Collection/Paginator and accessing element
                if (in_array($member, ['first', 'last', 'random', 'pop', 'shift', 'sole', 'value'], true)) {
                    if (preg_match('/(?:Collection|LengthAwarePaginator|Paginator)<(?:[^,]+,\s*)?([^>]+)>/', $currentType, $matchItem)) {
                        $currentType = trim($matchItem[1]);
                        continue;
                    }
                }

                // If calling collection transformations returning self collection
                if (in_array($member, ['where', 'filter', 'map', 'values', 'sortBy', 'sortByDesc', 'take', 'skip', 'slice'], true)) {
                    continue;
                }

                $details = $this->resolveMemberDetails($currentType, $member, false, 'item');
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
     * Collect all variables available for the current view.
     *
     * @param array<string, mixed>|null $position
     * @return array<string, array<string, mixed>>
     */
    protected function collectVariablesForView(Document $document, string $viewKey, ?array $position = null): array
    {
        if ($position !== null && isset($position['line'], $position['character'])) {
            return $this->scopeResolver->resolveAtPosition($document, (int) $position['line'], (int) $position['character'], $viewKey)->legacyVariables();
        }

        return $this->scopeResolver->legacyVariables($document, $viewKey);
    }

    /**
     * Resolve the dot-notation view key from the file URI.
     */
    protected function resolveViewKey(string $uri): string
    {
        $path = str_replace('\\', '/', $uri);

        try {
            $views = $this->project->index->views();
            $matched = $views->first(function ($view) use ($path) {
                $viewPath = str_replace('\\', '/', $view['path'] ?? '');
                return $viewPath !== '' && str_ends_with($path, $viewPath);
            });
            if ($matched && !empty($matched['key'])) {
                return $matched['key'];
            }
        } catch (Throwable) {}

        if (preg_match('/resources\/views\/vendor\/([^\/]+)\/(.+)\.blade\.php$/', $path, $matches)) {
            return "{$matches[1]}::" . str_replace('/', '.', $matches[2]);
        }

        if (preg_match('/Modules\/([^\/]+)\/resources\/views\/(.+)\.blade\.php$/i', $path, $matches)) {
            return strtolower($matches[1]) . '::' . str_replace('/', '.', $matches[2]);
        }

        if (preg_match('/resources\/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        if (preg_match('/views\/(.+)\.blade\.php$/', $path, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return basename($path, '.blade.php');
    }

    protected function relativePath(string $uriOrPath): string
    {
        $path = str_starts_with($uriOrPath, 'file://') ? FileUri::of($uriOrPath)->path() : $uriOrPath;
        $base = rtrim($this->project->path(), '/\\');
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $base);

        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
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
            } catch (Throwable) {}
        }
    }

    /**
     * Qualify a potentially short class name to a fully qualified class name.
     */
    public function qualifyType(string $type, Document $document): string
    {
        $type = trim($type);
        if ($type === '' || $type === 'mixed' || $type === 'void' || $type === 'null') {
            return $type;
        }

        if (str_starts_with($type, '\\')) {
            return $type;
        }

        if (str_starts_with($type, '?')) {
            return '?' . $this->qualifyType(substr($type, 1), $document);
        }

        if (preg_match('/^([a-zA-Z0-9_\\\\]+)<(.+)>$/', $type, $m)) {
            $outer = $this->qualifyType($m[1], $document);
            $innerParts = array_map('trim', explode(',', $m[2]));
            $qualifiedInners = array_map(fn($p) => $this->qualifyType($p, $document), $innerParts);
            return $outer . '<' . implode(', ', $qualifiedInners) . '>';
        }

        if (str_ends_with($type, '[]')) {
            return $this->qualifyType(substr($type, 0, -2), $document) . '[]';
        }

        if (str_contains($type, '|')) {
            $parts = explode('|', $type);
            return implode('|', array_map(fn($p) => $this->qualifyType(trim($p), $document), $parts));
        }

        $primitives = ['string', 'int', 'float', 'bool', 'array', 'object', 'callable', 'iterable', 'self', 'static', 'parent', 'true', 'false', 'never', 'stdClass'];
        if (in_array(strtolower($type), $primitives, true)) {
            return $type;
        }

        $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
        if (isset($importedUses[$type])) {
            return $importedUses[$type]['class'];
        }

        try {
            $models = $this->project->index->models();
            foreach ($models as $fqcn => $mData) {
                if (class_basename($fqcn) === $type) {
                    return '\\' . ltrim($fqcn, '\\');
                }
            }
        } catch (Throwable) {}

        return $type;
    }
}
