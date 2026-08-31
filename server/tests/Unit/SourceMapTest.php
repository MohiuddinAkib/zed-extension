<?php

declare(strict_types=1);

use App\Lsp\Semantics\SourceMap;

test('sourcemap maps blade offsets to virtual offsets and back accurately', function () {
    $blade = "<div>\n    <h1>{{ \$user->name }}</h1>\n</div>";
    $virtual = "<?php\n\$__echo_1 = (\$user->name);\n";

    $map = new SourceMap($blade, $virtual);

    // {{ $user->name }} starts at offset 17 in Blade ('$user->name' is 17..28)
    // In Virtual PHP, '$user->name' is at offset 20..31
    $bladeStart = strpos($blade, '$user->name');
    $virtualStart = strpos($virtual, '$user->name');
    $length = strlen('$user->name');

    $map->addMapping($bladeStart, $virtualStart, $length);

    expect($map->bladeToVirtualOffset($bladeStart))->toBe($virtualStart);
    expect($map->bladeToVirtualOffset($bladeStart + 5))->toBe($virtualStart + 5);
    expect($map->virtualToBladeOffset($virtualStart))->toBe($bladeStart);
    expect($map->virtualToBladeOffset($virtualStart + 5))->toBe($bladeStart + 5);

    // Line and character position mapping
    $bladePos = $map->virtualToBladePosition(1, 13); // line 1, char 13 in virtual is '$' of $user
    expect($bladePos)->not->toBeNull();
    expect($bladePos['line'])->toBe(1);
    expect($bladePos['character'])->toBe(11); // char 11 in Blade line 1

    $virtualPos = $map->bladeToVirtualPosition(1, 11);
    expect($virtualPos)->not->toBeNull();
    expect($virtualPos['line'])->toBe(1);
    expect($virtualPos['character'])->toBe(13);
});
