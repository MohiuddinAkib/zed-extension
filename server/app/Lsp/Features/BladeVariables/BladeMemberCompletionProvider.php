<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\DocBlockParser;
use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Features\AppBindings\AppBindingContainerTypeMap;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

class BladeMemberCompletionProvider implements CompletionProvider
{
    public const HIGHER_ORDER_COLLECTION_PROXIES = [
        'average' => 'Calculate average value by property/method across items',
        'avg' => 'Calculate average value by property/method across items',
        'contains' => 'Determine if any item matches the condition or property',
        'each' => 'Execute a callback / method on each item',
        'every' => 'Verify that all items match the condition or property',
        'filter' => 'Filter items using a method or truthy property',
        'first' => 'Get the first item matching the property/method',
        'flatMap' => 'Map a collection and flatten the result',
        'groupBy' => 'Group collection items by property or method',
        'keyBy' => 'Key collection items by property or method',
        'map' => 'Transform each item via property access or method call',
        'max' => 'Get maximum value by property/method across items',
        'min' => 'Get minimum value by property/method across items',
        'partition' => 'Separate items into two collections by condition',
        'reject' => 'Filter out items using a method or truthy property',
        'skipUntil' => 'Skip items until condition or property is met',
        'skipWhile' => 'Skip items while condition or property is met',
        'some' => 'Determine if any item matches the condition or property',
        'sortBy' => 'Sort items by property or method in ascending order',
        'sortByDesc' => 'Sort items by property or method in descending order',
        'sum' => 'Sum property or method values across items',
        'takeUntil' => 'Take items until condition or property is met',
        'takeWhile' => 'Take items while condition or property is met',
        'unique' => 'Filter collection so only unique items by property remain',
        'until' => 'Take items until condition or property is met',
    ];

