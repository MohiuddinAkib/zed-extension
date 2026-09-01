<?php

declare(strict_types=1);

namespace App\Lsp\Features\Attributes;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class AttributeLinkProvider implements LinkProvider
{
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide Laravel attribute/helper/facade argument document links.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('attributeLink', true)) {
            return [];
        }

        return (new AttributeDocumentMapper($this->project))->links($document);
    }
}
