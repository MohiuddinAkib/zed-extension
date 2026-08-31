<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladePhp;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use ReflectionClass;
use Throwable;

class BladePhpLinkProvider implements LinkProvider
{
    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
    ) {
        $this->registerAutoloader();
    }

    /**
     * Get document links for PHP classes and static methods in Blade.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $links = [];
        $lines = explode("\n", $document->content);

        foreach ($lines as $lineIndex => $line) {
            $pattern = '/(\\\\?[A-Z][a-zA-Z0-9_]*(?:\\\\[A-Z][a-zA-Z0-9_]*)+)(?:::([a-zA-Z0-9_]+))?/';
            if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $idx => $match) {
                $classText = $matches[1][$idx][0];
                $classOffset = $matches[1][$idx][1];
                $className = ltrim($classText, '\\');

                $methodText = !empty($matches[2][$idx][0]) ? $matches[2][$idx][0] : null;
                $methodOffset = !empty($matches[2][$idx][1]) ? $matches[2][$idx][1] : null;

                // 1. Link for Class Name
                $filePath = $this->resolveClassFilePath($className);
                if ($filePath && file_exists($filePath)) {
                    $classTarget = FileUri::fromPath($filePath) . '#L1';
                    $links[] = [
                        'range' => [
                            'start' => ['line' => $lineIndex, 'character' => $classOffset],
                            'end' => ['line' => $lineIndex, 'character' => $classOffset + strlen($classText)],
                        ],
                        'target' => $classTarget,
                        'tooltip' => "Go to class {$className}",
                    ];
                }

                // 2. Link for Method Name (if present)
                if ($methodText !== null && $methodText !== 'class' && $methodOffset !== null) {
                    $methodFilePath = $filePath;
                    $targetLine = 1;

                    // Try reflection first
                    try {
                        if (class_exists($className)) {
                            $refClass = new ReflectionClass($className);
                            if ($refClass->hasMethod($methodText)) {
                                $refMethod = $refClass->getMethod($methodText);
                                if ($refMethod->getFileName()) {
                                    $methodFilePath = $refMethod->getFileName();
                                    $targetLine = $refMethod->getStartLine();
                                }
                            }
                        }
                    } catch (Throwable) {}

                    if ($targetLine === 1 && $methodFilePath && file_exists($methodFilePath)) {
                        $code = @file_get_contents($methodFilePath);
                        if ($code) {
                            $methodInfo = $this->extractMethodInfo($code, $methodText);
                            if ($methodInfo !== null) {
                                $targetLine = $methodInfo['line'];
                            }
                        }
                    }

                    if ($methodFilePath && file_exists($methodFilePath)) {
                        $methodTarget = FileUri::fromPath($methodFilePath) . "#L{$targetLine}";
                        $links[] = [
                            'range' => [
                                'start' => ['line' => $lineIndex, 'character' => $methodOffset],
                                'end' => ['line' => $lineIndex, 'character' => $methodOffset + strlen($methodText)],
                            ],
                            'target' => $methodTarget,
                            'tooltip' => "Go to {$className}::{$methodText}",
                        ];
                    }
                }
            }
        }

        return $links;
    }

    protected function resolveClassFilePath(string $className): ?string
    {
        $basePath = rtrim($this->project->path(), '/\\');

        if (str_starts_with($className, 'App\\')) {
            $rel = 'app/' . str_replace('\\', '/', substr($className, 4)) . '.php';
            $full = "{$basePath}/{$rel}";
            if (file_exists($full)) {
                return $full;
            }
        }

        if (str_starts_with($className, 'Modules\\')) {
            $rel = str_replace('\\', '/', $className) . '.php';
            $full = "{$basePath}/{$rel}";
            if (file_exists($full)) {
                return $full;
            }
        }

        return null;
    }

    /**
     * @return array{line: int, params: string, returnType: string, doc: string}|null
     */
    protected function extractMethodInfo(string $code, string $methodName): ?array
    {
        $lines = explode("\n", $code);
        $methodRegex = '/function\s+' . preg_quote($methodName, '/') . '\s*\(([^)]*)\)(?:\s*:\s*([a-zA-Z0-9_\\\\?|&]+))?/i';

        foreach ($lines as $idx => $line) {
            if (preg_match($methodRegex, $line, $m)) {
                return [
                    'line' => $idx + 1,
                    'params' => trim($m[1]),
                    'returnType' => !empty($m[2]) ? trim($m[2]) : '',
                    'doc' => '',
                ];
            }
        }

        return null;
    }

    protected function registerAutoloader(): void
    {
        if ($this->autoloaderRegistered) {
            return;
        }

        $basePath = rtrim($this->project->path(), '/\\');
        $autoloadPath = "{$basePath}/vendor/autoload.php";
        if (file_exists($autoloadPath)) {
            try {
                @include_once $autoloadPath;
                $this->autoloaderRegistered = true;
            } catch (Throwable) {}
        }
    }
}
