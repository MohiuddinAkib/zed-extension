<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use App\Lsp\Support\CompletionItems;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentCompletion implements Method
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
     * Handle the textDocument/completion request.
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

        if (!is_array($position)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        if (!is_int($position['line'] ?? null) || !is_int($position['character'] ?? null)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        foreach ($this->features->completions() as $provider) {
            $items = $provider->get($document, $position);

            if ($items !== []) {
                return JsonRpcResponse::result(
                    $request->id(),
                    CompletionItems::matching($document, $items),
                );
            }

            $request->cancelIfRequested();
        }

        if (str_ends_with($document->uri, '.blade.php')) {
            try {
                $scope = $this->features->scopeResolver()->resolveAtPosition($document, (int) $position['line'], (int) $position['character']);
                $virtualDoc = $this->features->bladeCompiler()->compile($document, $scope);
                $virtualPos = $virtualDoc->sourceMap->bladeToVirtualPosition((int) $position['line'], (int) $position['character']);
                if ($virtualPos !== null) {
                    $phpItems = $this->features->phpIntelligence()->completion($virtualDoc, $virtualPos);
                    if (!empty($phpItems)) {
                        return JsonRpcResponse::result(
                            $request->id(),
                            CompletionItems::matching($document, $phpItems),
                        );
                    }
                }
            } catch (\Throwable) {}
        }

        return JsonRpcResponse::result($request->id(), []);
    }
}
