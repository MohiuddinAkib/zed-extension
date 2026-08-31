<?php

declare(strict_types=1);

namespace App\Lsp;

class DocumentManager
{
    /**
     * The open documents, keyed by URI.
     *
     * @var array<string, Document>
     */
    protected array $documents = [];

    /**
     * Register a newly opened document.
     */
    public function open(string $uri, string $content): void
    {
        $this->documents[$uri] = new Document($uri, $content);
    }

    /**
     * Update the content of an open document.
     */
    public function update(string $uri, string $content): void
    {
        $this->documents[$uri] = new Document($uri, $content);
    }

    /**
     * Remove a closed document.
     */
    public function close(string $uri): void
    {
        unset($this->documents[$uri]);
    }

    /**
     * Get an open document.
     */
    public function get(string $uri): ?Document
    {
        return $this->documents[$uri] ?? null;
    }

    /**
     * Get all open documents.
     *
     * @return array<int, Document>
     */
    public function all(): array
    {
        return array_values($this->documents);
    }

    /**
     * Determine if a document is currently open.
     */
    public function has(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }
}
