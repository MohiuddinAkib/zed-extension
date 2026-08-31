<?php

declare(strict_types=1);

namespace App\Lsp\Contracts;

interface FileWatcher
{
    /**
     * Initialize the file watcher after client registration.
     */
    public function initialize(): void;

    /**
     * Get file watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array;

    /**
     * Handle changed workspace-relative paths.
     *
     * @param  array<int, string>  $changes
     */
    public function onFileChange(array $changes): void;
}
