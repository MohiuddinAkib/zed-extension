<?php

declare(strict_types=1);

namespace App\Lsp\Features\BladePhp;

use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

class BladePhpHoverProvider implements HoverProvider
{
    protected bool $autoloaderRegistered = false;

    public function __construct(
        protected Project $project,
    ) {
        $this->registerAutoloader();
    }

    /**
     * Provide hover for PHP class and method calls in Blade documents.
     */
    public function get(Document $document, array $position): ?array
    {
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
        $isHoveringMethod = $match['isHoveringMethod'] && $methodName !== null && $methodName !== 'class';

        $range = [
            'start' => ['line' => $lineNumber, 'character' => $match['activeRange']['start']],
            'end' => ['line' => $lineNumber, 'character' => $match['activeRange']['end']],
        ];

        // 1. Try PHP Reflection first for full inheritance and vendor method resolution
        try {
            if (class_exists($className)) {
                $refClass = new ReflectionClass($className);

                if ($isHoveringMethod && $refClass->hasMethod($methodName)) {
                    $refMethod = $refClass->getMethod($methodName);
                    $signature = $this->formatMethodSignature($refMethod);
                    $doc = $this->formatDocComment($refMethod->getDocComment());
                    $source = $refMethod->getFileName() ? $this->relativePath($refMethod->getFileName()) . ':' . $refMethod->getStartLine() : '';

                    $markdown = "```php\n{$signature}\n```\n\n";
                    if ($doc !== '') {
                        $markdown .= "{$doc}\n\n";
                    }
                    if ($source !== '') {
                        $markdown .= "*Defined in:* `{$source}`\n";
                    }

                    return [
                        'contents' => [
                            'kind' => 'markdown',
                            'value' => $markdown,
                        ],
                        'range' => $range,
                    ];
                }

                // Class hover via Reflection
                $classSig = $this->formatClassSignature($refClass);
                $classDoc = $this->formatDocComment($refClass->getDocComment());
                $source = $refClass->getFileName() ? $this->relativePath($refClass->getFileName()) : '';

                $markdown = "```php\n{$classSig}\n```\n\n";
                if ($classDoc !== '') {
                    $markdown .= "{$classDoc}\n\n";
                }
                if ($source !== '') {
                    $markdown .= "*Defined in:* `{$source}`\n";
                }

                return [
                    'contents' => [
                        'kind' => 'markdown',
                        'value' => $markdown,
                    ],
                    'range' => $range,
                ];
            }
        } catch (Throwable) {}

        // 2. Fallback to file AST scanning
        $filePath = $this->resolveClassFilePath($className);
        if (!$filePath || !file_exists($filePath)) {
            return null;
        }

        $code = @file_get_contents($filePath);
        if (!$code) {
            return null;
        }

        if ($isHoveringMethod) {
            $methodInfo = $this->extractMethodInfo($code, $methodName);
            if ($methodInfo !== null) {
                $markdown = "```php\n<?php\n"
                    . "public static function {$methodName}({$methodInfo['params']})";

                if ($methodInfo['returnType'] !== '') {
                    $markdown .= ": {$methodInfo['returnType']};";
                } else {
                    $markdown .= ';';
                }
                $markdown .= "\n```\n\n";

                if ($methodInfo['doc'] !== '') {
                    $markdown .= "{$methodInfo['doc']}\n\n";
                }

                $markdown .= "*Class:* `\\{$className}`  \n";
                $markdown .= "*Defined in:* `{$this->relativePath($filePath)}:{$methodInfo['line']}`\n";

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
        $markdown = "```php\n<?php\nclass \\{$className}\n```\n\n";
        if ($classDoc !== '') {
            $markdown .= "{$classDoc}\n\n";
        }
        $markdown .= "*Defined in:* `{$this->relativePath($filePath)}`\n";

        return [
            'contents' => [
                'kind' => 'markdown',
                'value' => $markdown,
            ],
            'range' => $range,
        ];
    }

    /**
     * @return array{class: string, method: ?string, isHoveringMethod: bool, activeRange: array{start: int, end: int}}|null
     */
    protected function findPhpSymbolAtPosition(string $line, int $character): ?array
    {
        $pattern = '/(\\\\?[A-Z][a-zA-Z0-9_]*(?:\\\\[A-Z][a-zA-Z0-9_]*)+)(?:::([a-zA-Z0-9_]+))?/';
        if (!preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as $idx => $match) {
            $fullOffset = $match[1];
            $fullLength = strlen($match[0]);

            $classText = $matches[1][$idx][0];
            $classOffset = $matches[1][$idx][1];
            $classEnd = $classOffset + strlen($classText);

            $methodText = !empty($matches[2][$idx][0]) ? $matches[2][$idx][0] : null;
            $methodOffset = !empty($matches[2][$idx][1]) ? $matches[2][$idx][1] : null;
            $methodEnd = ($methodOffset !== null && $methodText !== null) ? $methodOffset + strlen($methodText) : null;

            if ($character >= $fullOffset && $character <= ($fullOffset + $fullLength)) {
                $isHoveringMethod = ($methodOffset !== null && $methodEnd !== null && $character >= ($classEnd + 2) && $character <= $methodEnd);

                $activeRange = $isHoveringMethod && $methodOffset !== null && $methodEnd !== null
                    ? ['start' => $methodOffset, 'end' => $methodEnd]
                    : ['start' => $classOffset, 'end' => $classEnd];

                return [
                    'class' => $classText,
                    'method' => $methodText,
                    'isHoveringMethod' => $isHoveringMethod,
                    'activeRange' => $activeRange,
                ];
            }
        }

        return null;
    }

    protected function formatMethodSignature(ReflectionMethod $method): string
    {
        $declaringClass = $method->getDeclaringClass()->getShortName();
        $methodName = $method->getName();
        $isStatic = $method->isStatic();
        $modifiers = $isStatic ? 'static function' : 'public function';

        $params = $method->getParameters();
        $paramStrings = [];

        foreach ($params as $param) {
            $pStr = '';
            $type = $param->getType();
            if ($type !== null) {
                $typeName = (string) $type;
                if ($param->isArray()) {
                    $typeName = 'array';
                }
                $pStr .= "{$typeName} ";
            }

            if ($param->isPassedByReference()) {
                $pStr .= '&';
            }

            if ($param->isVariadic()) {
                $pStr .= '...';
            }

            $pStr .= '$' . $param->getName();

            if ($param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                $pStr .= ' = ' . $this->formatDefaultValue($default, $param);
            }

            $paramStrings[] = $pStr;
        }

        $returnType = $method->getReturnType();
        $returnStr = $returnType !== null ? ': ' . (string) $returnType : '';

        if (count($paramStrings) <= 1) {
            $paramsFormatted = implode(', ', $paramStrings);
            return "<?php\n{$modifiers} {$declaringClass}::{$methodName}({$paramsFormatted}){$returnStr}";
        }

        $paramsFormatted = "\n    " . implode(",\n    ", $paramStrings) . "\n";
        return "<?php\n{$modifiers} {$declaringClass}::{$methodName}({$paramsFormatted}){$returnStr}";
    }

    protected function formatDefaultValue(mixed $value, ReflectionParameter $param): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_array($value)) {
            return '[]';
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if ($param->isDefaultValueConstant()) {
            return (string) $param->getDefaultValueConstantName();
        }
        return var_export($value, true);
    }

    protected function formatClassSignature(ReflectionClass $class): string
    {
        $shortName = $class->getShortName();
        $parent = $class->getParentClass();
        $extends = $parent ? " extends {$parent->getShortName()}" : '';

        return "<?php\nclass {$shortName}{$extends}";
    }

    protected function formatDocComment(string|false $doc): string
    {
        if (!$doc) {
            return '';
        }

        // Clean docblock markers
        $lines = explode("\n", $doc);
        $cleaned = [];
        foreach ($lines as $l) {
            $t = trim($l, "/* \t\r\n");
            if ($t !== '' && !str_starts_with($t, '@')) {
                $cleaned[] = $t;
            }
        }

        return implode("\n", $cleaned);
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
                $params = trim($m[1]);
                $returnType = !empty($m[2]) ? trim($m[2]) : '';

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
