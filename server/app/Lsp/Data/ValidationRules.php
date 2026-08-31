<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;

class ValidationRules implements DataProvider
{
    public function __construct(protected Project $project) {}

    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/validation-rules.php') ?: '';
    }

    /**
     * @param  array<string, array<string, mixed>>  $data
     * @return array<string, array<string, mixed>>
     */
    public function parse(array $data): array
    {
        return collect($data)
            ->filter(fn (mixed $rule): bool => is_array($rule) && is_string($rule['name'] ?? null))
            ->mapWithKeys(fn (array $rule, string $name): array => [strtolower($name) => $rule])
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
            'app/Providers/{,*,**/*}.php',
            'app/Rules/{,*,**/*}.php',
            'composer.json',
            'composer.lock',
        ];
    }
}
