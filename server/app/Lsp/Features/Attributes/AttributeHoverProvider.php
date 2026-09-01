<?php

declare(strict_types=1);

namespace App\Lsp\Features\Attributes;

use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class AttributeHoverProvider implements HoverProvider
{
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide Laravel attribute/helper/facade argument hover links.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function get(Document $document, array $position): ?array
    {
        if (!$this->project->boolean('attributeHover', true)) {
            return null;
        }

        return (new AttributeDocumentMapper($this->project))->hover($document, $position);
    }
}
