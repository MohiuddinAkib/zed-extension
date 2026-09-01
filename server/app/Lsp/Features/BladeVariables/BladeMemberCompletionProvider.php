<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\DocBlockParser;
use App\Lsp\Analysis\FunctionTypeResolver;
use App\Lsp\Analysis\SemanticIndex;
use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Features\ClassIndex\ClassRegistry;
use App\Lsp\Features\Facades\FacadeMap;
use App\Lsp\Features\Functions\GlobalFunctionRegistry;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\Utf16Position;
use Illuminate\Container\Container;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

class BladeMemberCompletionProvider implements CompletionProvider
{
    public const HIGHER_ORDER_COLLECTION_PROXIES = [
        'average'    => 'Calculate average value by property/method across items',
        'avg'        => 'Calculate average value by property/method across items',
        'contains'   => 'Determine if any item matches the condition or property',
        'each'       => 'Execute a callback / method on each item',
        'every'      => 'Verify that all items match the condition or property',
        'filter'     => 'Filter items using a method or truthy property',
        'first'      => 'Get the first item matching the property/method',
        'flatMap'    => 'Map a collection and flatten the result',
        'groupBy'    => 'Group collection items by property or method',
        'keyBy'      => 'Key collection items by property or method',
        'map'        => 'Transform each item via property access or method call',
        'max'        => 'Get maximum value by property/method across items',
        'min'        => 'Get minimum value by property/method across items',
        'partition'  => 'Separate items into two collections by condition',
        'reject'     => 'Filter out items using a method or truthy property',
        'skipUntil'  => 'Skip items until condition or property is met',
        'skipWhile'  => 'Skip items while condition or property is met',
        'some'       => 'Determine if any item matches the condition or property',
        'sortBy'     => 'Sort items by property or method in ascending order',
        'sortByDesc' => 'Sort items by property or method in descending order',
        'sum'        => 'Sum property or method values across items',
        'takeUntil'  => 'Take items until condition or property is met',
        'takeWhile'  => 'Take items while condition or property is met',
        'unique'     => 'Filter collection so only unique items by property remain',
        'until'      => 'Take items until condition or property is met',
    ];

    protected BladeAstAnalyzer $bladeAnalyzer;

    protected BladeScopeResolver $scopeResolver;

    protected DocBlockParser $docBlockParser;

    protected SemanticIndex $semanticIndex;

