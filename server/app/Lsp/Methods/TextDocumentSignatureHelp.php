<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\BladeDirectives\BladeDirectiveSignatureProvider;
use App\Lsp\Project;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;
use Throwable;

class TextDocumentSignatureHelp implements Method
{
    protected BladeDirectiveSignatureProvider $directiveSignatures;

    public function __construct(
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
        protected Project $project,
    ) {
        $this->directiveSignatures = new BladeDirectiveSignatureProvider($this->project);
    }

    /**
     * Handle the textDocument/signatureHelp request.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get(
            (string) $request->get('textDocument.uri')
        );

        if ($document === null) {
            return JsonRpcResponse::result($request->id(), null);
        }

        $position = $request->get('position', []);
        if (!is_array($position) || !is_int($position['line'] ?? null) || !is_int($position['character'] ?? null)) {
            return JsonRpcResponse::result($request->id(), null);
        }

        // 1. Check Blade Directives & Laravel Helper signatures
        $sig = $this->directiveSignatures->get($document, $position);
        if ($sig !== null) {
            return JsonRpcResponse::result($request->id(), $sig);
        }

        // 2. Check Virtual PHP Document / AST methods via PhpIntelligenceAdapter
        if (str_ends_with($document->uri, '.blade.php')) {
            try {
                $scope = $this->features->scopeResolver()->resolveAtPosition($document, (int) $position['line'], (int) $position['character']);
                $virtualDoc = $this->features->bladeCompiler()->compile($document, $scope);
                $virtualPos = $virtualDoc->sourceMap->bladeToVirtualPosition((int) $position['line'], (int) $position['character']);
                if ($virtualPos !== null) {
                    $phpSig = $this->features->phpIntelligence()->signatureHelp($virtualDoc, $virtualPos);
                    if ($phpSig !== null) {
                        return JsonRpcResponse::result($request->id(), $phpSig);
                    }
                }
            } catch (Throwable) {}
        }

        return JsonRpcResponse::result($request->id(), null);
    }
}

