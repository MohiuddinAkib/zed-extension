<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;

class Helpers implements DataProvider
{
    public function __construct(protected Project $project) {}

    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/helpers.php') ?: '';
    }

    /**
     * Parse reflected helper function data.
     *
     * @param  array<string, array<string, mixed>>  $data
     * @return array<string, array<string, mixed>>
     */
    public function parse(array $data): array
    {
        return collect($data)
            ->filter(fn (mixed $function): bool => is_array($function) && is_string($function['name'] ?? null))
            ->mapWithKeys(fn (array $function, string $name): array => [strtolower($name) => $function])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array
    {
        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            'composer.json',
            'composer.lock',
            'app/{,*,**/*}.php',
            'bootstrap/{,*,**/*}.php',
        ];
    }
}
