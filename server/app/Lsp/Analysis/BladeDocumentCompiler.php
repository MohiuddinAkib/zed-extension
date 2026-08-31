<?php

declare(strict_types=1);

namespace App\Lsp\Analysis;

use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Semantics\SourceMap;
use App\Lsp\Semantics\TypeDisplay;
use App\Lsp\Semantics\ViewScope;
use App\Lsp\Semantics\VirtualDocument;
use Stillat\BladeParser\Document\Document as BladeDocument;
use Stillat\BladeParser\Nodes\DirectiveNode;
use Stillat\BladeParser\Nodes\EchoNode;
use Stillat\BladeParser\Nodes\PhpBlockNode;
use Stillat\BladeParser\Nodes\PhpTagNode;
use Throwable;

class BladeDocumentCompiler
{
    protected TypeDisplay $typeDisplay;

    public function __construct(
        protected ?Project $project = null,
    ) {
        $this->typeDisplay = new TypeDisplay();
    }

    /**
     * Compile a Blade document into a VirtualDocument with full PHP syntax and source mappings.
     */
    public function compile(Document $document, ?ViewScope $scope = null): VirtualDocument
    {
        $bladeContent = $document->content;
        $injectedVars = [];
        $virtualBuffer = "<?php\n\n";

        // 1. Inject default Global Facades and Aliases (Js, Str, Arr, Auth, Route, DB, etc.)
        $virtualBuffer .= \App\Lsp\Features\Facades\FacadeMap::defaultUseStatements() . "\n\n";

        // 2. Inject template @use(...) directives
        $bladeAstAnalyzer = new BladeAstAnalyzer($this->project);
        $importedUses = $bladeAstAnalyzer->extractUseDirectives($bladeContent);
        foreach ($importedUses as $alias => $uInfo) {
            $classFqcn = ltrim($uInfo['class'], '\\');
            if (str_ends_with($classFqcn, "\\{$alias}")) {
                $virtualBuffer .= "use {$classFqcn};\n";
            } else {
                $virtualBuffer .= "use {$classFqcn} as {$alias};\n";
            }
        }
        $virtualBuffer .= "\n";

        // 3. Inject ViewScope variables as typed PHP declarations
        if ($scope !== null) {
            foreach ($scope->variables as $varName => $symbol) {
                if ($varName === '' || $varName === 'this' || !preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $varName)) {
                    continue;
                }

                $typeStr = (string) $symbol->type;
                if ($typeStr === '') {
                    $typeStr = 'mixed';
                }

                $virtualBuffer .= "/** @var {$typeStr} \${$varName} */\n";
                $virtualBuffer .= "\${$varName} = null;\n";
                $injectedVars[] = $varName;
            }
        }

        // Framework default globals if not already present
        $defaultGlobals = [
            '__env' => '\Illuminate\View\Factory',
            'app' => '\Illuminate\Foundation\Application',
            'errors' => '\Illuminate\Support\ViewErrorBag',
            'request' => '\Illuminate\Http\Request',
        ];

        foreach ($defaultGlobals as $gName => $gType) {
            if (!in_array($gName, $injectedVars, true)) {
                $virtualBuffer .= "/** @var {$gType} \${$gName} */\n";
                $virtualBuffer .= "\${$gName} = null;\n";
                $injectedVars[] = $gName;
            }
        }

        $virtualBuffer .= "\n// --- Blade Template Expressions ---\n";

        $sourceMap = new SourceMap($bladeContent, '');
        $openBlocksCount = 0;

