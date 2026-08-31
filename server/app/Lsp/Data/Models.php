<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;

class Models implements DataProvider
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the models template to run.
     */
    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/models.php') ?: '';
    }

    /**
     * Parse the raw model data.
     *
     * @param  array<string, mixed>  $data
     * @return array{models: array<string, array<string, mixed>>, builderMethods: array<int, array<string, mixed>>}
     */
    public function parse(array $data): array
    {
        $models = $data['models'] ?? [];
        $builderMethods = $data['builderMethods'] ?? [];

        return [
            'models'         => is_array($models) ? $models : [],
            'builderMethods' => is_array($builderMethods) ? $builderMethods : [],
        ];
    }

    /**
     * Get data.
     *
     * @return array{models: array<string, array<string, mixed>>, builderMethods: array<int, array<string, mixed>>}
     */
    public function get(): array
    {
        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * Get model-related watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            'app/{,*,**/*}.php',
            'database/migrations/{,*,**/*}.php',
            'composer.json',
            'composer.lock',
        ];
    }
}
