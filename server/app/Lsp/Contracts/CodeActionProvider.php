<?php

declare(strict_types=1);

namespace App\Lsp\Contracts;

use App\Lsp\CodeActions\CodeActionContext;
use App\Lsp\Document;

interface CodeActionProvider
{
    /**
     * Provide code actions for the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, CodeActionContext $context): array;
}
