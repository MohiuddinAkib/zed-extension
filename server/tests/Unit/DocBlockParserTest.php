<?php

declare(strict_types=1);

use App\Lsp\Analysis\DocBlockParser;

test('docblock parser extracts properties and methods from complex docblocks', function () {
    $parser = new DocBlockParser();

    $doc = <<<'DOC'
    /**
     * The system alert mail mailable.
     *
     * @property-read "staging"|"production" $environment
     * @property array{ip: string, user_agent?: string} $device The user device info
     * @method static \Illuminate\Database\Eloquent\Builder whereStatus(string $status = 'open')
     */
    DOC;

    $properties = $parser->extractProperties($doc);
    expect($properties)->toHaveKey('environment')
        ->toHaveKey('device');
    expect($properties['environment'])->toBe('("staging" | "production")');
    expect($properties['device'])->toBe('array{ip: string, user_agent?: string}');

    $methods = $parser->extractMethods($doc);
    expect($methods)->toHaveKey('whereStatus');
    expect($methods['whereStatus']['returnType'])->toBe('\Illuminate\Database\Eloquent\Builder');
    expect($methods['whereStatus']['signature'])->toBe("(string \$status = 'open'): \\Illuminate\\Database\\Eloquent\\Builder");
});

test('docblock parser extracts array shape keys with exact types and optionality', function () {
    $parser = new DocBlockParser();

    $shape = <<<'SHAPE'
    array{
        id: int,
        name: string,
        tags?: list<string>,
        meta: array{ip: string}
    }
    SHAPE;

    $keys = $parser->extractArrayShapeKeys($shape);
    expect($keys)->toHaveKey('id')
        ->toHaveKey('name')
        ->toHaveKey('tags')
        ->toHaveKey('meta');

    expect($keys['id']['type'])->toBe('int');
    expect($keys['id']['optional'])->toBeFalse();

    expect($keys['tags']['type'])->toBe('list<string>');
    expect($keys['tags']['optional'])->toBeTrue();

    expect($keys['meta']['type'])->toBe('array{ip: string}');
});

test('docblock parser unwraps generic collection and array item types', function () {
    $parser = new DocBlockParser();

    expect($parser->unwrapItemType('Collection<int, App\\Models\\User>'))->toBe('App\\Models\\User');
    expect($parser->unwrapItemType('Illuminate\\Support\\Collection<App\\Models\\Post>'))->toBe('App\\Models\\Post');
    expect($parser->unwrapItemType('App\\Models\\Ticket[]'))->toBe('App\\Models\\Ticket');
    expect($parser->unwrapItemType('list<string>'))->toBe('string');
    expect($parser->unwrapItemType('string'))->toBeNull();
});

test('docblock parser extracts @return tag type string', function () {
    $parser = new DocBlockParser();

    $doc = <<<'DOC'
    /**
     * Get auth manager.
     *
     * @return \Illuminate\Auth\AuthManager|\Illuminate\Contracts\Auth\Guard
     */
    DOC;
    expect($parser->extractReturnTag($doc))->toBe('(\Illuminate\Auth\AuthManager | \Illuminate\Contracts\Auth\Guard)');

    $docSimple = <<<'DOC'
    /**
     * @return string
     */
    DOC;
    expect($parser->extractReturnTag($docSimple))->toBe('string');

    $docEmpty = <<<'DOC'
    /**
     * No return tag here
     */
    DOC;
    expect($parser->extractReturnTag($docEmpty))->toBeNull();
});

