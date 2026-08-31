<?php

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

$rules = collect();

try {
    if (class_exists(Validator::class)) {
        $reflection = new ReflectionClass(Validator::class);

        collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED))
            ->filter(fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'validate') && strlen($method->getName()) > 8)
            ->each(function (ReflectionMethod $method) use ($rules): void {
                $rule = Str::snake(substr($method->getName(), 8));

                $rules[$rule] = [
                    'name'       => $rule,
                    'method'     => $method->getName(),
                    'source'     => $method->getFileName() ? LspHelper::relativePath($method->getFileName()) : null,
                    'line'       => $method->getStartLine() ?: null,
                    'origin'     => 'Laravel Validator Reflection',
                    'hasParams'  => $method->getNumberOfParameters() > 2,
                ];
            });
    }
} catch (Throwable) {
    //
}

try {
    $factory = app('validator');
    $factoryReflection = new ReflectionObject($factory);

    foreach (['extensions', 'implicitExtensions', 'dependentExtensions'] as $propertyName) {
        if (!$factoryReflection->hasProperty($propertyName)) {
            continue;
        }

        $property = $factoryReflection->getProperty($propertyName);
        $property->setAccessible(true);
        $extensions = $property->getValue($factory);

        if (!is_array($extensions)) {
            continue;
        }

        foreach ($extensions as $name => $extension) {
            $rule = Str::snake((string) $name);

            $rules[$rule] = [
                'name'      => $rule,
                'method'    => null,
                'source'    => null,
                'line'      => null,
                'origin'    => 'Custom Validator Extension',
                'hasParams' => true,
            ];
        }
    }
} catch (Throwable) {
    //
}

echo $rules->sortKeys()->toJson();
