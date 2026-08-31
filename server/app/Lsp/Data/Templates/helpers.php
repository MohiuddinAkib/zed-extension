<?php

$formatter = new class
{
    public function signature(ReflectionFunction $function): string
    {
        $parameters = [];

        foreach ($function->getParameters() as $parameter) {
            $parameters[] = $this->parameterToString($parameter);
        }

        return $function->getName() . '(' . implode(', ', $parameters) . '): ' . $this->typeToString($function->getReturnType());
    }

    public function parameterToString(ReflectionParameter $parameter): string
    {
        $type = $this->typeToString($parameter->getType());
        $part = $type !== 'mixed' ? "{$type} " : '';
        $part .= $parameter->isPassedByReference() ? '&' : '';
        $part .= $parameter->isVariadic() ? '...' : '';
        $part .= '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $part .= ' = ' . $this->defaultValueToString($parameter);
        }

        return $part;
    }

    public function typeToString(?ReflectionType $type): string
    {
        return match (true) {
            $type instanceof ReflectionNamedType        => $this->namedTypeToString($type),
            $type instanceof ReflectionUnionType        => implode('|', array_map($this->typeToString(...), $type->getTypes())),
            $type instanceof ReflectionIntersectionType => implode('&', array_map($this->typeToString(...), $type->getTypes())),
            default                                     => 'mixed',
        };
    }

    public function namedTypeToString(ReflectionNamedType $type): string
    {
        $name = $type->getName();

        if (!$type->isBuiltin() && !in_array($name, ['self', 'parent', 'static'], true)) {
            $name = '\\' . $name;
        }

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' . $name : $name;
    }

    public function defaultValueToString(ReflectionParameter $parameter): string
    {
        if ($parameter->isDefaultValueConstant()) {
            return '\\' . $parameter->getDefaultValueConstantName();
        }

        $value = $parameter->getDefaultValue();

        return match (true) {
            is_null($value)    => 'null',
            is_numeric($value) => (string) $value,
            is_bool($value)    => $value ? 'true' : 'false',
            is_array($value)   => '[]',
            is_object($value)  => 'new \\' . get_class($value),
            default            => "'{$value}'",
        };
    }
};

$basePath = base_path();
$autoloadFiles = [];
$composerAutoloadFiles = base_path('vendor/composer/autoload_files.php');

if (file_exists($composerAutoloadFiles)) {
    $files = require $composerAutoloadFiles;
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_string($file) && file_exists($file)) {
                $autoloadFiles[realpath($file) ?: $file] = true;

                try {
                    include_once $file;
                } catch (Throwable) {
                    //
                }
            }
        }
    }
}

echo json_encode(collect(get_defined_functions()['user'] ?? [])
    ->mapWithKeys(function (string $functionName) use ($formatter, $autoloadFiles, $basePath): array {
        try {
            $reflection = new ReflectionFunction($functionName);
            $fileName = $reflection->getFileName();

            if (!is_string($fileName) || $fileName === '') {
                return [];
            }

            $realFile = realpath($fileName) ?: $fileName;
            $isAutoloaded = isset($autoloadFiles[$realFile]);
            $isProjectFile = str_starts_with($realFile, realpath($basePath) ?: $basePath);

            if (!$isAutoloaded && !$isProjectFile) {
                return [];
            }

            $returnType = $formatter->typeToString($reflection->getReturnType());
            if ($returnType === 'mixed' && ($docComment = $reflection->getDocComment())) {
                if (preg_match('/@return\s+([^\s]+)/', $docComment, $retMatch)) {
                    $returnType = $retMatch[1];
                }
            }

            return [
                strtolower($functionName) => [
                    'name'       => $functionName,
                    'signature'  => $formatter->signature($reflection),
                    'returnType' => $returnType,
                    'doc'        => 'Reflected helper function `' . $functionName . '`.',
                    'snippet'    => $functionName . '(' . ($reflection->getNumberOfRequiredParameters() > 0 ? '${1}' : '') . ')',
                    'source'     => LspHelper::relativePath($fileName),
                    'line'       => $reflection->getStartLine() ?: 1,
                    'origin'     => $isAutoloaded ? 'Composer Autoload Helper' : 'Project Helper',
                ],
            ];
        } catch (Throwable) {

            return [];
        }
    })
    ->all());
