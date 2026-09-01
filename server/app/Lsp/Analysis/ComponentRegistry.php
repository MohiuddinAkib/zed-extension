<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Project;
use App\Lsp\Semantics\ComponentPropSymbol;
use App\Lsp\Semantics\ComponentSymbol;
use App\Lsp\Semantics\SlotSymbol;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Support\FileUri;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

class ComponentRegistry
{
    /**
     * @var array<string, ComponentSymbol>
     */
    protected array $components = [];

    protected bool $indexed = false;
    protected Parser $phpParser;
    protected NodeFinder $nodeFinder;
    protected BladeAstAnalyzer $bladeAnalyzer;
    protected DocBlockParser $docBlockParser;

    public function __construct(protected Project $project)
    {
        $this->phpParser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
        $this->bladeAnalyzer = new BladeAstAnalyzer($this->project);
        $this->docBlockParser = new DocBlockParser();
    }

    /**
     * Get a component symbol by tag name or component name (e.g. 'alert', 'x-alert', 'filament::button').
     */
    public function getComponent(string $name): ?ComponentSymbol
    {
        $this->ensureIndexed();

        $cleanName = ltrim($name, 'x-');
        if (isset($this->components[$cleanName])) {
            return $this->components[$cleanName];
        }

        if (isset($this->components[$name])) {
            return $this->components[$name];
        }

        // Try kebab-to-dot or dot-to-kebab
        $dotName = str_replace('-', '.', $cleanName);
        if (isset($this->components[$dotName])) {
            return $this->components[$dotName];
        }

        $kebabName = str_replace('.', '-', $cleanName);
        if (isset($this->components[$kebabName])) {
            return $this->components[$kebabName];
        }

        return null;
    }

    /**
     * @return array<string, ComponentSymbol>
     */
    public function all(): array
    {
        $this->ensureIndexed();
        return $this->components;
    }

    public function registerComponent(ComponentSymbol $component): void
    {
        $this->components[$component->name] = $component;
    }

    public function ensureIndexed(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->indexed = true;
        $this->discoverComponents();
    }

    protected function discoverComponents(): void
    {
        // 1. Discover Anonymous Blade components in resources/views/components
        $this->discoverAnonymousComponents();

        // 2. Discover Class-based components in app/View/Components
        $this->discoverClassComponents();

        // 3. Discover Livewire components in app/Livewire
        $this->discoverLivewireComponents();

        // 4. Discover Laravel's built-in Markdown mail components when the runtime index misses the mail view hint
        $this->discoverMailMarkdownComponents();

        // 5. Merge indexed Blade components from ProjectIndex
        $this->mergeProjectIndexComponents();
    }

