<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladePhp;

use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;

class BladePhpProvider implements HoverProvider, LinkProvider
{
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide hover for PHP class and method calls in Blade documents.
     */
    public function get(Document $document, ?array $position = null): array|null
    {
        // When called as LinkProvider (single argument Document)
        if ($position === null) {
            return $this->getLinks($document);
        }

        if (!str_ends_with($document->uri, '.blade.php')) {
            return null;
        }

        $lineNumber = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($lineNumber) || !is_int($character)) {
            return null;
        }

        $lines = explode("\n", $document->content);
        $line = $lines[$lineNumber] ?? '';

        $match = $this->findPhpSymbolAtPosition($line, $character);
        if ($match === null) {
            return null;
        }

        $className = ltrim($match['class'], '\\');
        $methodName = $match['method'] ?? null;
        $filePath = $this->resolveClassFilePath($className);

        if (!$filePath || !file_exists($filePath)) {
            return null;
        }

        $code = @file_get_contents($filePath);
        if (!$code) {
            return null;
        }

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $match['start']],
            'end' => ['line' => $lineNumber, 'character' => $match['end']],
        ];

        if ($methodName !== null && $methodName !== 'class') {
            $methodInfo = $this->extractMethodInfo($code, $methodName);
            if ($methodInfo !== null) {
                $markdown = "```php\n"
                    . "public static function {$methodName}({$methodInfo['params']})";

                if ($methodInfo['returnType'] !== '') {
                    $markdown .= ": {$methodInfo['returnType']}";
                }
                $markdown .= "\n```\n\n";

                if ($methodInfo['doc'] !== '') {
                    $markdown .= "{$methodInfo['doc']}\n\n";
                }

                $markdown .= "**Class**: `\\{$className}`  \n";
                $markdown .= "**Defined in**: `{$this->relativePath($filePath)}:{$methodInfo['line']}`\n";

                return [
                    'contents' => [
                        'kind' => 'markdown',
                        'value' => $markdown,
                    ],
                    'range' => $range,
                ];
            }
        }

        // Class hover
        $classDoc = $this->extractClassDoc($code);
        $markdown = "```php\nclass \\{$className}\n```\n\n";
        if ($classDoc !== '') {
            $markdown .= "{$classDoc}\n\n";
        }
        $markdown .= "**Defined in**: `{$this->relativePath($filePath)}`\n";

        return [
            'contents' => [
                'kind' => 'markdown',
                'value' => $markdown,
            ],
            'range' => $range,
        ];
    }

    /**
     * Get document links for PHP classes and static methods in Blade.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getLinks(Document $document): array
    {
        if (!str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $links = [];
        $lines = explode("\n", $document->content);

        foreach ($lines as $lineIndex => $line) {
            $pattern = '/\\\\?([A-Z][a-zA-Z0-9_]*(?:\\\\[A-Z][a-zA-Z0-9_]*)+)(?:::([a-zA-Z0-9_]+))?/';
            if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $idx => $match) {
                $fullText = $match[0];
                $offset = $match[1];
                $className = ltrim($matches[1][$idx][0], '\\');
                $methodName = !empty($matches[2][$idx][0]) ? $matches[2][$idx][0] : null;

                $filePath = $this->resolveClassFilePath($className);
                if (!$filePath || !file_exists($filePath)) {
                    continue;
                }

                $targetLine = 1;
                if ($methodName !== null && $methodName !== 'class') {
                    $code = @file_get_contents($filePath);
                    if ($code) {
                        $methodInfo = $this->extractMethodInfo($code, $methodName);
                        if ($methodInfo !== null) {
                            $targetLine = $methodInfo['line'];
                        }
                    }
                }

                $range = [
                    'start' => ['line' => $lineIndex, 'character' => $offset],
                    'end' => ['line' => $lineIndex, 'character' => $offset + strlen($fullText)],
                ];

                $targetUri = FileUri::fromPath($filePath);
                $links[] = [
                    'range' => $range,
                    'target' => "{$targetUri}#L{$targetLine}",
                    'tooltip' => "Go to {$className}" . ($methodName ? "::{$methodName}" : ''),
                ];
            }
        }

        return $links;
    }

    /**
     * @return array{class: string, method: ?string, start: int, end: int}|null
     */
    protected function findPhpSymbolAtPosition(string $line, int $character): ?array
    {
        $pattern = '/\\\\?([A-Z][a-zA-Z0-9_]*(?:\\\\[A-Z][a-zA-Z0-9_]*)+)(?:::([a-zA-Z0-9_]+))?/';
        if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as $idx => $match) {
            $offset = $match[1];
            $length = strlen($match[0]);

            if ($character >= $offset && $character <= ($offset + $length)) {
                return [
                    'class' => $matches[1][$idx][0],
                    'method' => !empty($matches[2][$idx][0]) ? $matches[2][$idx][0] : null,
                    'start' => $offset,
                    'end' => $offset + $length,
                ];
            }
        }

        return null;
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

        // Check Modules e.g. Modules\Blog\
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
                $params = trim($m[1]);
                $returnType = !empty($m[2]) ? trim($m[2]) : '';

                // Look for docblock preceding the method
                $doc = '';
                if ($idx > 0) {
                    $docLines = [];
                    for ($d = $idx - 1; $d >= max(0, $idx - 15); $d--) {
                        $trimLine = trim($lines[$d]);
                        if (str_ends_with($trimLine, '*/') || str_starts_with($trimLine, '*') || str_starts_with($trimLine, '/**')) {
                            array_unshift($docLines, $trimLine);
                            if (str_starts_with($trimLine, '/**')) {
                                break;
                            }
                        } else {
                            break;
                        }
                    }
                    $doc = implode("\n", $docLines);
                }

                return [
                    'line' => $idx + 1,
                    'params' => $params,
                    'returnType' => $returnType,
                    'doc' => $doc,
                ];
            }
        }

        return null;
    }

    protected function extractClassDoc(string $code): string
    {
        if (preg_match('/\/\*\*[\s\S]*?\*\/\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+/i', $code, $m)) {
            return trim($m[0]);
        }

        return '';
    }

    protected function relativePath(string $path): string
    {
        $base = rtrim($this->project->path(), '/\\');
        $path = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $base);

        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
    }
}
