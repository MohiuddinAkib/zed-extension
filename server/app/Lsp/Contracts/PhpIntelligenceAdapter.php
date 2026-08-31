<?php

declare(strict_types=1);

namespace App\Lsp\Contracts;

use App\Lsp\Semantics\VirtualDocument;

interface PhpIntelligenceAdapter
{
    /**
     * Provide completion items for a position inside a virtual PHP document.
     *
     * @param  array{line: int, character: int}  $position
     * @return list<array<string, mixed>>
     */
    public function completion(VirtualDocument $document, array $position): array;

    /**
     * Provide hover information for a position inside a virtual PHP document.
     *
     * @param  array{line: int, character: int}  $position
     * @return array<string, mixed>|null
     */
    public function hover(VirtualDocument $document, array $position): ?array;

    /**
     * Provide definition targets for a position inside a virtual PHP document.
     *
     * @param  array{line: int, character: int}  $position
     * @return list<array<string, mixed>>
     */
    public function definition(VirtualDocument $document, array $position): array;

    /**
     * Provide signature help for a position inside a virtual PHP document.
     *
     * @param  array{line: int, character: int}  $position
     * @return array<string, mixed>|null
     */
    public function signatureHelp(VirtualDocument $document, array $position): ?array;

    /**
     * Provide diagnostics for a virtual PHP document.
     *
     * @return list<array<string, mixed>>
     */
    public function diagnostics(VirtualDocument $document): array;

    /**
     * Provide references for a position inside a virtual PHP document.
     *
     * @param  array{line: int, character: int}  $position
     * @return list<array<string, mixed>>
     */
    public function references(VirtualDocument $document, array $position, bool $includeDeclaration = true): array;
}