    protected function discoverAnonymousComponents(): void
    {
        $basePath = rtrim($this->project->path(), '/\\');
        $componentsDir = $basePath . '/resources/views/components';

        if (!is_dir($componentsDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($componentsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relPath = ltrim(substr($file->getPathname(), strlen($componentsDir)), '/\\');
            $componentKey = str_replace(['/', '\\'], '.', substr($relPath, 0, -10));
            $kebabName = str_replace('.', '-', $componentKey);

            $content = (string) file_get_contents($file->getPathname());
            $props = $this->extractPropsFromBlade($content, $file->getPathname());

            $props = $this->extractPropsFromBlade($content, $file->getPathname());
            $slots = $this->extractSlotsFromBlade($content);

            $symbol = new ComponentSymbol(
                name: $kebabName,
                tagName: 'x-' . $kebabName,
                isAnonymous: true,
                className: null,
                viewPath: $file->getPathname(),
                props: $props,
                slots: $slots,
                documentation: "Blade component: <x-{$kebabName}>",
            );

            $this->components[$kebabName] = $symbol;
            $this->components[$componentKey] = $symbol;
        }
    }

    /**
     * @return array<string, ComponentPropSymbol>
     */
    public function extractPropsFromBlade(string $content, string $source = ''): array
    {
        $props = [];
        $propSymbols = $this->bladeAnalyzer->extractPropsDirectiveSymbols($content, $source);

        foreach ($propSymbols as $name => $varSymbol) {
            $required = $varSymbol->metadata['required'] ?? ($varSymbol->defaultValue === null);
            $defaultValue = $varSymbol->defaultValue;
            $prop = new ComponentPropSymbol(
                name: $name,
                type: $varSymbol->type,
                required: (bool) $required,
                defaultValue: $defaultValue,
                documentation: "Component prop \${$name}" . ($defaultValue !== null ? " (default: {$defaultValue})" : ' (required)'),
            );
            $props[$name] = $prop;
            $props[$prop->kebabName()] = $prop;
            $props[$prop->camelName()] = $prop;
        }

        return $props;
    }

    /**
     * @return array<string, SlotSymbol>
     */
    public function extractSlotsFromBlade(string $content): array
    {
        $slots = [
            'slot' => new SlotSymbol('slot', [], 'Default slot content'),
        ];

        // Match $header, $footer, etc. used as slots: {!! $header !!}, {{ $header }}, $header->attributes, @isset($header), @if(isset($header))
        if (preg_match_all('/(?:\$([a-zA-Z0-9_]+)->attributes|\{\{\s*\$([a-zA-Z0-9_]+)\s*\}\}|\{!!\s*\$([a-zA-Z0-9_]+)\s*!!\}|@(?:isset|if\s*\(\s*isset)\s*\(\s*\$([a-zA-Z0-9_]+)\s*\))/i', $content, $matches)) {
            $names = array_filter(array_merge($matches[1], $matches[2], $matches[3], $matches[4]));
            foreach ($names as $name) {
                if (in_array($name, ['attributes', 'slot', 'this', 'errors', 'loop'], true)) {
                    continue;
                }
                $kebab = Str::kebab($name);
                $slots[$kebab] = new SlotSymbol($kebab, [], "Named slot: <x-slot:{$kebab}>");
            }
        }

        // Match <x-slot:name ...> or @slot('name')
        if (preg_match_all('/(?:<x-slot:([a-zA-Z0-9_-]+)|@slot\s*\(\s*[\'"]([a-zA-Z0-9_-]+)[\'"]\s*\))/i', $content, $slotMatches)) {
            $slotNames = array_filter(array_merge($slotMatches[1], $slotMatches[2]));
            foreach ($slotNames as $sName) {
                $kebab = Str::kebab($sName);
                $slots[$kebab] = new SlotSymbol($kebab, [], "Named slot: <x-slot:{$kebab}>");
            }
        }

        return $slots;
    }

    protected function discoverClassComponents(): void
    {
        $basePath = rtrim($this->project->path(), '/\\');
        $searchDirs = [
            $basePath . '/app/View/Components' => 'App\\View\\Components',
        ];

        // Scan Modules/*/View/Components
        if (is_dir($basePath . '/Modules')) {
            foreach (glob($basePath . '/Modules/*', GLOB_ONLYDIR) ?: [] as $modDir) {
                $modName = basename($modDir);
                if (is_dir($modDir . '/View/Components')) {
                    $searchDirs[$modDir . '/View/Components'] = "Modules\\{$modName}\\View\\Components";
                }
                if (is_dir($modDir . '/src/View/Components')) {
                    $searchDirs[$modDir . '/src/View/Components'] = "Modules\\{$modName}\\View\\Components";
                }
            }
        }

        foreach ($searchDirs as $componentsDir => $nsPrefix) {
            if (!is_dir($componentsDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($componentsDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $relPath = ltrim(substr($file->getPathname(), strlen($componentsDir)), '/\\');
                $classSubPath = str_replace(['/', '\\'], '\\', substr($relPath, 0, -4));
                $className = "{$nsPrefix}\\{$classSubPath}";
                $kebabName = Str::kebab(str_replace('\\', '.', $classSubPath));

                $props = $this->extractPropsFromPhpFile($file->getPathname(), $className);

                $symbol = new ComponentSymbol(
                    name: $kebabName,
                    tagName: 'x-' . $kebabName,
                    isAnonymous: false,
                    className: $className,
                    viewPath: null,
                    props: $props,
                    slots: [
                        'slot' => new SlotSymbol('slot', [], 'Default slot content'),
                    ],
                    documentation: "Class component: {$className}",
                );

                $this->components[$kebabName] = $symbol;
            }
        }
    }

    protected function discoverLivewireComponents(): void
    {
        $basePath = rtrim($this->project->path(), '/\\');
        $livewireDir = $basePath . '/app/Livewire';

        if (!is_dir($livewireDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($livewireDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relPath = ltrim(substr($file->getPathname(), strlen($livewireDir)), '/\\');
            $classSubPath = str_replace(['/', '\\'], '\\', substr($relPath, 0, -4));
            $className = "App\\Livewire\\{$classSubPath}";
            $kebabName = Str::kebab(str_replace('\\', '.', $classSubPath));

            $props = $this->extractPropsFromPhpFile($file->getPathname(), $className);

            $symbol = new ComponentSymbol(
                name: $kebabName,
                tagName: 'livewire:' . $kebabName,
                isAnonymous: false,
                className: $className,
                viewPath: null,
                props: $props,
                slots: [
                    'slot' => new SlotSymbol('slot', [], 'Default slot content'),
                ],
                documentation: "Livewire component: {$className}",
            );

            $this->components[$kebabName] = $symbol;
            $this->components['livewire:' . $kebabName] = $symbol;
        }
    }

    protected function discoverMailMarkdownComponents(): void
    {
        $basePath = rtrim($this->project->path(), '/\\');

        // Ordered lowest → highest priority. Published path is last so it overwrites the vendor default.
        $candidates = [
            $basePath . '/vendor/laravel/framework/src/Illuminate/Mail/resources/views/html',
            $basePath . '/resources/views/vendor/mail/html',
        ];

        foreach ($candidates as $mailComponentsDir) {
            if (!is_dir($mailComponentsDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($mailComponentsDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $relPath = ltrim(substr($file->getPathname(), strlen($mailComponentsDir)), '/\\');
                $componentKey = 'mail::' . str_replace(['/', '\\'], '.', substr($relPath, 0, -10));
                $componentKey = str_replace('.index', '', $componentKey);
                $kebabKey = 'mail::' . str_replace('.', '-', substr($componentKey, strlen('mail::')));

                $content = (string) file_get_contents($file->getPathname());
                $props = $this->extractPropsFromBlade($content, $file->getPathname());
                $slots = $this->extractSlotsFromBlade($content);

                $symbol = new ComponentSymbol(
                    name: $componentKey,
                    tagName: 'x-' . $componentKey,
                    isAnonymous: true,
                    className: null,
                    viewPath: $file->getPathname(),
                    props: $props,
                    slots: $slots,
                    documentation: "Laravel mail component: <x-{$componentKey}>",
                );

                // Register under all key variants; later iterations (published path) override earlier ones
                $this->components[$componentKey] = $symbol;
                $this->components['x-' . $componentKey] = $symbol;
                $this->components[$kebabKey] = $symbol;
                $this->components['x-' . $kebabKey] = $symbol;
            }
        }
    }

    /**
     * @return array<string, ComponentPropSymbol>
     */
    public function extractPropsFromPhpFile(string $filePath, string $className): array
    {
        $props = [];

        try {
            $code = (string) file_get_contents($filePath);
            $stmts = $this->phpParser->parse($code);
            if ($stmts) {
                $useMap = [];
                foreach ($this->nodeFinder->findInstanceOf($stmts, Node\Stmt\Use_::class) as $useStmt) {
                    foreach ($useStmt->uses as $useUse) {
                        $useMap[$useUse->getAlias()->toString()] = $useUse->name->toString();
                    }
                }

                $classNode = $this->nodeFinder->findFirstInstanceOf($stmts, Class_::class);
                if ($classNode instanceof Class_) {
                    // Extract class/constructor docblocks for @param & @var
                    $paramDocs = [];
                    $ctor = $classNode->getMethod('__construct');
                    if ($ctor instanceof ClassMethod) {
                        if ($docComment = $ctor->getDocComment()) {
                            if (preg_match_all('/@param\s+([^\s]+)\s+\$([a-zA-Z0-9_]+)/', $docComment->getText(), $docM)) {
                                foreach ($docM[2] as $dIdx => $pName) {
                                    $paramDocs[$pName] = $docM[1][$dIdx];
                                }
                            }
                        }

                        foreach ($ctor->params as $param) {
                            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                                $name = $param->var->name;
                                $typeName = $this->formatAstTypeNode($param->type, $useMap);
                                if ($typeName === 'mixed' && isset($paramDocs[$name])) {
                                    $typeName = $paramDocs[$name];
                                }

                                $required = $param->default === null;
                                $defaultVal = null;
                                if ($param->default !== null) {
                                    $defaultVal = $this->nodeSource($code, $param->default);
                                }

                                $prop = new ComponentPropSymbol(
                                    name: $name,
                                    type: TypeRef::fromString($typeName),
                                    required: $required,
                                    defaultValue: $defaultVal,
                                    description: "Constructor prop \${$name}" . ($defaultVal !== null ? " (default: {$defaultVal})" : ''),
                                );
                                $props[$name] = $prop;
                                $props[$prop->kebabName()] = $prop;
                                $props[$prop->camelName()] = $prop;
                            }
                        }
                    }

                    // 2. Public properties
                    foreach ($classNode->getProperties() as $propStmt) {
                        if ($propStmt->isPublic()) {
                            foreach ($propStmt->props as $p) {
                                $name = $p->name->toString();
                                if (!isset($props[$name])) {
                                    $typeName = $this->formatAstTypeNode($propStmt->type, $useMap);
                                    $defaultVal = $p->default ? $this->nodeSource($code, $p->default) : null;
                                    $prop = new ComponentPropSymbol(
                                        name: $name,
                                        type: TypeRef::fromString($typeName),
                                        required: false,
                                        defaultValue: $defaultVal,
                                        description: "Public property \${$name}",
                                    );
                                    $props[$name] = $prop;
                                    $props[$prop->kebabName()] = $prop;
                                    $props[$prop->camelName()] = $prop;
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {}

        return $props;
    }

    /**
     * @param array<string, string> $useMap
     */
    public function formatAstTypeNode(?Node $typeNode, array $useMap = []): string
    {
        if ($typeNode === null) {
            return 'mixed';
        }

        if ($typeNode instanceof Node\NullableType) {
            return '?' . $this->formatAstTypeNode($typeNode->type, $useMap);
        }

        if ($typeNode instanceof Node\UnionType) {
            return implode('|', array_map(fn ($t) => $this->formatAstTypeNode($t, $useMap), $typeNode->types));
        }

        if ($typeNode instanceof Node\IntersectionType) {
            return implode('&', array_map(fn ($t) => $this->formatAstTypeNode($t, $useMap), $typeNode->types));
        }

        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }

        if ($typeNode instanceof Node\Name) {
            $nameStr = $typeNode->toString();
            if (isset($useMap[$nameStr])) {
                return '\\' . $useMap[$nameStr];
            }
            return '\\' . $nameStr;
        }

        return 'mixed';
    }

    protected function nodeSource(string $code, Node $node): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start >= 0 && $end >= $start && $end < strlen($code)) {
            return trim(substr($code, $start, $end - $start + 1));
        }

        if ($node instanceof Node\Expr) {
            return (new \PhpParser\PrettyPrinter\Standard())->prettyPrintExpr($node);
        }

        return '';
    }

    protected function mergeProjectIndexComponents(): void
    {
        try {
            $rawComponents = $this->project->index->bladeComponents()['components'] ?? [];
            foreach ($rawComponents as $key => $data) {
                if (!is_array($data)) {
                    continue;
                }

                $keyStr = (string) $key;
                $existing = $this->components[$keyStr] ?? null;

                $props = [];
                if (isset($data['props']) && is_array($data['props'])) {
                    foreach ($data['props'] as $p) {
                        if (is_array($p) && isset($p['name'])) {
                            $name = $p['name'];
                            $type = $p['type'] ?? 'mixed';
                            $prop = new ComponentPropSymbol(
                                name: $name,
                                type: TypeRef::fromString($type),
                                required: !empty($p['required']),
                                defaultValue: $p['default'] ?? null,
                                documentation: "Prop \${$name}",
                            );
                            $props[$name] = $prop;
                            $props[$prop->kebabName()] = $prop;
                            $props[$prop->camelName()] = $prop;
                        }
                    }
                }

                $slots = [
                    'slot' => new SlotSymbol('slot', [], 'Default slot content'),
                ];

                $viewPath = $data['paths'][0] ?? null;
                if (is_string($viewPath) && $viewPath !== '') {
                    $basePath = rtrim($this->project->path(), '/\\');
                    $fullPath = str_starts_with($viewPath, '/') ? $viewPath : $basePath . '/' . ltrim($viewPath, '/\\');
                    if (file_exists($fullPath) && str_ends_with($fullPath, '.blade.php')) {
                        try {
                            $content = (string) file_get_contents($fullPath);
                            $extractedProps = $this->extractPropsFromBlade($content, $fullPath);
                            $props = array_merge($extractedProps, $props);
                            $extractedSlots = $this->extractSlotsFromBlade($content);
                            $slots = array_merge($slots, $extractedSlots);
                        } catch (Throwable) {}
                    }
                }

                if ($existing !== null) {
                    $mergedProps = array_merge($props, $existing->props);
                    $mergedSlots = array_merge($slots, $existing->slots);
                    $symbol = new ComponentSymbol(
                        name: $existing->name,
                        tagName: $existing->tagName,
                        isAnonymous: $existing->isAnonymous,
                        className: $existing->className,
                        viewPath: $existing->viewPath ?? $viewPath,
                        props: $mergedProps,
                        slots: $mergedSlots,
                        documentation: $existing->documentation,
                    );
                } else {
                    $symbol = new ComponentSymbol(
                        name: $keyStr,
                        tagName: 'x-' . $keyStr,
                        isAnonymous: empty($data['class']),
                        className: $data['class'] ?? null,
                        viewPath: $viewPath,
                        props: $props,
                        slots: $slots,
                        documentation: "Blade component: <x-{$keyStr}>",
                    );
                }

                $this->components[$keyStr] = $symbol;
                $this->components['x-' . $keyStr] = $symbol;

                if (str_contains($keyStr, '::')) {
                    [$ns, $namePart] = explode('::', $keyStr, 2);
                    $kebabVariant = $ns . '::' . str_replace('.', '-', $namePart);
                    $dotVariant = $ns . '::' . str_replace('-', '.', $namePart);
                    $this->components[$kebabVariant] = $symbol;
                    $this->components[$dotVariant] = $symbol;
                    $this->components['x-' . $kebabVariant] = $symbol;
                    $this->components['x-' . $dotVariant] = $symbol;
                }
            }
        } catch (Throwable) {}
    }
}
