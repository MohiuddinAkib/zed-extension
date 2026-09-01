<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladeVariables;

class BladeLoopScopeInfo
{
    /** @var string Directive type ('foreach', 'forelse', 'for', 'while') */
    public string $type = '';

    /** @var int 0-indexed start line of loop opening directive */
    public int $startLine = 0;

    /** @var int 0-indexed end line of loop closing directive */
    public int $endLine = PHP_INT_MAX;

    /** @var int Byte offset of opening directive start */
    public int $startOffset = 0;

    /** @var int Byte offset of closing directive end */
    public int $endOffset = PHP_INT_MAX;

    /** @var int Byte offset where inner loop scope variable declaration starts */
    public int $declarationOffset = 0;

    /** @var array<string, bool> Variables declared/assigned by this loop */
    public array $declaredVariables = [];

    /** @var BladeLoopScopeInfo|null */
    public ?BladeLoopScopeInfo $parent = null;

    /** @var array<int, BladeLoopScopeInfo> */
    public array $children = [];
}
