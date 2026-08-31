<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentReferences implements Method
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
        protected Project $project,
    ) {}

    /**
     * Handle the textDocument/references request.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get(
            (string) $request->get('textDocument.uri')
        );

        if ($document === null) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $position = $request->get('position', []);

        if (!is_array($position) || !is_int($position['line'] ?? null) || !is_int($position['character'] ?? null)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $includeDeclaration = (bool) $request->get('context.includeDeclaration', true);
        $references = [];

        // In Blade templates, use virtual PHP document and source map
        if (str_ends_with($document->uri, '.blade.php')) {
            try {
                $scope = $this->features->scopeResolver()->resolveAtPosition($document, (int) $position['line'], (int) $position['character']);
                $virtualDoc = $this->features->bladeCompiler()->compile($document, $scope);
                $virtualPos = $virtualDoc->sourceMap->bladeToVirtualPosition((int) $position['line'], (int) $position['character']);
                if ($virtualPos !== null) {
                    $phpRefs = $this->features->phpIntelligence()->references($virtualDoc, $virtualPos, $includeDeclaration);
                    foreach ($phpRefs as $ref) {
                        // Map virtual doc ranges back to Blade template ranges
                        $vStartLine = $ref['range']['start']['line'] ?? 0;
                        $vStartChar = $ref['range']['start']['character'] ?? 0;
                        $vEndLine = $ref['range']['end']['line'] ?? $vStartLine;
                        $vEndChar = $ref['range']['end']['character'] ?? $vStartChar;

                        $bladeStart = $virtualDoc->sourceMap->virtualToBladePosition($vStartLine, $vStartChar);
                        $bladeEnd = $virtualDoc->sourceMap->virtualToBladePosition($vEndLine, $vEndChar);

                        if ($bladeStart !== null) {
                            $references[] = [
                                'uri'   => $document->uri,
                                'range' => [
                                    'start' => $bladeStart,
                                    'end'   => $bladeEnd ?? $bladeStart,
                                ],
                            ];
                        }
                    }
                }
            } catch (\Throwable) {}
        }

        return JsonRpcResponse::result($request->id(), $references);
    }
}