        try {
            $bladeDoc = BladeDocument::fromText($bladeContent);
            $nodes = $bladeDoc->getNodes();

            foreach ($nodes as $node) {
                if ($node instanceof EchoNode) {
                    $rawInner = $node->innerContent;
                    $trimmed = trim($rawInner);
                    if ($trimmed === '') {
                        continue;
                    }

                    $leadingSpaces = strlen($rawInner) - strlen(ltrim($rawInner));
                    $prefixLen = str_starts_with($node->content, '{!!') ? 3 : 2;
                    $bladeOffset = ($node->position ? $node->position->startOffset : 0) + $prefixLen + $leadingSpaces;

                    $stmtPrefix = '$__blade_echo = (';
                    $virtualBuffer .= $stmtPrefix;
                    $virtualOffset = strlen($virtualBuffer);

                    $virtualBuffer .= $trimmed;
                    $virtualBuffer .= ");\n";

                    $sourceMap->addMapping($bladeOffset, $virtualOffset, strlen($trimmed));
                } elseif ($node instanceof DirectiveNode) {
                    $name = strtolower($node->content);
                    $innerArgs = $node->arguments ? (string) $node->arguments->innerContent : '';
                    $argsOffset = ($node->arguments && $node->arguments->position)
                        ? $node->arguments->position->startOffset
                        : (($node->position ? $node->position->startOffset : 0) + strlen($node->content) + 2);

                    switch ($name) {
                        case 'if':
                        case 'elseif':
                        case 'unless':
                            $keyword = $name === 'elseif' ? '} elseif (' : 'if (';
                            if ($name === 'unless') {
                                $virtualBuffer .= 'if (!(';
                            } else {
                                $virtualBuffer .= $keyword;
                            }
                            $virtualOffset = strlen($virtualBuffer);
                            $virtualBuffer .= $innerArgs;
                            $virtualBuffer .= ($name === 'unless' ? ')) {' : ') {') . "\n";
                            if ($name !== 'elseif') {
                                $openBlocksCount++;
                            }
                            $sourceMap->addMapping($argsOffset, $virtualOffset, strlen($innerArgs));
                            break;

                        case 'else':
                            $virtualBuffer .= "} else {\n";
                            break;

                        case 'endif':
                        case 'endunless':
                            if ($openBlocksCount > 0) {
                                $virtualBuffer .= "}\n";
                                $openBlocksCount--;
                            }
                            break;

                        case 'foreach':
                        case 'forelse':
                            $virtualBuffer .= 'foreach (';
                            $virtualOffset = strlen($virtualBuffer);
                            $virtualBuffer .= $innerArgs;
                            $virtualBuffer .= ") {\n";
                            $virtualBuffer .= "    /** @var object \$loop */\n";
                            $virtualBuffer .= "    \$loop = (object) ['iteration' => 1, 'index' => 0, 'first' => true, 'last' => false, 'count' => 1, 'even' => false, 'odd' => true, 'depth' => 1, 'parent' => null];\n";
                            $openBlocksCount++;
                            $sourceMap->addMapping($argsOffset, $virtualOffset, strlen($innerArgs));
                            break;

                        case 'empty':
                            $virtualBuffer .= "} if (true) {\n";
                            break;

                        case 'endforeach':
                        case 'endforelse':
                            if ($openBlocksCount > 0) {
                                $virtualBuffer .= "}\n";
                                $openBlocksCount--;
                            }
                            break;

                        case 'for':
                        case 'while':
                            $virtualBuffer .= "{$name} (";
                            $virtualOffset = strlen($virtualBuffer);
                            $virtualBuffer .= $innerArgs;
                            $virtualBuffer .= ") {\n";
                            $openBlocksCount++;
                            $sourceMap->addMapping($argsOffset, $virtualOffset, strlen($innerArgs));
                            break;

                        case 'endfor':
                        case 'endwhile':
                            if ($openBlocksCount > 0) {
                                $virtualBuffer .= "}\n";
                                $openBlocksCount--;
                            }
                            break;

                        case 'php':
                            if ($innerArgs !== '') {
                                $virtualOffset = strlen($virtualBuffer);
                                $virtualBuffer .= "{$innerArgs};\n";
                                $sourceMap->addMapping($argsOffset, $virtualOffset, strlen($innerArgs));
                            }
                            break;

                        case 'js':
                        case 'json':
                            $virtualBuffer .= '$__blade_js = (';
                            $virtualOffset = strlen($virtualBuffer);
                            $virtualBuffer .= $innerArgs;
                            $virtualBuffer .= ");\n";
                            $sourceMap->addMapping($argsOffset, $virtualOffset, strlen($innerArgs));
                            break;

                        case 'error':
                            $virtualBuffer .= "if (true) {\n    \$message = '';\n";
                            $openBlocksCount++;
                            break;

                        case 'enderror':
                            if ($openBlocksCount > 0) {
                                $virtualBuffer .= "}\n";
                                $openBlocksCount--;
                            }
                            break;

                        case 'props':
                            $virtualBuffer .= "/** @var \Illuminate\View\ComponentAttributeBag \$attributes */\n";
                            $virtualBuffer .= "\$attributes = new \Illuminate\View\ComponentAttributeBag();\n";
                            if ($innerArgs !== '') {
                                $virtualBuffer .= "\$__blade_props = {$innerArgs};\n";
                            }
                            break;

                        case 'use':
                            break;
                    }
                } elseif ($node instanceof PhpBlockNode) {
                    $code = $node->innerContent;
                    $bladeOffset = ($node->position ? $node->position->startOffset : 0) + 4;
                    $virtualOffset = strlen($virtualBuffer);
                    $virtualBuffer .= "{$code}\n";
                    $sourceMap->addMapping($bladeOffset, $virtualOffset, strlen($code));
                } elseif ($node instanceof PhpTagNode) {
                    $code = $node->innerContent;
                    $bladeOffset = ($node->position ? $node->position->startOffset : 0) + 5;
                    $virtualOffset = strlen($virtualBuffer);
                    $virtualBuffer .= "{$code}\n";
                    $sourceMap->addMapping($bladeOffset, $virtualOffset, strlen($code));
                }
            }

            // Extract bound component/HTML attributes: :attribute="$expr" or x-bind:attribute="$expr"
            if (preg_match_all('/(?::([a-zA-Z0-9_\-]+)|x-bind:([a-zA-Z0-9_\-]+))\s*=\s*(["\'])(.*?)\3/', $bladeContent, $attrMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($attrMatches as $m) {
                    $expr = $m[4][0];
                    $exprOffset = $m[4][1];
                    if (trim($expr) !== '') {
                        $virtualBuffer .= '$__blade_attr = (';
                        $virtualOffset = strlen($virtualBuffer);
                        $virtualBuffer .= $expr;
                        $virtualBuffer .= ");\n";
                        $sourceMap->addMapping($exprOffset, $virtualOffset, strlen($expr));
                    }
                }
            }
        } catch (Throwable) {
            // Handle mid-edit blade parser errors gracefully
        }

        // Close any dangling blocks for valid PHP parsing
        while ($openBlocksCount > 0) {
            $virtualBuffer .= "}\n";
            $openBlocksCount--;
        }

        // Initialize source map with completed virtual buffer
        $finalMap = new SourceMap($bladeContent, $virtualBuffer);
        foreach ($sourceMap->getMappings() as $m) {
            $finalMap->addMapping($m['bladeStart'], $m['virtualStart'], $m['bladeEnd'] - $m['bladeStart']);
        }

        return new VirtualDocument(
            bladeUri: $document->uri,
            phpCode: $virtualBuffer,
            sourceMap: $finalMap,
            scope: $scope,
            injectedVariables: $injectedVars,
        );
    }
}