    protected FunctionTypeResolver $functionTypeResolver;

    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
        ?SemanticIndex $semanticIndex = null,
        ?FunctionTypeResolver $functionTypeResolver = null,
    ) {
        $this->semanticIndex = $semanticIndex ?? $this->resolveSemanticIndex();
        $this->functionTypeResolver = $functionTypeResolver ?? new FunctionTypeResolver($this->project, semanticIndex: $this->semanticIndex);
        $this->bladeAnalyzer = new BladeAstAnalyzer($this->project, $this->functionTypeResolver);
        $this->scopeResolver = new BladeScopeResolver($this->project, $this->bladeAnalyzer);
        $this->docBlockParser = new DocBlockParser;
    }


    protected function resolveSemanticIndex(): SemanticIndex
    {
        $container = Container::getInstance();

        if ($container->bound(SemanticIndex::class)) {
            return $container->make(SemanticIndex::class);
        }

        return new SemanticIndex($this->project);
    }


    /**
     * Provide member completions (properties, methods, relations) for typed variables in Blade templates.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return [];
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';
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

        // 2. @inject('...' directive completions
        if (str_contains($text, '@inject')) {
            $injectCompletions = $this->getInjectDirectiveCompletions($document, $line, $text, $lineNumber, $character);
            if (!empty($injectCompletions)) {
                return $injectCompletions;
            }
        }

        // 3. Static / Facade member call: Js::, Str::, Auth::, Route::, DB::, AirFlight::, Status::
        if (preg_match('/([a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]*)$/', $text, $staticMatch)) {
            return $this->getStaticMemberCompletions($document, $staticMatch[1], $staticMatch[2], $lineNumber, $character);
        }

        // 3b. Fluent Static / Model / Facade method chains: User::where('...')-> or User::query()-> or Str::of('...')-> or Storage::disk('s3')->
        if (preg_match('/([a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]+)(?:\(([^()]*)\))?((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $classOrAlias = $matches[1];
            $staticMethod = $matches[2];
            $staticArgs = $matches[3];
            $chain = $matches[4];
            $memberPrefix = $matches[5];

            $qualifiedClass = $this->qualifyType($classOrAlias, $document);
            $cleanClass = ltrim($qualifiedClass, '\\');
            $models = $this->semanticIndex->models();

            if (EloquentBuilderRegistry::isModel($cleanClass, $models)) {
                $initialType = $this->resolveModelStaticCallType($qualifiedClass, $staticMethod);
                $targetType = $this->resolveChainedType($initialType, $chain);
            } elseif (FacadeMap::isFacadeOrAlias($classOrAlias)) {
                $driverRegistry = new \App\Lsp\Analysis\DriverRegistry($this->project);
                $argVal = trim($staticArgs, '\'"');

                if ($classOrAlias === 'Auth' && $staticMethod === 'guard') {
                    $initialType = $driverRegistry->resolveDriverType('auth_guards', $argVal);
                } elseif ($classOrAlias === 'Storage' && in_array($staticMethod, ['disk', 'fake', 'persistentFake'], true)) {
                    $initialType = $driverRegistry->resolveDriverType('filesystem_disks', $argVal);
                } elseif (($classOrAlias === 'DB' || $classOrAlias === 'Database') && $staticMethod === 'connection') {
                    $initialType = $driverRegistry->resolveDriverType('database_connections', $argVal);
                } elseif ($classOrAlias === 'Cache' && in_array($staticMethod, ['store', 'driver'], true)) {
                    $initialType = $driverRegistry->resolveDriverType('cache_stores', $argVal);
                } elseif ($classOrAlias === 'Queue' && $staticMethod === 'connection') {
                    $initialType = $driverRegistry->resolveDriverType('queue_connections', $argVal);
                } elseif ($classOrAlias === 'Mail' && $staticMethod === 'mailer') {
                    $initialType = $driverRegistry->resolveDriverType('mailers', $argVal);
                } elseif ($classOrAlias === 'Broadcast' && $staticMethod === 'connection') {
                    $initialType = $driverRegistry->resolveDriverType('broadcasters', $argVal);
                } elseif ($classOrAlias === 'Redis' && $staticMethod === 'connection') {
                    $initialType = $driverRegistry->resolveDriverType('redis_connections', $argVal);
                } elseif ($classOrAlias === 'Str' && $staticMethod === 'of') {
                    $initialType = '\Illuminate\Support\Stringable';
                } elseif ($classOrAlias === 'DB' && in_array($staticMethod, ['table', 'query'], true)) {
                    $initialType = '\Illuminate\Database\Query\Builder';
                } else {
                    $accessor = FacadeMap::resolveAccessor($classOrAlias);
                    $facadeClass = FacadeMap::resolve($classOrAlias);
                    $rootType = $accessor ?? $facadeClass;
                    $initialType = $this->resolveMethodReturnType($rootType, $staticMethod);
                }
                $targetType = $this->resolveChainedType($initialType, $chain);
            } else {
                $initialType = $this->resolveMethodReturnType($cleanClass, $staticMethod);
                $targetType = $this->resolveChainedType($initialType, $chain);
            }
        }
        // 4. Container call: app('db.connection')-> or resolve('db')-> or app(PaymentService::class)->
        elseif (preg_match('/(?:app|resolve)\s*\(\s*([\'"][a-zA-Z0-9_.\/\\\\-]+[\'"]|[a-zA-Z0-9_\\\\]+::class)\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $rawBinding = $matches[1];
            $chain = $matches[2];
            $memberPrefix = $matches[3];
            $bindingKey = str_ends_with($rawBinding, '::class')
                ? substr($rawBinding, 0, -7)
                : trim($rawBinding, '\'"');
            $rootType = $this->semanticIndex->containerBindingType($bindingKey);
            if ($rootType) {
                $targetType = $this->resolveChainedType($rootType, $chain);
            }
        }
        // 5. Tap helper: tap($var)-> or tap(new Class())->
        elseif (preg_match('/tap\s*\(\s*(?:\$([a-zA-Z0-9_]+)|new\s+([a-zA-Z0-9_\\\\]+)(?:\([^\)]*\))?)\s*\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $varName = $matches[1];
            $newClass = $matches[2];
            $chain = $matches[3];
            $memberPrefix = $matches[4];

            if ($varName !== '') {
                $varType = $this->resolveVariableType($varName, $document, $position) ?? 'mixed';
                $varType = $this->qualifyType($varType, $document);
                $targetType = $this->resolveChainedType($varType, $chain);
            } elseif ($newClass !== '') {
                $rootType = $this->qualifyType($newClass, $document);
                $targetType = $this->resolveChainedType($rootType, $chain);
            }
        }
        // 6. Global helper: config()->, auth('web')->, fluent($data)->, etc.
        elseif (preg_match('/(?:\b|\()([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(([^()]*)\)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^()]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $funcName = $matches[1];
            $rawArgs = $matches[2];
            $chain = $matches[3];
            $memberPrefix = $matches[4];

            $args = $this->functionTypeResolver->splitArguments($rawArgs);
            $rootType = $this->functionTypeResolver->resolveCall($funcName, $args, $document, $position);

            if ($rootType) {
                $targetType = $this->resolveChainedType($rootType, $chain);
            }
        }

        // 7. Object member access: $var-> or $var?-> or $var->chain->
        elseif (preg_match('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?)*)(?:->|\?->)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $varName = $matches[1];
            $chain = $matches[2];
            $memberPrefix = $matches[3];

            $varType = $this->resolveVariableType($varName, $document, $position);
            if ($varType !== null) {
                $varType = $this->qualifyType($varType, $document);
                $targetType = $this->resolveChainedType($varType, $chain);
            }
        }
        // 8. Array key access: $var[' or $var[" or $var[ or $var['key']['
        elseif (preg_match('/\$([a-zA-Z0-9_]+)((?:(?:->|\?->)[a-zA-Z0-9_]+(?:\([^\)]*\))?|\[[^\]]*\])*)\[([\'"]?)([a-zA-Z0-9_]*)$/', $text, $matches)) {
            $isArrayAccess = true;
            $varName = $matches[1];
            $chain = $matches[2];
            $quoteChar = $matches[3]; // "'" or '"' or ''
            $memberPrefix = $matches[4];

            $varType = $this->resolveVariableType($varName, $document, $position);
            if ($varType !== null) {
                $varType = $this->qualifyType($varType, $document);
                $targetType = $this->resolveChainedType($varType, $chain);
            }
        } else {
            // 9. Global Facades and @use class suggestions in expression context
            return $this->getExpressionClassCompletions($document, $text, $lineNumber, $character);
        }

        if (!$targetType || $targetType === 'mixed') {
            return [];
        }

        $this->ensureAutoloaderRegistered();

        $members = $this->resolveMembersForType($targetType, $varName);

        if (empty($members)) {
            return [];
        }

        $range = [
            'start' => [
                'line'      => $lineNumber,
                'character' => $character - Utf16Position::length($memberPrefix),
            ],
            'end' => [
                'line'      => $lineNumber,
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
                10      => '0_', // Properties / Array keys
                5       => '1_',  // Relations/Fields
                2       => '2_',  // Methods
                21      => '3_', // Constants
                default => '4_',
            };

            $labelDetails = [];
            if ($isMethod) {
                $paramSig = $member['paramSignature'] ?? null;
                if ($paramSig === null && preg_match('/\((.*?)\)/', $detail, $mSig)) {
                    $paramSig = '(' . $mSig[1] . ')';
                }
                $retType = $member['returnType'] ?? null;
                if ($retType === null && str_contains($detail, '): ')) {
                    $retType = trim(explode('): ', $detail)[1]);
                }
                if ($paramSig !== null) {
                    $labelDetails['detail'] = $paramSig;
                }
                if ($retType !== null && $retType !== '') {
                    $labelDetails['description'] = $retType;
                }
            } else {
                $desc = $member['returnType'] ?? ($detail !== '' ? $detail : null);
                if ($desc !== null) {
                    $labelDetails['description'] = $desc;
                }
            }

            $item = [
                'label'            => $label,
                'kind'             => $kind,
                'detail'           => $detail,
                'insertTextFormat' => $insertTextFormat,
                'textEdit'         => [
                    'range'   => $range,
                    'newText' => $newText,
                ],
                'sortText' => $sortPrefix . $name,
            ];

            if (!empty($labelDetails)) {
                $item['labelDetails'] = $labelDetails;
            }

            if ($doc !== '') {
                $item['documentation'] = [
                    'kind'  => 'markdown',
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
    protected function resolveMembersForType(string $type, string $varName = ''): array
    {
        if (str_contains($type, '|')) {
            $unionMembers = [];
            foreach (explode('|', $type) as $subType) {
                $subType = trim($subType);
                if ($subType === '' || in_array($subType, ['mixed', 'null', 'void', 'never', 'false', 'true', 'string', 'int', 'float', 'bool', 'array'], true)) {
                    continue;
                }
                foreach ($this->resolveMembersForType($subType, $varName) as $k => $v) {
                    if (!isset($unionMembers[$k])) {
                        $unionMembers[$k] = $v;
                    }
                }
            }
            if (!empty($unionMembers)) {
                return $unionMembers;
            }
        }

        if (str_contains($type, '&')) {
            $intersectionMembers = [];
            foreach (explode('&', $type) as $subType) {
                $subType = trim($subType);
                if ($subType === '' || in_array($subType, ['mixed', 'null', 'void', 'never', 'false', 'true'], true)) {
                    continue;
                }
                foreach ($this->resolveMembersForType($subType, $varName) as $k => $v) {
                    if (!isset($intersectionMembers[$k])) {
                        $intersectionMembers[$k] = $v;
                    }
                }
            }
            if (!empty($intersectionMembers)) {
                return $intersectionMembers;
            }
        }

        $members = [];
        $cleanType = ltrim(preg_replace('/\|null|\?/', '', $type), '\\');
        $baseClass = preg_replace('/<.*>$/', '', $cleanType);
        $baseClass = ltrim(preg_replace('/\[\]$/', '', $baseClass), '\\');


        // 1. Loop variable: $loop->index, $loop->iteration, $loop->first, $loop->last, etc.
        if ($varName === 'loop' && ($cleanType === 'stdClass' || $cleanType === 'object' || $baseClass === 'stdClass' || $baseClass === 'object' || str_contains($cleanType, 'Loop'))) {
            $loopProps = [
                'index'     => ['type' => 'int', 'doc' => 'The index of the current loop iteration (starts at 0).'],
                'iteration' => ['type' => 'int', 'doc' => 'The current loop iteration (starts at 1).'],
                'remaining' => ['type' => 'int', 'doc' => 'The iterations remaining in the loop.'],
                'count'     => ['type' => 'int', 'doc' => 'The total number of items in the array being iterated.'],
                'first'     => ['type' => 'bool', 'doc' => 'Whether this is the first iteration through the loop.'],
                'last'      => ['type' => 'bool', 'doc' => 'Whether this is the last iteration through the loop.'],
                'even'      => ['type' => 'bool', 'doc' => 'Whether this is an even iteration through the loop.'],
                'odd'       => ['type' => 'bool', 'doc' => 'Whether this is an odd iteration through the loop.'],
                'depth'     => ['type' => 'int', 'doc' => 'The nesting level of the current loop.'],
                'parent'    => ['type' => '?object', 'doc' => 'When in a nested loop, the parent loop variable.'],
            ];
            foreach ($loopProps as $pName => $pInfo) {
                $members[$pName] = [
                    'name'          => $pName,
                    'kind'          => 10, // Property
                    'detail'        => $pInfo['type'],
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
                    'name'          => $propName,
                    'kind'          => 10, // Property
                    'detail'        => $propType,
                    'documentation' => "`@var {$propType} \${$propName}`",
                ];
            }

            return $members;
        }

        // 2b. Fluent container: Illuminate\Support\Fluent or Fluent<array{...}>
        if ($baseClass === 'Illuminate\Support\Fluent' || $baseClass === 'Fluent') {
            if (preg_match('/(?:Illuminate\\\\Support\\\\)?Fluent<(.+)>$/', $cleanType, $fluentShapeMatch)) {
                $innerShapeStr = $fluentShapeMatch[1];
                $dataPathResolver = new \App\Lsp\Analysis\DataPathResolver($this->project);
                $innerKeys = $dataPathResolver->resolveKeysForType(\App\Lsp\Semantics\TypeRef::fromString($innerShapeStr));
                foreach ($innerKeys as $propName => $info) {
                    $propType = $info['type']->displayName;
                    $members[$propName] = [
                        'name'          => $propName,
                        'kind'          => 10, // Property
                        'detail'        => $propType,
                        'documentation' => "**Dynamic Property (Fluent)**\n\n*Type:* `{$propType}`",
                    ];
                }
            }

            $fluentMethods = [
                'get'           => ['sig' => '($key, $default = null)', 'ret' => 'mixed', 'snippet' => 'get(${1:\$key})'],
                'set'           => ['sig' => '($key, $value)', 'ret' => '$this', 'snippet' => 'set(${1:\$key}, ${2:\$value})'],
                'has'           => ['sig' => '($key)', 'ret' => 'bool', 'snippet' => 'has(${1:\$key})'],
                'value'         => ['sig' => '($key, $default = null)', 'ret' => 'mixed', 'snippet' => 'value(${1:\$key})'],
                'scope'         => ['sig' => '($key, $callback = null)', 'ret' => 'mixed', 'snippet' => 'scope(${1:\$key})'],
                'only'          => ['sig' => '($keys)', 'ret' => 'array', 'snippet' => 'only(${1:\$keys})'],
                'except'        => ['sig' => '($keys)', 'ret' => 'array', 'snippet' => 'except(${1:\$keys})'],
                'string'        => ['sig' => '($key, $default = null)', 'ret' => '\Illuminate\Support\Stringable', 'snippet' => 'string(${1:\$key})'],
                'integer'       => ['sig' => '($key, $default = null)', 'ret' => 'int', 'snippet' => 'integer(${1:\$key})'],
                'boolean'       => ['sig' => '($key, $default = null)', 'ret' => 'bool', 'snippet' => 'boolean(${1:\$key})'],
                'array'         => ['sig' => '($key, $default = null)', 'ret' => 'array', 'snippet' => 'array(${1:\$key})'],
                'collection'    => ['sig' => '($key, $default = null)', 'ret' => '\Illuminate\Support\Collection', 'snippet' => 'collection(${1:\$key})'],
                'date'          => ['sig' => '($key, $format = null, $tz = null)', 'ret' => '\Illuminate\Support\Carbon', 'snippet' => 'date(${1:\$key})'],
                'enum'          => ['sig' => '($key, $enumClass)', 'ret' => 'mixed', 'snippet' => 'enum(${1:\$key}, ${2:\$enumClass})'],
                'object'        => ['sig' => '($key, $default = null)', 'ret' => 'object', 'snippet' => 'object(${1:\$key})'],
                'toArray'       => ['sig' => '()', 'ret' => 'array', 'snippet' => 'toArray()'],
                'toJson'        => ['sig' => '($options = 0)', 'ret' => 'string', 'snippet' => 'toJson()'],
                'jsonSerialize' => ['sig' => '()', 'ret' => 'array', 'snippet' => 'jsonSerialize()'],
            ];

            foreach ($fluentMethods as $mName => $mInfo) {
                if (!isset($members[$mName])) {
                    $members[$mName] = [
                        'name'           => $mName,
                        'kind'           => 2, // Method
                        'detail'         => $mInfo['sig'] . ': ' . $mInfo['ret'],
                        'documentation'  => "```php\npublic function {$mName}{$mInfo['sig']}: {$mInfo['ret']};\n```\n\n*Fluent method*",
                        'isMethod'       => true,
                        'requiredParams' => str_contains($mInfo['sig'], '=') ? 0 : (str_contains($mInfo['sig'], '$') ? 1 : 0),
                        'snippet'        => $mInfo['snippet'],
                    ];
                }
            }

            return $members;
        }

        // 3. Eloquent Model Attributes & Relations from Index
        $models = $this->semanticIndex->models();
        if (isset($models[$baseClass])) {
            $modelData = $models[$baseClass];
            foreach ($modelData['attributes'] ?? [] as $attr) {
                $attrName = $attr['name'] ?? '';
                if ($attrName !== '') {
                    $attrType = $attr['cast'] ?? $attr['type'] ?? 'mixed';
                    $members[$attrName] = [
                        'name'          => $attrName,
                        'kind'          => 10, // Property
                        'detail'        => $attrType,
                        'documentation' => "**Eloquent Attribute**\n\n*Type:* `{$attrType}`",
                    ];
                }
            }

            if (!isset($members['id'])) {
                $members['id'] = [
                    'name'          => 'id',
                    'kind'          => 10,
                    'detail'        => 'int|string',
                    'documentation' => '**Eloquent Primary Key**' . "\n\n" . '*Type:* `int|string`',
                ];
            }

            foreach ($modelData['relations'] ?? [] as $rel) {
                $relName = $rel['name'] ?? '';
                if ($relName !== '') {
                    $relType = $rel['type'] ?? 'Relation';
                    $relRelated = $rel['related'] ?? 'Model';
                    $members[$relName] = [
                        'name'          => $relName,
                        'kind'          => 5, // Field / Relation
                        'detail'        => "{$relType} -> {$relRelated}",
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
                    'name'          => $proxyName,
                    'kind'          => 10, // Property
                    'detail'        => "HigherOrderCollectionProxy<{$shortItemType}>",
                    'documentation' => "**Higher Order Collection Proxy (`{$proxyName}`)**\n\n```php\n\$collection->{$proxyName}->...\n```\n\n{$proxyDesc}.\n\n*Target Item Type:* `{$itemType}`",
                ];
            }
        }

        // 5. Higher Order Tap Proxy
        $members['tap'] = [
            'name'          => 'tap',
            'kind'          => 10, // Property
            'detail'        => 'HigherOrderTapProxy<' . class_basename($cleanType) . '>',
            'documentation' => "**Higher Order Tap Proxy**\n\n```php\n\$var->tap()->... or \$var->tap->...\n```\n\nPasses the target object into a closure and returns it.",
        ];

        // 6. Eloquent Builder & Model query methods
        $isBuilder = EloquentBuilderRegistry::isBuilder($type);
        $isModel = EloquentBuilderRegistry::isModel($baseClass, $models);
        if ($isBuilder || $isModel) {
            $targetModel = $isBuilder
                ? (EloquentBuilderRegistry::extractModelFromBuilder($type) ?? $baseClass)
                : $baseClass;

            $builderMembers = $this->semanticIndex->eloquentMembersForModel($targetModel, false);
            foreach ($builderMembers as $bName => $bMember) {
                if (!isset($members[$bName])) {
                    $members[$bName] = $bMember;
                }
            }
        }

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
                                'name'          => $pName,
                                'kind'          => 10,
                                'detail'        => $pType,
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
                                'name'           => $mName,
                                'kind'           => 2,
                                'detail'         => $sig,
                                'documentation'  => "```php\npublic {$mInfo['name']}{$sig}\n```\n\n*Source:* `{$targetRef->getName()}`",
                                'isMethod'       => true,
                                'requiredParams' => $hasRequired ? 1 : 0,
                                'snippet'        => $hasRequired ? "{$mName}(\${1})" : "{$mName}()",
                            ];
                        }
                    }
                }

                // 4. Backed Enum properties (value, name)
                if ($targetRef->isEnum()) {
                    $members['name'] = [
                        'name'          => 'name',
                        'kind'          => 10,
                        'detail'        => 'string',
                        'documentation' => 'The case name of this Enum.',
                    ];
                    $members['value'] = [
                        'name'          => 'value',
                        'kind'          => 10,
                        'detail'        => 'string|int',
                        'documentation' => 'The scalar value of this Backed Enum.',
                    ];
                }

                // 5. Public Properties
                foreach ($targetRef->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                    $pName = $prop->getName();
                    if (!isset($members[$pName])) {
                        $pType = $prop->getType() ? (string) $prop->getType() : 'mixed';
                        $members[$pName] = [
                            'name'          => $pName,
                            'kind'          => 10,
                            'detail'        => $pType,
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
                            'name'           => $mName,
                            'kind'           => 2, // Method
                            'detail'         => "{$paramSignature}: {$returnType}",
                            'documentation'  => "```php\npublic function {$mName}{$paramSignature}: {$returnType}\n```",
                            'isMethod'       => true,
                            'requiredParams' => $numRequired,
                            'snippet'        => $numRequired > 0 ? "{$mName}(\${1})" : "{$mName}()",
                        ];
                    }
                }
            }
        } catch (Throwable) {
        }

        return $members;
    }

    /**
     * Resolve variable type from Blade scope, docblocks, attributes, or local assignments.
     */
    protected function resolveVariableType(string $varName, Document $document, array $position): ?string
    {
        // 1. Blade view variables
        if (str_ends_with($document->uri, '.blade.php')) {
            $viewKey = $this->resolveViewKey($document->uri);
            $variables = $this->collectVariablesForView($document, $viewKey, $position);
            if (isset($variables[$varName])) {
                return $variables[$varName]['type'] ?? 'mixed';
            }
        }

        $content = $document->content;

        // 2. Docblock @var / @param tags
        if (preg_match_all('/\/\*\*[\s\S]*?\*\//', $content, $matches)) {
            foreach (array_reverse($matches[0]) as $docComment) {
                $varTags = $this->docBlockParser->extractVarTags($docComment);
                if (isset($varTags[$varName])) {
                    return $varTags[$varName];
                }
                if (isset($varTags['']) && count($varTags) === 1) {
                    return $varTags[''];
                }
                $paramTags = $this->docBlockParser->extractParamTags($docComment);
                if (isset($paramTags[$varName])) {
                    return $paramTags[$varName];
                }
            }
        }

        // 3. Attribute-decorated property / parameter: #[Auth('web')] $guard or #[Storage('s3')] $disk
        if (preg_match('/#\[\s*\\\\?([a-zA-Z0-9_\\\\]+)\s*(?:\(([^)]*)\))?\s*\]\s*(?:(?:public|protected|private|readonly|\s)+\s+)?(?:([a-zA-Z0-9_\\\\]+)\s+)?\$' . preg_quote($varName, '/') . '\b/', $content, $attrM)) {
            $attrName = $attrM[1];
            $attrArg = !empty($attrM[2]) ? trim($attrM[2], '\'"') : null;
            $typeHint = !empty($attrM[3]) ? $attrM[3] : null;

            $attrRegistry = new \App\Lsp\Analysis\AttributeIntelligenceRegistry($this->project);
            $injectedType = $attrRegistry->resolveInjectedType($attrName, $attrArg);
            if ($injectedType && $injectedType !== 'mixed') {
                return $injectedType;
            }
            if ($typeHint) {
                return $typeHint;
            }
        }

        // 4. Preceding assignment statement inference via DataPathResolver
        $dataPathResolver = new \App\Lsp\Analysis\DataPathResolver($this->project);
        $inferred = $dataPathResolver->inferVariableType($varName, $document, $position);
        if ($inferred !== null) {
            return $inferred->displayName;
        }

        return null;
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
        if (preg_match_all('/(?:->|\?->|\[\s*[\'"]?)([a-zA-Z0-9_]+)(?:[\'"]?\s*\]|\(([^)]*)\))?/', $chain, $m, PREG_SET_ORDER)) {
            $driverRegistry = new \App\Lsp\Analysis\DriverRegistry($this->project);
            foreach ($m as $match) {
                $member = $match[1];
                $args = $match[2] ?? '';
                $cleanArg = trim($args, '\'"');

                // Higher order tap proxy
                if ($member === 'tap') {
                    continue;
                }

                // Driver methods on managers or facades
                if ($member === 'guard' && $cleanArg !== '') {
                    $currentType = $driverRegistry->resolveDriverType('auth_guards', $cleanArg);
                    continue;
                }
                if ($member === 'disk' && $cleanArg !== '') {
                    $currentType = $driverRegistry->resolveDriverType('filesystem_disks', $cleanArg);
                    continue;
                }
                if (in_array($member, ['store', 'driver'], true) && $cleanArg !== '') {
                    $currentType = $driverRegistry->resolveDriverType('cache_stores', $cleanArg);
                    continue;
                }
                if ($member === 'connection' && $cleanArg !== '') {
                    $currentType = $driverRegistry->resolveDriverType('database_connections', $cleanArg);
                    continue;
                }
                if ($member === 'mailer' && $cleanArg !== '') {
                    $currentType = $driverRegistry->resolveDriverType('mailers', $cleanArg);
                    continue;
                }
                if ($member === 'channel' && $cleanArg !== '') {
                    $currentType = $driverRegistry->resolveDriverType('log_channels', $cleanArg);
                    continue;
                }

                // If currentType is Fluent
                if (str_contains($currentType, 'Fluent')) {
                    $dataPathResolver = new \App\Lsp\Analysis\DataPathResolver($this->project);
                    $fluentReturn = $dataPathResolver->inferFluentMethodReturnType(
                        \App\Lsp\Semantics\TypeRef::fromString($currentType),
                        $member,
                        $this->functionTypeResolver->splitArguments($args),
                        new Document('memory://fluent-chain.php', ''),
                    );

                    if ((string) $fluentReturn !== 'mixed') {
                        $currentType = $fluentReturn->displayName;
                        continue;
                    }

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

                    // Check if $member is a dynamic property on the inner shape
                    if (preg_match('/(?:Illuminate\\\\Support\\\\)?Fluent<(.+)>$/', $currentType, $flM)) {
                        $innerKeys = $dataPathResolver->resolveKeysForType(\App\Lsp\Semantics\TypeRef::fromString($flM[1]));
                        if (isset($innerKeys[$member])) {
                            $currentType = $innerKeys[$member]['type']->displayName;
                            continue;
                        }
                    }
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

                // If currentType is Builder
                if (EloquentBuilderRegistry::isBuilder($currentType)) {
                    $modelType = EloquentBuilderRegistry::extractModelFromBuilder($currentType) ?? 'mixed';
                    if (in_array($member, [
                        'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween',
                        'orWhere', 'with', 'without', 'withCount', 'withSum', 'withAvg', 'withMin',
                        'withMax', 'withExists', 'select', 'addSelect', 'latest', 'oldest', 'orderBy',
                        'orderByDesc', 'groupBy', 'having', 'limit', 'take', 'offset', 'skip', 'when',
                        'unless', 'scopes', 'withTrashed', 'onlyTrashed', 'withoutTrashed', 'lockForUpdate',
                        'sharedLock', 'distinct', 'has', 'doesntHave', 'whereHas', 'whereDoesntHave',
                        'orWhereHas', 'whereAll', 'whereAny', 'whereBinary', 'whereJsonContains',
                        'whereJsonLength', 'dump', 'tap',
                    ], true) || str_starts_with($member, 'where')) {
                        continue;
                    }
                    if (in_array($member, ['get', 'all', 'createMany'], true)) {
                        $currentType = $modelType !== 'mixed' ? "\\Illuminate\\Database\\Eloquent\\Collection<int, {$modelType}>" : '\\Illuminate\\Database\\Eloquent\\Collection';
                        continue;
                    }
                    if (in_array($member, ['first', 'firstOrFail', 'find', 'findOrFail', 'firstOrCreate', 'updateOrCreate', 'create', 'make', 'sole', 'findOrNew', 'firstOrNew', 'forceCreate'], true)) {
                        $currentType = $modelType;
                        continue;
                    }
                    if (in_array($member, ['paginate', 'simplePaginate', 'cursorPaginate'], true)) {
                        $currentType = $modelType !== 'mixed' ? "\\Illuminate\\Pagination\\LengthAwarePaginator<int, {$modelType}>" : '\\Illuminate\\Pagination\\LengthAwarePaginator';
                        continue;
                    }
                    if (in_array($member, ['cursor', 'lazy', 'lazyById'], true)) {
                        $currentType = $modelType !== 'mixed' ? "\\Illuminate\\Support\\LazyCollection<int, {$modelType}>" : '\\Illuminate\\Support\\LazyCollection';
                        continue;
                    }
                    if (in_array($member, ['pluck'], true)) {
                        $currentType = '\\Illuminate\\Support\\Collection';
                        continue;
                    }
                    if (in_array($member, ['count', 'update', 'delete', 'destroy', 'upsert'], true)) {
                        $currentType = 'int';
                        continue;
                    }
                    if (in_array($member, ['exists', 'doesntExist', 'insert'], true)) {
                        $currentType = 'bool';
                        continue;
                    }
                }

                // If calling collection transformations returning self collection
                if (in_array($member, ['where', 'filter', 'map', 'values', 'sortBy', 'sortByDesc', 'take', 'skip', 'slice', 'reject', 'reverse', 'flatten', 'collapse'], true)) {
                    continue;
                }

                $members = $this->resolveMembersForType($currentType);
                if (isset($members[$member])) {
                    $detail = $members[$member]['detail'] ?? '';
                    $ret = '';
                    if (str_contains($detail, '): ')) {
                        $ret = trim(explode('): ', $detail)[1]);
                    } elseif ($detail !== '') {
                        $ret = $detail;
                    }

                    $cleanRet = ltrim(preg_replace('/\|null|\?/', '', $ret), '\\');
                    if (in_array($cleanRet, ['static', '$this', 'self'], true)) {
                        // Keep currentType
                    } elseif ($ret !== '' && $ret !== 'mixed') {
                        $currentType = $ret;
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
     * @param  array<string, mixed>|null  $position
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
        } catch (Throwable) {
        }

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
        } elseif (FacadeMap::isFacadeOrAlias($classOrAlias)) {
            $targetClass = FacadeMap::resolve($classOrAlias);
            $accessorClass = FacadeMap::resolveAccessor($classOrAlias);
        } else {
            $targetClass = '\\' . ltrim($classOrAlias, '\\');
        }

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $character - Utf16Position::length($memberPrefix)],
            'end'   => ['line' => $lineNumber, 'character' => $character],
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
                        if (str_starts_with($mName, '__')) {
                            continue;
                        }
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($mName), strtolower($memberPrefix))) {
                            continue;
                        }
                        if (isset($seenMembers[$mName])) {
                            continue;
                        }
                        $seenMembers[$mName] = true;

                        $sig = $this->formatMethodSignature($method);
                        $retType = $method->hasReturnType() ? (string) $method->getReturnType() : 'mixed';
                        $numReq = $method->getNumberOfRequiredParameters();
                        $newText = $numReq > 0 ? "{$mName}(\${1})" : "{$mName}()";

                        $completions[] = [
                            'label'        => $mName,
                            'labelDetails' => [
                                'detail'      => $sig,
                                'description' => $retType,
                            ],
                            'kind'             => 2, // Method
                            'detail'           => "public static {$mName}{$sig}: {$retType}",
                            'insertTextFormat' => 2, // Snippet format
                            'documentation'    => [
                                'kind'  => 'markdown',
                                'value' => "**{$cleanTarget}::{$mName}**\n\n```php\npublic static function {$mName}{$sig}: {$retType};\n```",
                            ],
                            'textEdit' => [
                                'range'   => $range,
                                'newText' => $newText,
                            ],
                        ];
                    }
                }

                // class pseudo-constant
                if (!isset($seenMembers['class']) && ($memberPrefix === '' || str_starts_with('class', strtolower($memberPrefix)))) {
                    $seenMembers['class'] = true;
                    $completions[] = [
                        'label'        => 'class',
                        'labelDetails' => [
                            'description' => "class-string<{$cleanTarget}>",
                        ],
                        'kind'             => 21, // Constant
                        'detail'           => "class-string<{$cleanTarget}>",
                        'insertTextFormat' => 1,
                        'documentation'    => [
                            'kind'  => 'markdown',
                            'value' => "The fully qualified class name of `{$cleanTarget}`.",
                        ],
                        'textEdit' => [
                            'range'   => $range,
                            'newText' => 'class',
                        ],
                    ];
                }

                // Constants / Enum cases
                foreach ($ref->getReflectionConstants() as $const) {
                    if ($const->isPublic()) {
                        $cName = $const->getName();
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($cName), strtolower($memberPrefix))) {
                            continue;
                        }
                        if (isset($seenMembers[$cName])) {
                            continue;
                        }
                        $seenMembers[$cName] = true;

                        $completions[] = [
                            'label'        => $cName,
                            'labelDetails' => [
                                'description' => 'const',
                            ],
                            'kind'             => 21, // Constant
                            'detail'           => "const {$cName}",
                            'insertTextFormat' => 1,
                            'documentation'    => [
                                'kind'  => 'markdown',
                                'value' => "**{$cleanTarget}::{$cName}**",
                            ],
                            'textEdit' => [
                                'range'   => $range,
                                'newText' => $cName,
                            ],
                        ];
                    }
                }

                // DocBlock @method static annotations on Facade class
                if ($docComment = $ref->getDocComment()) {
                    $docMethods = $this->docBlockParser->extractMethods($docComment);
                    foreach ($docMethods as $dName => $dInfo) {
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($dName), strtolower($memberPrefix))) {
                            continue;
                        }
                        if (isset($seenMembers[$dName])) {
                            continue;
                        }
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
                            'label'        => $dName,
                            'labelDetails' => [
                                'detail'      => $sig,
                                'description' => $dInfo['returnType'] ?? 'mixed',
                            ],
                            'kind'             => 2, // Method
                            'detail'           => $sig,
                            'insertTextFormat' => 2, // Snippet format
                            'documentation'    => [
                                'kind'  => 'markdown',
                                'value' => "**{$cleanTarget}::{$dName}** (Facade method)\n\n```php\npublic static function {$dName}{$sig};\n```",
                            ],
                            'textEdit' => [
                                'range'   => $range,
                                'newText' => $newText,
                            ],
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        // 3. If it is a Facade, also resolve instance methods from the underlying accessor
        if ($accessorClass !== null && $accessorClass !== $targetClass) {
            $cleanAccessor = ltrim($accessorClass, '\\');
            if (class_exists($cleanAccessor) || interface_exists($cleanAccessor)) {
                try {
                    $refAcc = new ReflectionClass($cleanAccessor);
                    foreach ($refAcc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                        $mName = $method->getName();
                        if (str_starts_with($mName, '__')) {
                            continue;
                        }
                        if ($memberPrefix !== '' && !str_starts_with(strtolower($mName), strtolower($memberPrefix))) {
                            continue;
                        }
                        if (isset($seenMembers[$mName])) {
                            continue;
                        }
                        $seenMembers[$mName] = true;

                        $sig = $this->formatMethodSignature($method);
                        $retType = $method->hasReturnType() ? (string) $method->getReturnType() : 'mixed';
                        $numReq = $method->getNumberOfRequiredParameters();
                        $newText = $numReq > 0 ? "{$mName}(\${1})" : "{$mName}()";

                        $completions[] = [
                            'label'        => $mName,
                            'labelDetails' => [
                                'detail'      => $sig,
                                'description' => $retType,
                            ],
                            'kind'             => 2, // Method
                            'detail'           => "public {$mName}{$sig}: {$retType} (via {$cleanAccessor})",
                            'insertTextFormat' => 2, // Snippet format
                            'documentation'    => [
                                'kind'  => 'markdown',
                                'value' => "**{$classOrAlias}::{$mName}**\n\n*Proxying to:* `{$cleanAccessor}::{$mName}`\n\n```php\npublic function {$mName}{$sig}: {$retType};\n```",
                            ],
                            'textEdit' => [
                                'range'   => $range,
                                'newText' => $newText,
                            ],
                        ];
                    }

                    if ($docComment = $refAcc->getDocComment()) {
                        $docMethods = $this->docBlockParser->extractMethods($docComment);
                        foreach ($docMethods as $dName => $dInfo) {
                            if ($memberPrefix !== '' && !str_starts_with(strtolower($dName), strtolower($memberPrefix))) {
                                continue;
                            }
                            if (isset($seenMembers[$dName])) {
                                continue;
                            }
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
                                'label'        => $dName,
                                'labelDetails' => [
                                    'detail'      => $sig,
                                    'description' => $dInfo['returnType'] ?? 'mixed',
                                ],
                                'kind'             => 2,
                                'detail'           => $sig,
                                'insertTextFormat' => 2, // Snippet format
                                'documentation'    => [
                                    'kind'  => 'markdown',
                                    'value' => "**{$classOrAlias}::{$dName}** (via {$cleanAccessor})\n\n```php\npublic function {$dName}{$sig};\n```",
                                ],
                                'textEdit' => [
                                    'range'   => $range,
                                    'newText' => $newText,
                                ],
                            ];
                        }
                    }
                } catch (Throwable) {
                }
            }
        }

        // 4. If target class is an Eloquent Model, also provide static Eloquent Builder methods
        $models = $this->semanticIndex->models();
        if (EloquentBuilderRegistry::isModel($cleanTarget, $models)) {
            $builderMembers = $this->semanticIndex->eloquentMembersForModel($cleanTarget, true);
            foreach ($builderMembers as $bName => $bMember) {
                if (isset($seenMembers[$bName])) {
                    continue;
                }
                if ($memberPrefix !== '' && !str_starts_with(strtolower($bName), strtolower($memberPrefix))) {
                    continue;
                }
                $seenMembers[$bName] = true;

                $completions[] = [
                    'label'        => $bName,
                    'labelDetails' => [
                        'detail'      => $bMember['paramSignature'] ?? '()',
                        'description' => $bMember['returnType'] ?? 'mixed',
                    ],
                    'kind'             => 2, // Method
                    'detail'           => $bMember['detail'] ?? '',
                    'insertTextFormat' => 2, // Snippet format
                    'documentation'    => [
                        'kind'  => 'markdown',
                        'value' => $bMember['documentation'] ?? '',
                    ],
                    'textEdit' => [
                        'range'   => $range,
                        'newText' => $bMember['snippet'] ?? "{$bName}()",
                    ],
                ];
            }

            // Pseudo-constant `class` if not already added
            if (!isset($seenMembers['class']) && ($memberPrefix === '' || str_starts_with('class', strtolower($memberPrefix)))) {
                $seenMembers['class'] = true;
                $completions[] = [
                    'label'        => 'class',
                    'labelDetails' => [
                        'description' => "class-string<{$cleanTarget}>",
                    ],
                    'kind'             => 21, // Constant
                    'detail'           => "class-string<{$cleanTarget}>",
                    'insertTextFormat' => 1,
                    'documentation'    => [
                        'kind'  => 'markdown',
                        'value' => "The fully qualified class name of `{$cleanTarget}`.",
                    ],
                    'textEdit' => [
                        'range'   => $range,
                        'newText' => 'class',
                    ],
                ];
            }
        }

        return $completions;
    }

    public function getUseDirectiveCompletions(string $prefix, int $lineNumber, int $character): array
    {
        $completions = [];
        $registry = new ClassRegistry($this->project);
        $matches = $registry->search($prefix, limit: 100);

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $character - Utf16Position::length($prefix)],
            'end'   => ['line' => $lineNumber, 'character' => $character],
        ];

        foreach ($matches as $item) {
            $class = $item['class'];
            $kindStr = $item['kind'] ?? 'Class';
            $kind = match ($kindStr) {
                'Interface' => 8,
                'Enum'      => 13,
                'Trait'     => 14,
                default     => 7,
            };

            $completions[] = [
                'label'        => $class,
                'labelDetails' => [
                    'detail'      => ' (' . $item['name'] . ')',
                    'description' => $kindStr,
                ],
                'kind'          => $kind,
                'detail'        => $item['detail'] ?? $class,
                'documentation' => [
                    'kind'  => 'markdown',
                    'value' => "### `{$class}`\n\n*Type:* `{$kindStr}`" . (!empty($item['path']) ? "\n*Path:* `{$item['path']}`" : '') . "\n\n```blade\n@use('{$class}')\n```\n\nImports `{$class}` into the Blade template scope.",
                ],
                'textEdit' => [
                    'range'   => $range,
                    'newText' => $class,
                ],
            ];
        }

        return $completions;
    }

    public function getInjectDirectiveCompletions(Document $document, string $line, string $text, int $lineNumber, int $character): array
    {
        // 1. Second argument: service / container binding / class string
        // E.g. @inject('metrics', '|' or @inject('metrics', 'App\Services\
        if (preg_match('/@inject\s*\(\s*[\'"][a-zA-Z0-9_]*[\'"]\s*,\s*[\'"]?([a-zA-Z0-9_.\/\\\\-]*)$/', $text, $m)) {
            $prefix = $m[1];
            $lowPrefix = strtolower($prefix);
            $range = [
                'start' => ['line' => $lineNumber, 'character' => $character - Utf16Position::length($prefix)],
                'end'   => ['line' => $lineNumber, 'character' => $character],
            ];

            $completions = [];
            $seen = [];

            // A. Indexed container bindings plus core fallback overlay
            foreach ($this->semanticIndex->containerBindings() as $bindingKey => $binding) {
                $boundClass = (string) ($binding['class'] ?? '');
                if ($lowPrefix !== '' && !str_starts_with(strtolower($bindingKey), $lowPrefix) && ($boundClass === '' || !str_starts_with(strtolower(class_basename($boundClass)), $lowPrefix))) {
                    continue;
                }
                $seen[$bindingKey] = true;
                $completions[] = [
                    'label'        => $bindingKey,
                    'labelDetails' => [
                        'detail'      => $boundClass !== '' ? ' -> ' . class_basename($boundClass) : '',
                        'description' => 'Container Binding',
                    ],
                    'kind'          => 18,
                    'detail'        => "Container Binding: {$bindingKey}" . ($boundClass !== '' ? " ({$boundClass})" : ''),
                    'documentation' => [
                        'kind'  => 'markdown',
                        'value' => "### Container Binding `'{$bindingKey}'`\n\n*Resolved Type:* `" . ($boundClass !== '' ? $boundClass : 'mixed') . "`\n\n*Origin:* `{$binding['origin']}`",
                    ],
                    'textEdit' => [
                        'range'   => $range,
                        'newText' => $bindingKey,
                    ],
                ];
            }

            // B. All Project & Vendor Classes, Contracts, and Services from ClassRegistry
            $registry = new ClassRegistry($this->project);
            $classes = $registry->search($prefix, limit: 100);
            foreach ($classes as $item) {
                $class = $item['class'];
                if (isset($seen[$class])) {
                    continue;
                }
                $seen[$class] = true;

                $kindStr = $item['kind'] ?? 'Class';
                $kind = match ($kindStr) {
                    'Interface'          => 8,
                    'Enum'               => 13,
                    'Trait'              => 14,
                    'Service', 'Factory' => 18,
                    default              => 7,
                };

                $completions[] = [
                    'label'        => $class,
                    'labelDetails' => [
                        'detail'      => ' (' . $item['name'] . ')',
                        'description' => $kindStr,
                    ],
                    'kind'          => $kind,
                    'detail'        => $item['detail'] ?? $class,
                    'documentation' => [
                        'kind'  => 'markdown',
                        'value' => "### `{$class}`\n\n*Type:* `{$kindStr}`" . (!empty($item['path']) ? "\n*Path:* `{$item['path']}`" : '') . "\n\nInjects `{$class}` via container auto-wiring.",
                    ],
                    'textEdit' => [
                        'range'   => $range,
                        'newText' => $class,
                    ],
                ];
            }

            return $completions;
        }

        // 2. First argument: variable name
        // E.g. @inject('|' or @inject('met|
        if (preg_match('/@inject\s*\(\s*[\'"]?([a-zA-Z0-9_]*)$/', $text, $m)) {
            $prefix = $m[1];
            $lowPrefix = strtolower($prefix);
            $range = [
                'start' => ['line' => $lineNumber, 'character' => $character - Utf16Position::length($prefix)],
                'end'   => ['line' => $lineNumber, 'character' => $character],
            ];

            // If 2nd argument already exists on the line, infer variable name from service
            $suggestions = [];
            if (preg_match('/@inject\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $line, $sMatch)) {
                $service = $sMatch[1];
                $base = class_basename($service);
                $camel = lcfirst($base);
                $suggestions[] = [
                    'name'   => $camel,
                    'detail' => "Inferred from '{$service}'",
                ];
                if (str_ends_with($camel, 'Service')) {
                    $short = substr($camel, 0, -7);
                    if ($short !== '') {
                        $suggestions[] = [
                            'name'   => $short,
                            'detail' => 'Short variable name',
                        ];
                    }
                }
            }

            // Common container variable suggestions
            $commonVars = [
                'metrics'        => 'Metrics service',
                'db'             => 'Database manager',
                'auth'           => 'Auth manager',
                'cache'          => 'Cache repository',
                'service'        => 'General service',
                'paymentGateway' => 'Payment Gateway',
                'userService'    => 'User service',
            ];
            foreach ($commonVars as $vName => $vDetail) {
                $suggestions[] = ['name' => $vName, 'detail' => $vDetail];
            }

            $completions = [];
            $seen = [];
            foreach ($suggestions as $s) {
                $var = $s['name'];
                if (isset($seen[$var])) {
                    continue;
                }
                if ($lowPrefix !== '' && !str_starts_with(strtolower($var), $lowPrefix)) {
                    continue;
                }
                $seen[$var] = true;

                $completions[] = [
                    'label'        => $var,
                    'labelDetails' => [
                        'description' => 'Variable Name',
                    ],
                    'kind'     => 6, // Variable
                    'detail'   => $s['detail'],
                    'textEdit' => [
                        'range'   => $range,
                        'newText' => $var,
                    ],
                ];
            }

            return $completions;
        }

        return [];
    }

    public function getExpressionClassCompletions(Document $document, string $text, int $lineNumber, int $character): array
    {
        $lines = explode("\n", $document->content);
        $linesBefore = array_slice($lines, 0, $lineNumber);
        $currentLinePrefix = Utf16Position::substr($lines[$lineNumber] ?? '', 0, $character);
        $contentUpToCursor = implode("\n", [...$linesBefore, $currentLinePrefix]);

        // 1. Blade Echo: {{ ... }} or {!! ... !!}
        $lastEchoOpen = max(strrpos($contentUpToCursor, '{{') !== false ? strrpos($contentUpToCursor, '{{') : -1, strrpos($contentUpToCursor, '{!!') !== false ? strrpos($contentUpToCursor, '{!!') : -1);
        $lastEchoClose = max(strrpos($contentUpToCursor, '}}') !== false ? strrpos($contentUpToCursor, '}}') : -1, strrpos($contentUpToCursor, '!!}') !== false ? strrpos($contentUpToCursor, '!!}') : -1);
        $inEcho = ($lastEchoOpen !== -1 && ($lastEchoClose === -1 || $lastEchoOpen > $lastEchoClose));

        // 2. Multiline @php ... @endphp block
        $lastPhpBlockOpen = -1;
        if (preg_match_all('/@php(?!\s*\()/i', $contentUpToCursor, $phpBlockMatches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($phpBlockMatches[0]);
            $lastPhpBlockOpen = $lastMatch[1];
        }
        $lastPhpBlockClose = strrpos($contentUpToCursor, '@endphp') !== false ? strrpos($contentUpToCursor, '@endphp') : -1;
        $inPhpBlock = ($lastPhpBlockOpen !== -1 && ($lastPhpBlockClose === -1 || $lastPhpBlockOpen > $lastPhpBlockClose));

        // 3. Raw PHP tags (open php tag and close tag)
        $lastPhpTagOpen = -1;
        if (preg_match_all('/<\?(?:php|=)?/i', $contentUpToCursor, $phpTagMatches, PREG_OFFSET_CAPTURE)) {
            $lastTagMatch = end($phpTagMatches[0]);
            $lastPhpTagOpen = $lastTagMatch[1];
        }
        $lastPhpTagClose = strrpos($contentUpToCursor, '?' . '>') !== false ? strrpos($contentUpToCursor, '?' . '>') : -1;
        $inPhpTag = ($lastPhpTagOpen !== -1 && ($lastPhpTagClose === -1 || $lastPhpTagOpen > $lastPhpTagClose));

        // 4. Directive call: @if(...), @foreach(...), @php(...)
        $inDirective = false;
        if (preg_match('/(?:@if|@elseif|@unless|@while|@for|@foreach|@switch|@case|@php)\s*\(.*$/s', $contentUpToCursor, $dMatch)) {
            $substr = $dMatch[0];
            if (substr_count($substr, '(') > substr_count($substr, ')')) {
                $inDirective = true;
            }
        }

        // 5. Bound component or HTML attribute: :prop="..." or wire:model="..."
        $inBoundAttr = (bool) preg_match('/(?::|wire:|x-bind:)[a-zA-Z0-9_-]+=(?:"[^"]*|\'[^\']*)$/', $currentLinePrefix);

        if (!$inEcho && !$inDirective && !$inPhpBlock && !$inPhpTag && !$inBoundAttr) {
            return [];
        }

        if (!preg_match('/(?<![\$\w\->\?:\/\\\\])([a-zA-Z_][a-zA-Z0-9_]*)$/', $text, $m)) {
            return [];
        }

        $prefix = $m[1];
        $range = [
            'start' => ['line' => $lineNumber, 'character' => $character - Utf16Position::length($prefix)],
            'end'   => ['line' => $lineNumber, 'character' => $character],
        ];

        $completions = [];

        // 1. Facades and global aliases
        foreach (FacadeMap::completions($prefix) as $item) {
            $item['textEdit'] = [
                'range'   => $range,
                'newText' => $item['insertText'] ?? $item['label'],
            ];
            $completions[] = $item;
        }

        // 2. @use & PHP use imported classes in current template
        $importedUses = $this->bladeAnalyzer->extractUseDirectives($document->content);
        foreach ($importedUses as $alias => $uInfo) {
            if ($prefix !== '' && !str_starts_with(strtolower($alias), strtolower($prefix))) {
                continue;
            }
            $completions[] = [
                'label'         => $alias,
                'kind'          => 7, // Class
                'detail'        => $uInfo['class'],
                'documentation' => [
                    'kind'  => 'markdown',
                    'value' => "**{$alias}** (`{$uInfo['class']}`)\n\n*Imported via:* `use {$uInfo['class']}` on line {$uInfo['line']}",
                ],
                'textEdit' => [
                    'range'   => $range,
                    'newText' => $alias,
                ],
            ];
        }

        // 3. Global Laravel & PHP Functions & Custom Helpers
        $functionRegistry = new GlobalFunctionRegistry($this->project);
        foreach ($functionRegistry->completions($prefix, $range) as $fItem) {
            $completions[] = $fItem;
        }

        return $completions;
    }

    public function resolveModelStaticCallType(string $modelFqcn, string $methodName): string
    {
        $cleanModel = ltrim($modelFqcn, '\\');

        if (in_array($methodName, [
            'query', 'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull',
            'whereBetween', 'orWhere', 'with', 'without', 'withCount', 'withSum', 'withAvg',
            'withMin', 'withMax', 'withExists', 'has', 'doesntHave', 'whereHas', 'whereDoesntHave',
            'orWhereHas', 'whereRaw', 'orWhereRaw', 'select', 'addSelect', 'selectRaw', 'distinct',
            'latest', 'oldest', 'inRandomOrder', 'reorder', 'orderBy', 'orderByDesc', 'orderByRaw',
            'groupBy', 'having', 'havingRaw', 'limit', 'take', 'offset', 'skip', 'when', 'unless',
            'scopes', 'withTrashed', 'onlyTrashed', 'withoutTrashed', 'lockForUpdate', 'sharedLock',
            'whereAll', 'whereAny', 'whereBinary', 'whereJsonContains', 'whereJsonLength', 'dump', 'tap',
        ], true) || str_starts_with($methodName, 'where')) {
            return "\\Illuminate\\Database\\Eloquent\\Builder<\\{$cleanModel}>";
        }

        if (in_array($methodName, ['all', 'get', 'createMany', 'findMany'], true)) {
            return "\\Illuminate\\Database\\Eloquent\\Collection<int, \\{$cleanModel}>";
        }

        if (in_array($methodName, ['paginate', 'simplePaginate', 'cursorPaginate'], true)) {
            return "\\Illuminate\\Pagination\\LengthAwarePaginator<int, \\{$cleanModel}>";
        }

        if (in_array($methodName, ['cursor', 'lazy', 'lazyById'], true)) {
            return "\\Illuminate\\Support\\LazyCollection<int, \\{$cleanModel}>";
        }

        if (in_array($methodName, ['find', 'findOrFail', 'first', 'firstOrFail', 'create', 'make', 'firstOrCreate', 'updateOrCreate', 'sole', 'findOrNew', 'firstOrNew', 'forceCreate'], true)) {
            return '\\' . $cleanModel;
        }

        if (in_array($methodName, ['pluck'], true)) {
            return '\\Illuminate\\Support\\Collection';
        }

        if (in_array($methodName, ['count', 'update', 'delete', 'destroy', 'upsert', 'insertGetId'], true)) {
            return 'int';
        }

        if (in_array($methodName, ['exists', 'doesntExist', 'insert', 'chunk', 'chunkById'], true)) {
            return 'bool';
        }

        if (in_array($methodName, ['toSql', 'toRawSql'], true)) {
            return 'string';
        }

        if (in_array($methodName, ['dd'], true)) {
            return 'never';
        }

        // Check if method is a local scope on the model
        $models = $this->semanticIndex->models();
        if (isset($models[$cleanModel]['scopes'])) {
            foreach ($models[$cleanModel]['scopes'] as $sc) {
                if (($sc['name'] ?? '') === $methodName) {
                    return "\\Illuminate\\Database\\Eloquent\\Builder<\\{$cleanModel}>";
                }
            }
        }

        return $this->resolveMethodReturnType($cleanModel, $methodName);
    }

    public function resolveMethodReturnType(string $class, string $methodName): string
    {
        $cleanClass = ltrim($class, '\\');
        if (!class_exists($cleanClass) && !interface_exists($cleanClass)) {
            return 'mixed';
        }

        try {
            $ref = new ReflectionClass($cleanClass);
            if ($ref->hasMethod($methodName)) {
                $m = $ref->getMethod($methodName);
                if ($m->hasReturnType()) {
                    return (string) $m->getReturnType();
                }
            }
            if ($doc = $ref->getDocComment()) {
                $methods = $this->docBlockParser->extractMethods($doc);
                if (isset($methods[$methodName]['returnType'])) {
                    return $methods[$methodName]['returnType'];
                }
            }
        } catch (Throwable) {}

        return 'mixed';
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
            } catch (Throwable) {
            }
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
            $qualifiedInners = array_map(fn ($p) => $this->qualifyType($p, $document), $innerParts);

            return $outer . '<' . implode(', ', $qualifiedInners) . '>';
        }

        // Array suffix: User[]
        if (str_ends_with($type, '[]')) {
            return $this->qualifyType(substr($type, 0, -2), $document) . '[]';
        }

        // Union: User|null or string|int
        if (str_contains($type, '|')) {
            $parts = explode('|', $type);

            return implode('|', array_map(fn ($p) => $this->qualifyType(trim($p), $document), $parts));
        }

        // Intersection: stdClass&object{...}
        if (str_contains($type, '&')) {
            $parts = explode('&', $type);

            return implode('&', array_map(fn ($p) => $this->qualifyType(trim($p), $document), $parts));
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
            $models = $this->semanticIndex->models();
            foreach ($models as $fqcn => $mData) {
                if (class_basename($fqcn) === $type) {
                    return '\\' . ltrim($fqcn, '\\');
                }
            }
        } catch (Throwable) {
        }

        return $type;
    }
}
