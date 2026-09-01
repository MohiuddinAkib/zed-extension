<?php

declare(strict_types=1);

use App\Lsp\Semantics\MacroParameterSymbol;
use App\Lsp\Semantics\MacroSymbol;
use App\Lsp\Semantics\TypeRef;

test('MacroSymbol stores parameter metadata, return type, and source location', function () {
    $param1 = new MacroParameterSymbol(
        name: 'ttl',
        type: TypeRef::fromString('int'),
        required: false,
        defaultValue: '3600',
    );
    $param2 = new MacroParameterSymbol(
        name: 'tags',
        type: TypeRef::fromString('array'),
        required: true,
        defaultValue: null,
    );

    $macro = new MacroSymbol(
        name: 'withCaching',
        targetClass: 'Illuminate\Http\Client\PendingRequest',
        facadeClass: 'Illuminate\Support\Facades\Http',
        parameters: [$param1, $param2],
        returnType: TypeRef::fromString('\Illuminate\Http\Client\PendingRequest'),
        sourcePath: '/app/Providers/AppServiceProvider.php',
        sourceLine: 525,
        isStatic: true,
        documentation: 'Custom HTTP caching macro',
    );

    expect($macro->name)->toBe('withCaching');
    expect($macro->targetClass)->toBe('Illuminate\Http\Client\PendingRequest');
    expect($macro->facadeClass)->toBe('Illuminate\Support\Facades\Http');
    expect($macro->parameters)->toHaveCount(2);
    expect($macro->parameters[0]->defaultValue)->toBe('3600');
    expect($macro->returnType->displayName)->toBe('\Illuminate\Http\Client\PendingRequest');
    expect($macro->sourceLine)->toBe(525);
    expect($macro->formattedSignature())->toBe('withCaching(int $ttl = 3600, array $tags): \Illuminate\Http\Client\PendingRequest');
});

test('MacroParameterSymbol formatted returns proper signatures for mixed and typed parameters', function () {
    $mixedParam = new MacroParameterSymbol(
        name: 'value',
        type: TypeRef::mixed(),
    );
    expect($mixedParam->formatted())->toBe('$value');

    $typedDefaultParam = new MacroParameterSymbol(
        name: 'limit',
        type: TypeRef::fromString('int'),
        required: false,
        defaultValue: '10',
    );
    expect($typedDefaultParam->formatted())->toBe('int $limit = 10');

    $nullableParam = new MacroParameterSymbol(
        name: 'callback',
        type: TypeRef::fromString('?callable'),
        required: false,
        defaultValue: 'null',
    );
    expect($nullableParam->formatted())->toBe('?callable $callback = null');
});

test('MacroSymbol defaults return type to mixed and handles parameterless signatures', function () {
    $macro = new MacroSymbol(
        name: 'resetState',
        targetClass: 'Illuminate\Support\Collection',
    );

    expect($macro->returnType)->not->toBeNull();
    expect($macro->returnType->displayName)->toBe('mixed');
    expect($macro->parameters)->toBe([]);
    expect($macro->isStatic)->toBeTrue();
    expect($macro->facadeClass)->toBeNull();
    expect($macro->sourcePath)->toBeNull();
    expect($macro->sourceLine)->toBeNull();
    expect($macro->documentation)->toBe('');
    expect($macro->formattedSignature())->toBe('resetState(): mixed');
});
