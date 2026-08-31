<?php

declare(strict_types=1);

namespace App\Lsp\Semantics;

final class VirtualDocument
{
    /**
     * @param  list<string>  $injectedVariables
     */
    public function __construct(
        public string $bladeUri,
        public string $phpCode,
        public SourceMap $sourceMap,
        public ?ViewScope $scope = null,
        public array $injectedVariables = [],
    ) {}

    public function virtualUri(): string
    {
        return $this->bladeUri . '.__virtual.php';
    }
}
