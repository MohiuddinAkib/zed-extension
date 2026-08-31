<?php

declare(strict_types=1);

namespace App\Lsp\Runtime;

use App\Lsp\Contracts\ExceptionHandler;
use Symfony\Component\Process\Process;
use Throwable;

class PhpRuntimeResolver
{
    public const DEFAULT_TIMEOUT = 5.0;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected string $path,
        protected string $environment = 'auto',
        protected ?ExceptionHandler $exceptions = null,
        protected float $timeout = self::DEFAULT_TIMEOUT,
    ) {}

    /**
     * Resolve the runtime configuration (command vector, environment name, php version, binary path).
     *
     * @return array{
     *     command: string[],
     *     environment: string,
     *     version: ?string,
     *     binary: ?string,
     *     status: string
     * }
     */
    public function resolve(): array
    {
        $command = match ($this->environment) {
            'yerd'  => $this->yerd(),
            'lerd'  => $this->lerd(),
            'herd'  => $this->herd(),
            'valet' => $this->valet(),
            'sail'  => $this->sail(),
            'lando' => $this->lando(),
            'ddev'  => $this->ddev(),
            'local' => $this->local(),
            'auto'  => $this->auto(),
            default => ['php'],
        };

        $envName = $this->environment === 'auto' ? $this->detectEnvironmentName($command) : $this->environment;
        $version = $this->detectPhpVersion($command);
        $binary = $this->detectBinaryPath($command);

        return [
            'command'     => $command,
            'environment' => $envName,
            'version'     => $version,
            'binary'      => $binary,
            'status'      => $version !== null ? 'valid' : 'fallback',
        ];
    }

    /**
     * Auto-detect the PHP command vector.
     *
     * @return string[]
     */
    protected function auto(): array
    {
        foreach (['yerd', 'lerd', 'herd', 'valet', 'sail', 'lando', 'ddev', 'local'] as $environment) {
            $command = $this->{$environment}();

            if ($command !== ['php']) {
                return $command;
            }
        }

        return ['php'];
    }

    /**
     * Resolve Yerd PHP command.
     *
     * @return string[]
     */
    protected function yerd(): array
    {
        // Check if Yerd CLI is available
        $output = $this->run(['yerd', 'which-php']);
        if ($output !== null && !str_contains($output, 'command not found') && trim($output) !== '') {
            return $this->binaryCommand($output);
        }

        $yerdPhp = $this->run(['yerd', 'php', '-r', 'echo PHP_BINARY;']);
        if ($yerdPhp !== null && trim($yerdPhp) !== '') {
            return ['yerd', 'php'];
        }

        return ['php'];
    }

    /**
     * Resolve Lerd PHP command.
     *
     * @return string[]
     */
    protected function lerd(): array
    {
        $output = $this->run(['lerd', 'php', '-r', 'echo PHP_BINARY;']);
        if ($output !== null && trim($output) !== '') {
            return ['lerd', 'php'];
        }

        return ['php'];
    }

    /**
     * Resolve Herd PHP command.
     *
     * @return string[]
     */
    protected function herd(): array
    {
        $output = $this->run(['herd', 'which-php']);

        if ($output === null || str_contains($output, 'No usable PHP version found')) {
            return ['php'];
        }

        return $this->binaryCommand($output);
    }

    /**
     * Resolve Valet PHP command.
     *
     * @return string[]
     */
    protected function valet(): array
    {
        return $this->binaryCommand($this->run(['valet', 'which-php']));
    }

    /**
     * Resolve Sail PHP command.
     *
     * @return string[]
     */
    protected function sail(): array
    {
        $sailBinary = DIRECTORY_SEPARATOR === '\\' ? '.\\vendor\\bin\\sail' : './vendor/bin/sail';

        return $this->run([$sailBinary, 'ps']) === null
            ? ['php']
            : [$sailBinary, 'php'];
    }

    /**
     * Resolve Lando PHP command.
     *
     * @return string[]
     */
    protected function lando(): array
    {
        return $this->run(['lando', 'php', '-r', 'echo PHP_BINARY;']) === null
            ? ['php']
            : ['lando', 'php'];
    }

    /**
     * Resolve DDEV PHP command.
     *
     * @return string[]
     */
    protected function ddev(): array
    {
        return $this->run(['ddev', 'php', '-r', 'echo PHP_BINARY;']) === null
            ? ['php']
            : ['ddev', 'php'];
    }

    /**
     * Resolve Local PHP command.
     *
     * @return string[]
     */
    protected function local(): array
    {
        return $this->binaryCommand($this->run(['php', '-r', 'echo PHP_BINARY;']));
    }

    /**
     * Convert a detected PHP binary path into a command.
     *
     * @return string[]
     */
    protected function binaryCommand(?string $output): array
    {
        $binary = trim((string) $output);

        return $binary === '' ? ['php'] : [$binary];
    }

    /**
     * Detect the PHP version from command vector.
     */
    protected function detectPhpVersion(array $command): ?string
    {
        $output = $this->run([...$command, '-r', 'echo PHP_VERSION;']);

        return $output !== null && trim($output) !== '' ? trim($output) : null;
    }

    /**
     * Detect the PHP binary path from command vector.
     */
    protected function detectBinaryPath(array $command): ?string
    {
        $output = $this->run([...$command, '-r', 'echo PHP_BINARY;']);

        return $output !== null && trim($output) !== '' ? trim($output) : null;
    }

    /**
     * Determine environment name from command vector.
     */
    protected function detectEnvironmentName(array $command): string
    {
        $cmdStr = implode(' ', $command);
        if (str_contains($cmdStr, 'yerd')) {
            return 'yerd';
        }
        if (str_contains($cmdStr, 'lerd')) {
            return 'lerd';
        }
        if (str_contains($cmdStr, 'herd') || str_contains($cmdStr, 'Herd')) {
            return 'herd';
        }
        if (str_contains($cmdStr, 'valet')) {
            return 'valet';
        }
        if (str_contains($cmdStr, 'sail')) {
            return 'sail';
        }
        if (str_contains($cmdStr, 'lando')) {
            return 'lando';
        }
        if (str_contains($cmdStr, 'ddev')) {
            return 'ddev';
        }

        return 'local';
    }

    /**
     * Run a command with bounded timeout.
     *
     * @param  string[]  $command
     */
    protected function run(array $command): ?string
    {
        try {
            $process = new Process($command, $this->path, timeout: $this->timeout);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable $e) {
            if ($this->exceptions) {
                $this->exceptions->report($e);
            }

            return null;
        }
    }
}
