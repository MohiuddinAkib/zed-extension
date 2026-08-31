<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\Features\BladeVariables\BladeVariableRenameProvider;
use App\Lsp\Project;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentRename implements Method
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected DocumentManager $documents,
        protected Project $project,
    ) {}

    /**
     * Handle the textDocument/rename request.
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
        $newName = (string) $request->get('newName', '');

        if (!is_array($position) || $newName === '') {
            return JsonRpcResponse::result($request->id(), null);
        }

        $provider = new BladeVariableRenameProvider($this->project);
        $result = $provider->rename($document, $position, $newName);

        return JsonRpcResponse::result($request->id(), $result);
    }
}