    protected BladeAstAnalyzer $bladeAnalyzer;
    protected BladeScopeResolver $scopeResolver;
    protected DocBlockParser $docBlockParser;
    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
    ) {
        $this->bladeAnalyzer = new BladeAstAnalyzer();
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
        $this->docBlockParser = new DocBlockParser();
    }

    /**
     * Provide member completions (properties, methods, relations) for typed variables in Blade templates.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return [];
        }

        $line = explode("\n", $document->content)[$lineNumber] ?? '';
        $text = Utf16Position::substr($line, 0, $character);

        $isArrayAccess = false;
        $quoteChar = '';
        $varName = '';
        $chain = '';
        $memberPrefix = '';
        $targetType = null;

        // 1. @use('...' directive class name completion
        if (preg_match('/@use\s*\(\s*[\'"]?([a-zA-Z0-9_\\\\]*)$/', $text, $useMatch)) {
            return $this->getUseDirectiveCompletions($useMatch[1], $lineNumber, $character);
        }

        // 2. Static / Facade member call: Js::, Str::, Auth::, Route::, DB::, AirFlight::, Status::
        if (preg_match('/([a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]*)$/', $text, $staticMatch)) {
            return $this->getStaticMemberCompletions($document, $staticMatch[1], $staticMatch[2], $lineNumber, $character);
        }

        // 3. Container call: app('db.connection')-> or resolve('db')-> or app(PaymentService::class)->
        if (preg_match('/(?:app|resolve)\s*\(\s*([\'"][a-zA-Z0-9_.\/\\\\-]+[\'"]|[a-zA-Z0-9_\\\\]+::class)\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $rawBinding = $matches[1];
            $chain = $matches[2];
            $memberPrefix = $matches[3];
            $bindingKey = str_ends_with($rawBinding, '::class')
                ? substr($rawBinding, 0, -7)
                : trim($rawBinding, '\'"');
            $rootType = AppBindingContainerTypeMap::resolveType($bindingKey);
            if ($rootType) {
                $targetType = $this->resolveChainedType($rootType, $chain);
            }
        }
        // 4. Tap helper: tap($var)-> or tap(new Class())->
        elseif (preg_match('/tap\s*\(\s*(?:\$([a-zA-Z0-9_]+)|new\s+([a-zA-Z0-9_\\\\]+)(?:\([^\)]*\))?)\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $varName = $matches[1];
            $newClass = $matches[2];
            $chain = $matches[3];
            $memberPrefix = $matches[4];

            if ($varName !== '') {
                $viewKey = $this->resolveViewKey($document->uri);
                $variables = $this->collectVariablesForView($document, $viewKey, $position);
                if (isset($variables[$varName])) {
                    $varType = $variables[$varName]['type'] ?? 'mixed';
                    $varType = $this->qualifyType($varType, $document);
                    $targetType = $this->resolveChainedType($varType, $chain);
                }
            } elseif ($newClass !== '') {
                $rootType = $this->qualifyType($newClass, $document);
                $targetType = $this->resolveChainedType($rootType, $chain);
            }
        }
        // 5. Helper calls: auth()->, request()->, session()->, now()->, today()->
        elseif (preg_match('/(auth|request|session|now|today)\s*\(\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $helperName = $matches[1];
            $chain = $matches[2];
            $memberPrefix = $matches[3];
            $rootType = match ($helperName) {
                'auth' => '\Illuminate\Auth\AuthManager',
                'request' => '\Illuminate\Http\Request',
                'session' => '\Illuminate\Session\SessionManager',
                'now', 'today' => '\Illuminate\Support\Carbon',
                default => null,
            };
            if ($rootType) {
                $targetType = $this->resolveChainedType($rootType, $chain);
            }
        }
        // 6. Object member access: $var-> or $var?-> or $var->chain->
        elseif (preg_match('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $varName = $matches[1];
            $chain = $matches[2];
            $memberPrefix = $matches[3];

            $viewKey = $this->resolveViewKey($document->uri);
            $variables = $this->collectVariablesForView($document, $viewKey, $position);
            if (isset($variables[$varName])) {
                $varType = $variables[$varName]['type'] ?? 'mixed';
                $varType = $this->qualifyType($varType, $document);
                $targetType = $this->resolveChainedType($varType, $chain);
            }
        }
        // 7. Array key access: $var[' or $var[" or $var[ or $var['key']['
        elseif (preg_match('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?|\[[^\]]*\])*)\[([\'"]?)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $isArrayAccess = true;
            $varName = $matches[1];
            $chain = $matches[2];
            $quoteChar = $matches[3]; // "'" or '"' or ''
            $memberPrefix = $matches[4];

            $viewKey = $this->resolveViewKey($document->uri);
            $variables = $this->collectVariablesForView($document, $viewKey, $position);
            if (isset($variables[$varName])) {
                $varType = $variables[$varName]['type'] ?? 'mixed';
                $varType = $this->qualifyType($varType, $document);
                $targetType = $this->resolveChainedType($varType, $chain);
            }
        } else {
            // 8. Global Facades and @use class suggestions in expression context
            return $this->getExpressionClassCompletions($document, $text, $lineNumber, $character);
        }

        if (!$targetType || $targetType === 'mixed') {
            return [];
        }

        $this->ensureAutoloaderRegistered();

        $members = $this->resolveMembersForType($targetType);

        if (empty($members)) {
            return [];
        }

        $range = [
            'start' => [
                'line' => $lineNumber,
                'character' => $character - strlen($memberPrefix),
            ],
            'end' => [
                'line' => $lineNumber,
                'character' => $character,
            ],
        ];

        $completions = [];
        foreach ($members as $member) {
            $name = $member['name'];
            if ($memberPrefix !== '' && !str_starts_with(strtolower($name), strtolower($memberPrefix))) {
                continue;
            }

            $kind = $member['kind']; // 10: Property, 5: Field, 2: Method, 21: Constant
            $detail = $member['detail'] ?? '';
            $doc = $member['documentation'] ?? '';
            $isMethod = ($kind === 2 || ($member['isMethod'] ?? false));

            $label = $name;
            $newText = $name;
            $insertTextFormat = 1; // PlainText by default

            if ($isArrayAccess) {
                if ($quoteChar === '') {
                    $label = "'{$name}'";
                    $newText = "'{$name}'";
                }
            } elseif ($isMethod) {
                $insertTextFormat = 2; // Snippet format
                $snippet = $member['snippet'] ?? null;
                if ($snippet !== null) {
                    $newText = $snippet;
                } else {
                    $requiredParams = $member['requiredParams'] ?? null;
                    if ($requiredParams !== null) {
                        $newText = $requiredParams > 0 ? "{$name}(\${1})" : "{$name}()";
                    } else {
                        // Check if signature contains required parameters
                        $hasRequiredParam = false;
                        if (preg_match('/\(\s*([^)=,\s]+)/', $detail, $paramMatch)) {
                            $firstParam = trim($paramMatch[1]);
                            if ($firstParam !== '' && $firstParam !== 'void') {
                                $hasRequiredParam = true;
                            }
                        }
                        $newText = $hasRequiredParam ? "{$name}(\${1})" : "{$name}()";
                    }
                }
            }

            $sortPrefix = match ($kind) {
                10 => '0_', // Properties / Array keys
                5 => '1_',  // Relations/Fields
                2 => '2_',  // Methods
                21 => '3_', // Constants
                default => '4_',
            };

            $item = [
                'label' => $label,
                'kind' => $kind,
                'detail' => $detail,
                'insertTextFormat' => $insertTextFormat,
                'textEdit' => [
                    'range' => $range,
                    'newText' => $newText,
                ],
                'sortText' => $sortPrefix . $name,
            ];

            if ($doc !== '') {
                $item['documentation'] = [
                    'kind' => 'markdown',
                    'value' => $doc,
                ];
            }

            $completions[] = $item;
        }

        return $completions;
    }

    /**
     * Resolve members (properties, methods, relations, array shape keys) for a given type.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function resolveMembersForType(string $type): array
    {
        $members = [];
        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');
        $baseClass = preg_replace('/<.*>$/', '', $cleanType);
        $baseClass = ltrim(preg_replace('/\[\]$/', '', $baseClass), '\\');

        // 1. Loop variable: $loop->index, $loop->iteration, $loop->first, $loop->last, etc.
        if ($cleanType === 'stdClass' || $cleanType === 'object' || $baseClass === 'stdClass' || $baseClass === 'object') {
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
            foreach ($loopProps as $pName => $pInfo) {
                $members[$pName] = [
                    'name' => $pName,
                    'kind' => 10, // Property
                    'detail' => $pInfo['type'],
                    'documentation' => "**\$loop->{$pName}** (`{$pInfo['type']}`)\n\n{$pInfo['doc']}",
                ];
            }
            return $members;
        }

        // 2. Array shape or Object shape: array{id?: int, subject?: string} or object{...}
        $shapeKeys = $this->docBlockParser->extractArrayShapeKeys($cleanType);
        if (!empty($shapeKeys)) {
            foreach ($shapeKeys as $propName => $info) {
                $propType = $info['type'];
                $members[$propName] = [
                    'name' => $propName,
                    'kind' => 10, // Property
                    'detail' => $propType,
                    'documentation' => "`@var {$propType} \${$propName}`",
                ];
            }
            return $members;
        }

        // 3. Eloquent Model Attributes & Relations from Index
        $models = $this->project->index->models();
        if (isset($models[$baseClass])) {
            $modelData = $models[$baseClass];
            foreach ($modelData['attributes'] ?? [] as $attr) {
                $attrName = $attr['name'] ?? '';
                if ($attrName !== '') {
                    $attrType = $attr['cast'] ?? $attr['type'] ?? 'mixed';
                    $members[$attrName] = [
                        'name' => $attrName,
                        'kind' => 10, // Property
                        'detail' => $attrType,
                        'documentation' => "**Eloquent Attribute**\n\n*Type:* `{$attrType}`",
                    ];
                }
            }

            foreach ($modelData['relations'] ?? [] as $rel) {
                $relName = $rel['name'] ?? '';
                if ($relName !== '') {
                    $relType = $rel['type'] ?? 'Relation';
                    $relRelated = $rel['related'] ?? 'Model';
                    $members[$relName] = [
                        'name' => $relName,
                        'kind' => 5, // Field / Relation
                        'detail' => "{$relType} -> {$relRelated}",
                        'documentation' => "**Eloquent Relation** (`{$relType}`)\n\n*Related Model:* `{$relRelated}`",
                    ];
                }
            }
        }

        // 4. Collection Higher Order Proxies
        if ($this->isCollectionType($type)) {
            $itemType = $this->extractCollectionItemType($type) ?? 'mixed';
            $shortItemType = class_basename($itemType);
            foreach (self::HIGHER_ORDER_COLLECTION_PROXIES as $proxyName => $proxyDesc) {
                $members[$proxyName] = [
                    'name' => $proxyName,
                    'kind' => 10, // Property
                    'detail' => "HigherOrderCollectionProxy<{$shortItemType}>",
                    'documentation' => "**Higher Order Collection Proxy (`{$proxyName}`)**\n\n```php\n\$collection->{$proxyName}->...\n```\n\n{$proxyDesc}.\n\n*Target Item Type:* `{$itemType}`",
                ];
            }
        }

        // 5. Higher Order Tap Proxy
        $members['tap'] = [
            'name' => 'tap',
            'kind' => 10, // Property
            'detail' => "HigherOrderTapProxy<" . class_basename($cleanType) . ">",
            'documentation' => "**Higher Order Tap Proxy**\n\n```php\n\$var->tap()->... or \$var->tap->...\n```\n\nPasses the target object into a closure and returns it.",
        ];

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
                // 3. Class PHPDoc @property, @property-read, @property-write, @method
                if ($docComment = $targetRef->getDocComment()) {
                    $docProps = $this->docBlockParser->extractProperties($docComment);
                    foreach ($docProps as $pName => $pType) {
                        if (!isset($members[$pName])) {
                            $members[$pName] = [
                                'name' => $pName,
                                'kind' => 10,
                                'detail' => $pType,
                                'documentation' => "`@var {$pType} \${$pName}`\n\n*Source:* `{$targetRef->getName()}`",
                            ];
                        }
                    }

                    $docMethods = $this->docBlockParser->extractMethods($docComment);
                    foreach ($docMethods as $mName => $mInfo) {
                        if (!isset($members[$mName])) {
                            $sig = $mInfo['signature'] ?? '()';
                            $hasRequired = false;
                            if (preg_match('/\(\s*([^)=,\s]+)/', $sig, $pm)) {
                                $fp = trim($pm[1]);
                                if ($fp !== '' && $fp !== 'void') {
                                    $hasRequired = true;
                                }
                            }
                            $members[$mName] = [
                                'name' => $mName,
                                'kind' => 2,
                                'detail' => $sig,
                                'documentation' => "```php\npublic {$mInfo['name']}{$sig}\n```\n\n*Source:* `{$targetRef->getName()}`",
                                'isMethod' => true,
                                'requiredParams' => $hasRequired ? 1 : 0,
                                'snippet' => $hasRequired ? "{$mName}(\${1})" : "{$mName}()",
                            ];
                        }
                    }
                }

                // 4. Backed Enum properties (value, name)
                if ($targetRef->isEnum()) {
                    $members['name'] = [
                        'name' => 'name',
                        'kind' => 10,
                        'detail' => 'string',
                        'documentation' => 'The case name of this Enum.',
                    ];
                    $members['value'] = [
                        'name' => 'value',
                        'kind' => 10,
                        'detail' => 'string|int',
                        'documentation' => 'The scalar value of this Backed Enum.',
                    ];
                }

                // 5. Public Properties
                foreach ($targetRef->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                    $pName = $prop->getName();
                    if (!isset($members[$pName])) {
                        $pType = $prop->getType() ? (string) $prop->getType() : 'mixed';
                        $members[$pName] = [
                            'name' => $pName,
                            'kind' => 10,
                            'detail' => $pType,
                            'documentation' => "```php\npublic {$pType} \${$pName};\n```",
                        ];
                    }
                }

                // 6. Public Methods
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
                        $paramSignature = '(' . implode(', ', $params) . ')';
                        $numRequired = $method->getNumberOfRequiredParameters();

                        $members[$mName] = [
                            'name' => $mName,
                            'kind' => 2, // Method
                            'detail' => "{$paramSignature}: {$returnType}",
                            'documentation' => "```php\npublic function {$mName}{$paramSignature}: {$returnType}\n```",
                            'isMethod' => true,
                            'requiredParams' => $numRequired,
                            'snippet' => $numRequired > 0 ? "{$mName}(\${1})" : "{$mName}()",
                        ];
                    }
                }
            }
        } catch (Throwable) {}

        return $members;
    }

    /**
     * Resolve chained types such as $ticket->status?->value.
     */
    protected function resolveChainedType(string $rootType, string $chain): string
    {
        if ($chain === '') {
            return $rootType;
        }

        $currentType = $rootType;
        if (preg_match_all('/(?:->|\?->|\[\s*[\'"]?)([a-zA-Z0-9_]+)(?:[\'"]?\s*\]|\([^\)]*\))?/', $chain, $m)) {
            foreach ($m[1] as $member) {
                // Higher order tap proxy
                if ($member === 'tap') {
                    continue;
                }

                // Higher order collection proxy access (e.g. $users->map->, $users->each->, $users->filter->)
                if (isset(self::HIGHER_ORDER_COLLECTION_PROXIES[$member])) {
                    if ($this->isCollectionType($currentType)) {
                        $itemType = $this->extractCollectionItemType($currentType);
                        if ($itemType !== null) {
                            $currentType = $itemType;
                            continue;
                        }
                    }
                }

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

                $members = $this->resolveMembersForType($currentType);
                if (isset($members[$member])) {
                    $detail = $members[$member]['detail'] ?? '';
                    if (str_contains($detail, '): ')) {
                        $currentType = trim(explode('): ', $detail)[1]);
                    } elseif ($detail !== '') {
                        $currentType = $detail;
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }
        }

        return $currentType;
    }

    protected function isCollectionType(string $type): bool
    {
        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');
        $baseClass = preg_replace('/<.*>$/', '', $cleanType);
        $baseClass = ltrim(preg_replace('/\[\]$/', '', $baseClass), '\\');

        if (in_array($baseClass, [
            'Illuminate\Database\Eloquent\Collection',
            'Illuminate\Support\Collection',
            'Illuminate\Support\LazyCollection',
            'Illuminate\Support\Enumerable',
            'Collection',
            'LazyCollection',
        ], true)) {
            return true;
        }

        if (class_exists($baseClass) && (
            is_subclass_of($baseClass, 'Illuminate\Support\Enumerable') ||
            is_subclass_of($baseClass, 'Illuminate\Support\Collection')
        )) {
            return true;
        }

        return false;
    }

    protected function extractCollectionItemType(string $type): ?string
    {
        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');

        if (preg_match('/(?:Collection|Enumerable|LazyCollection|Paginator)<(?:[^,]+,\s*)?([^>]+)>/i', $cleanType, $m)) {
            return trim($m[1]);
        }

        return null;
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

    public function getStaticMemberCompletions(Document $document, string $classOrAlias, string $memberPrefix, int $lineNumber, int $character): array
    {
        $this->ensureAutoloaderRegistered();

        // 1. Check template @use directives
        $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
        $targetClass = null;
        $accessorClass = null;

        if (isset($importedUses[$classOrAlias])) {
            $targetClass = $importedUses[$classOrAlias]['class'];
        } elseif (\App\Lsp\Features\Facades\FacadeMap::isFacadeOrAlias($classOrAlias)) {
            $targetClass = \App\Lsp\Features\Facades\FacadeMap::resolve($classOrAlias);
            $accessorClass = \App\Lsp\Features\Facades\FacadeMap::resolveAccessor($classOrAlias);
        } else {
            $targetClass = '\\' . ltrim($classOrAlias, '\\');
        }

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $character - strlen($memberPrefix)],
            'end' => ['line' => $lineNumber, 'character' => $character],
        ];

        $completions = [];
        $seenMembers = [];

        // 2. Resolve members from $targetClass (static methods, constants, @method annotations)
        $cleanTarget = ltrim($targetClass, '\\');
        if (class_exists($cleanTarget) || interface_exists($cleanTarget) || enum_exists($cleanTarget)) {
            try {
                $ref = new ReflectionClass($cleanTarget);

                // Static methods
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->isStatic()) {
                        $mName = $method->getName();
                        if (str_starts_with($mName, '__')) continue;
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($mName), strtolower($memberPrefix))) continue;
                        if (isset($seenMembers[$mName])) continue;
                        $seenMembers[$mName] = true;

                        $sig = $this->formatMethodSignature($method);
                        $retType = $method->hasReturnType() ? (string) $method->getReturnType() : 'mixed';
                        $numReq = $method->getNumberOfRequiredParameters();
                        $newText = $numReq > 0 ? "{$mName}(\${1})" : "{$mName}()";

                        $completions[] = [
                            'label' => $mName,
                            'kind' => 2, // Method
                            'detail' => "public static {$mName}{$sig}: {$retType}",
                            'insertTextFormat' => 2, // Snippet format
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => "**{$cleanTarget}::{$mName}**\n\n```php\npublic static function {$mName}{$sig}: {$retType};\n```",
                            ],
                            'textEdit' => [
                                'range' => $range,
                                'newText' => $newText,
                            ],
                        ];
                    }
                }

                // class pseudo-constant
                if (!isset($seenMembers['class']) && ($memberPrefix === '' || str_starts_with('class', strtolower($memberPrefix)))) {
                    $seenMembers['class'] = true;
                    $completions[] = [
                        'label' => 'class',
                        'kind' => 21, // Constant
                        'detail' => "class-string<{$cleanTarget}>",
                        'insertTextFormat' => 1,
                        'documentation' => [
                            'kind' => 'markdown',
                            'value' => "The fully qualified class name of `{$cleanTarget}`.",
                        ],
                        'textEdit' => [
                            'range' => $range,
                            'newText' => 'class',
                        ],
                    ];
                }

                // Constants / Enum cases
                foreach ($ref->getReflectionConstants() as $const) {
                    if ($const->isPublic()) {
                        $cName = $const->getName();
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($cName), strtolower($memberPrefix))) continue;
                        if (isset($seenMembers[$cName])) continue;
                        $seenMembers[$cName] = true;

                        $completions[] = [
                            'label' => $cName,
                            'kind' => 21, // Constant
                            'detail' => "const {$cName}",
                            'insertTextFormat' => 1,
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => "**{$cleanTarget}::{$cName}**",
                            ],
                            'textEdit' => [
                                'range' => $range,
                                'newText' => $cName,
                            ],
                        ];
                    }
                }

                // DocBlock @method static annotations on Facade class
                if ($docComment = $ref->getDocComment()) {
                    $docMethods = $this->docBlockParser->extractMethods($docComment);
                    foreach ($docMethods as $dName => $dInfo) {
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($dName), strtolower($memberPrefix))) continue;
                        if (isset($seenMembers[$dName])) continue;
                        $seenMembers[$dName] = true;

                        $sig = $dInfo['signature'] ?? '()';
                        $hasRequired = false;
                        if (preg_match('/\(\s*([^)=,\s]+)/', $sig, $pm)) {
                            $fp = trim($pm[1]);
                            if ($fp !== '' && $fp !== 'void') {
                                $hasRequired = true;
                            }
                        }
                        $newText = $hasRequired ? "{$dName}(\${1})" : "{$dName}()";

                        $completions[] = [
                            'label' => $dName,
                            'kind' => 2, // Method
                            'detail' => $sig,
                            'insertTextFormat' => 2, // Snippet format
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => "**{$cleanTarget}::{$dName}** (Facade method)\n\n```php\npublic static function {$dName}{$sig};\n```",
                            ],
                            'textEdit' => [
                                'range' => $range,
                                'newText' => $newText,
                            ],
                        ];
                    }
                }
            } catch (\Throwable) {}
        }

        // 3. If it is a Facade, also resolve instance methods from the underlying accessor
        if ($accessorClass !== null && $accessorClass !== $targetClass) {
            $cleanAccessor = ltrim($accessorClass, '\\');
            if (class_exists($cleanAccessor) || interface_exists($cleanAccessor)) {
                try {
                    $refAcc = new ReflectionClass($cleanAccessor);
                    foreach ($refAcc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                        $mName = $method->getName();
                        if (str_starts_with($mName, '__')) continue;
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($mName), strtolower($memberPrefix))) continue;
                        if (isset($seenMembers[$mName])) continue;
                        $seenMembers[$mName] = true;

                        $sig = $this->formatMethodSignature($method);
                        $retType = $method->hasReturnType() ? (string) $method->getReturnType() : 'mixed';
                        $numReq = $method->getNumberOfRequiredParameters();
                        $newText = $numReq > 0 ? "{$mName}(\${1})" : "{$mName}()";

                        $completions[] = [
                            'label' => $mName,
                            'kind' => 2, // Method
                            'detail' => "public {$mName}{$sig}: {$retType} (via {$cleanAccessor})",
                            'insertTextFormat' => 2, // Snippet format
                            'documentation' => [
                                'kind' => 'markdown',
                                'value' => "**{$classOrAlias}::{$mName}**\n\n*Proxying to:* `{$cleanAccessor}::{$mName}`\n\n```php\npublic function {$mName}{$sig}: {$retType};\n```",
                            ],
                            'textEdit' => [
                                'range' => $range,
                                'newText' => $newText,
                            ],
                        ];
                    }

                    if ($docComment = $refAcc->getDocComment()) {
                        $docMethods = $this->docBlockParser->extractMethods($docComment);
                        foreach ($docMethods as $dName => $dInfo) {
                            if ($memberPrefix !== '' && !str_starts_with(strtolower($dName), strtolower($memberPrefix))) continue;
                            if (isset($seenMembers[$dName])) continue;
                            $seenMembers[$dName] = true;

                            $sig = $dInfo['signature'] ?? '()';
                            $hasRequired = false;
                            if (preg_match('/\(\s*([^)=,\s]+)/', $sig, $pm)) {
                                $fp = trim($pm[1]);
                                if ($fp !== '' && $fp !== 'void') {
                                    $hasRequired = true;
                                }
                            }
                            $newText = $hasRequired ? "{$dName}(\${1})" : "{$dName}()";

                            $completions[] = [
                                'label' => $dName,
                                'kind' => 2,
                                'detail' => $sig,
                                'insertTextFormat' => 2, // Snippet format
                                'documentation' => [
                                    'kind' => 'markdown',
                                    'value' => "**{$classOrAlias}::{$dName}** (via {$cleanAccessor})\n\n```php\npublic function {$dName}{$sig};\n```",
                                ],
                                'textEdit' => [
                                    'range' => $range,
                                    'newText' => $newText,
                                ],
                            ];
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        return $completions;
    }

    public function getUseDirectiveCompletions(string $prefix, int $lineNumber, int $character): array
    {
        $completions = [];
        $lowPrefix = strtolower($prefix);

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $character - strlen($prefix)],
            'end' => ['line' => $lineNumber, 'character' => $character],
        ];

        // 1. Eloquent Models
        try {
            $models = $this->project->index->models();
            foreach ($models as $m) {
                $class = is_array($m) ? ($m['class'] ?? ($m['name'] ?? '')) : (string) $m;
                if ($class === '') continue;
                $cleanClass = ltrim($class, '\\');
                if ($lowPrefix !== '' && !str_starts_with(strtolower($cleanClass), $lowPrefix) && !str_starts_with(strtolower(class_basename($cleanClass)), $lowPrefix)) {
                    continue;
                }
                $completions[] = [
                    'label' => $cleanClass,
                    'kind' => 7, // Class
                    'detail' => 'Eloquent Model',
                    'textEdit' => [
                        'range' => $range,
                        'newText' => $cleanClass,
                    ],
                ];
            }
        } catch (\Throwable) {}

        // 2. Global Facades and helpers
        foreach (\App\Lsp\Features\Facades\FacadeMap::all() as $alias => $fqcn) {
            $cleanFqcn = ltrim($fqcn, '\\');
            if ($lowPrefix !== '' && !str_starts_with(strtolower($cleanFqcn), $lowPrefix) && !str_starts_with(strtolower($alias), $lowPrefix)) {
                continue;
            }
            $completions[] = [
                'label' => $cleanFqcn,
                'kind' => 7, // Class
                'detail' => \App\Lsp\Features\Facades\FacadeMap::description($alias),
                'textEdit' => [
                    'range' => $range,
                    'newText' => $cleanFqcn,
                ],
            ];
        }

        return $completions;
    }

    public function getExpressionClassCompletions(Document $document, string $text, int $lineNumber, int $character): array
    {
        $lastEchoPos = false;
        $p1 = strrpos($text, '{{');
        $p2 = strrpos($text, '{!!');
        if ($p1 !== false && $p2 !== false) $lastEchoPos = max($p1, $p2);
        elseif ($p1 !== false) $lastEchoPos = $p1;
        elseif ($p2 !== false) $lastEchoPos = $p2;

        $lastEchoClose = false;
        $c1 = strrpos($text, '}}');
        $c2 = strrpos($text, '!!}');
        if ($c1 !== false && $c2 !== false) $lastEchoClose = max($c1, $c2);
        elseif ($c1 !== false) $lastEchoClose = $c1;
        elseif ($c2 !== false) $lastEchoClose = $c2;

        $inEcho = ($lastEchoPos !== false && ($lastEchoClose === false || $lastEchoPos > $lastEchoClose));

        $inDirective = false;
        if (preg_match('/(?:@if|@elseif|@unless|@while|@for|@foreach|@switch|@case|@php)\s*\(.*$/', $text, $dMatch)) {
            $substr = $dMatch[0];
            if (substr_count($substr, '(') > substr_count($substr, ')')) {
                $inDirective = true;
            }
        }

        $inPhpBlock = (bool) preg_match('/@php\s+[^@]*$/', $text);
        $inBoundAttr = (bool) preg_match('/(?::|wire:|x-bind:)[a-zA-Z0-9_-]+=(?:"[^"]*|\'[^\']*)$/', $text);

        if (!$inEcho && !$inDirective && !$inPhpBlock && !$inBoundAttr) {
            return [];
        }

        if (!preg_match('/(?<![\$\w\->\?:\/\\\\])([a-zA-Z_][a-zA-Z0-9_]*)$/', $text, $m)) {
            return [];
        }

        $prefix = $m[1];
        $range = [
            'start' => ['line' => $lineNumber, 'character' => $character - strlen($prefix)],
            'end' => ['line' => $lineNumber, 'character' => $character],
        ];

        $completions = [];

        // 1. Facades and global aliases
        foreach (\App\Lsp\Features\Facades\FacadeMap::completions($prefix) as $item) {
            $item['textEdit'] = [
                'range' => $range,
                'newText' => $item['insertText'] ?? $item['label'],
            ];
            $completions[] = $item;
        }

        // 2. @use imported classes in current template
        $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
        foreach ($importedUses as $alias => $uInfo) {
            if ($prefix !== '' && !str_starts_with(strtolower($alias), strtolower($prefix))) {
                continue;
            }
            $completions[] = [
                'label' => $alias,
                'kind' => 7, // Class
                'detail' => $uInfo['class'],
                'documentation' => [
                    'kind' => 'markdown',
                    'value' => "**{$alias}** (`{$uInfo['class']}`)\n\n*Imported via:* `@use('{$uInfo['class']}')` on line {$uInfo['line']}",
                ],
                'textEdit' => [
                    'range' => $range,
                    'newText' => $alias,
                ],
            ];
        }

        // 3. Global Laravel & PHP Functions & Custom Helpers
        $functionRegistry = new \App\Lsp\Features\Functions\GlobalFunctionRegistry($this->project);
        foreach ($functionRegistry->completions($prefix, $range) as $fItem) {
            $completions[] = $fItem;
        }

        return $completions;
    }

    protected function formatMethodSignature(ReflectionMethod $method): string
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

        return '(' . implode(', ', $params) . ')';
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
     * Qualify a potentially short class name to a fully qualified class name
     * using @use directive imports and the project model index.
     *
     * Handles generics (Collection<User>), arrays (User[]), unions (User|null),
     * and nullable (?User) recursively.
     */
    public function qualifyType(string $type, Document $document): string
    {
        $type = trim($type);
        if ($type === '' || $type === 'mixed' || $type === 'void' || $type === 'null') {
            return $type;
        }

        // Already fully qualified
        if (str_starts_with($type, '\\')) {
            return $type;
        }

        // Nullable: ?User → ?\App\Models\User
        if (str_starts_with($type, '?')) {
            return '?' . $this->qualifyType(substr($type, 1), $document);
        }

        // Generic: Collection<User> or Collection<int, User>
        if (preg_match('/^([a-zA-Z0-9_\\\\]+)<(.+)>$/', $type, $m)) {
            $outer = $this->qualifyType($m[1], $document);
            $innerParts = array_map('trim', explode(',', $m[2]));
            $qualifiedInners = array_map(fn($p) => $this->qualifyType($p, $document), $innerParts);
            return $outer . '<' . implode(', ', $qualifiedInners) . '>';
        }

        // Array suffix: User[]
        if (str_ends_with($type, '[]')) {
            return $this->qualifyType(substr($type, 0, -2), $document) . '[]';
        }

        // Union: User|null or string|int
        if (str_contains($type, '|')) {
            $parts = explode('|', $type);
            return implode('|', array_map(fn($p) => $this->qualifyType(trim($p), $document), $parts));
        }

        // Primitives: don't qualify
        $primitives = ['string', 'int', 'float', 'bool', 'array', 'object', 'callable', 'iterable', 'self', 'static', 'parent', 'true', 'false', 'never', 'stdClass'];
        if (in_array(strtolower($type), $primitives, true)) {
            return $type;
        }

        // Check @use directive imports
        $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
        if (isset($importedUses[$type])) {
            return $importedUses[$type]['class'];
        }

        // Check project model index by basename match
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
