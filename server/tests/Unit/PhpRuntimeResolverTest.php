<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Runtime\PhpRuntimeResolver;

test('PhpRuntimeResolver resolves local and auto environments safely', function () {
    $tempDir = sys_get_temp_dir();
    $resolver = new PhpRuntimeResolver($tempDir, 'local');
    $res = $resolver->resolve();

    expect($res['command'])->not->toBeEmpty();
    expect($res['environment'])->toBe('local');
    expect($res['status'])->toBe('valid');
    expect($res['version'])->not->toBeNull();

    $autoResolver = new PhpRuntimeResolver($tempDir, 'auto');
    $autoRes = $autoResolver->resolve();

    expect($autoRes['command'])->not->toBeEmpty();
    expect($autoRes['status'])->toBe('valid');
});

test('PhpRuntimeResolver gracefully falls back on unavailable custom environments', function () {
    $tempDir = sys_get_temp_dir();
    $resolver = new PhpRuntimeResolver($tempDir, 'non_existent_env');
    $res = $resolver->resolve();

    expect($res['command'])->toBe(['php']);
});
