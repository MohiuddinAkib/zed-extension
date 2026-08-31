<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladePhpAstAnalyzer;

test('BladePhpAstAnalyzer flags variables in isset and empty as guarded', function () {
    $analyzer = new BladePhpAstAnalyzer();

    $blade = <<<'BLADE'
@if(isset($maybeSet) || empty($maybeEmpty))
    <p>{{ $maybeSet }}</p>
@endif
@isset($directiveSet)
    <span>{{ $directiveSet }}</span>
@endisset
@empty($directiveEmpty)
    <span>Empty</span>
@endempty
BLADE;

    $expressions = $analyzer->extractAllExpressions($blade);
    $variables = collect($expressions)->where('kind', 'variable')->all();

    $issetVar = collect($variables)->first(fn ($v) => $v['name'] === 'maybeSet' && ($v['isGuarded'] ?? false));
    expect($issetVar)->not->toBeNull();

    $emptyVar = collect($variables)->first(fn ($v) => $v['name'] === 'maybeEmpty' && ($v['isGuarded'] ?? false));
    expect($emptyVar)->not->toBeNull();

    $dirSetVar = collect($variables)->first(fn ($v) => $v['name'] === 'directiveSet' && ($v['isGuarded'] ?? false));
    expect($dirSetVar)->not->toBeNull();

    $dirEmptyVar = collect($variables)->first(fn ($v) => $v['name'] === 'directiveEmpty' && ($v['isGuarded'] ?? false));
    expect($dirEmptyVar)->not->toBeNull();
});

test('BladePhpAstAnalyzer flags assignment targets as isAssignment', function () {
    $analyzer = new BladePhpAstAnalyzer();

    $blade = <<<'BLADE'
@php
    $newCount = 42;
@endphp
BLADE;

    $expressions = $analyzer->extractAllExpressions($blade);
    $newCountVar = collect($expressions)->firstWhere('name', 'newCount');

    expect($newCountVar)->not->toBeNull();
    expect($newCountVar['isAssignment'] ?? false)->toBeTrue();
});

test('BladePhpAstAnalyzer flags closure and arrow function parameters', function () {
    $analyzer = new BladePhpAstAnalyzer();

    $blade = <<<'BLADE'
{{ $users->map(fn ($userItem) => $userItem->name) }}
BLADE;

    $expressions = $analyzer->extractAllExpressions($blade);
    $userParamVars = collect($expressions)->where('name', 'userItem')->values()->all();

    expect($userParamVars)->toHaveCount(2);
    expect($userParamVars[0]['isAssignment'] ?? false)->toBeTrue();
    expect($userParamVars[1]['isClosureParam'] ?? false)->toBeTrue();
});

