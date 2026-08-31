<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladePhp;

use App\Lsp\Analysis\BladeDocumentCompiler;
use App\Lsp\Contracts\DiagnosticProvider;
use App\Lsp\Document;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use Throwable;

class BladePhpDiagnosticProvider implements DiagnosticProvider
{
    protected BladeDocumentCompiler $compiler;

    public function __construct(
        protected Project $project,
        protected FeatureRegistry $features,
    ) {
        $this->compiler = new BladeDocumentCompiler($this->project);
    }

    /**
     * Provide diagnostics for PHP syntax in Blade templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        try {
            $scope = $this->features->scopeResolver()->resolve($document);
            $virtualDoc = $this->compiler->compile($document, $scope);
            return $this->features->phpIntelligence()->diagnostics($virtualDoc);
        } catch (Throwable) {
            return [];
        }
    }
}
