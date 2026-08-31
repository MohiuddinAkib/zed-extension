<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladePhpAstAnalyzer;

test('blade php ast analyzer extracts all members and calls with exact AST ranges', function () {
    $analyzer = new BladePhpAstAnalyzer();

    $blade = <<<'BLADE'
<div>
    <h1>#{{ $ticket->id }} &mdash; {{ $ticket->status?->value }}</h1>
    @php
        $statusColor = match ($ticket->status?->value ?? 'open') {
            'open' => 'bg-amber-50',
            default => 'bg-zinc-100',
        };
    @endphp
    <span>{{ $ticket->status?->getLabel() }}</span>
    <p>{{ app('db.connection')->getDatabaseName() }}</p>
    <p>{{ auth()->user()->name }}</p>
</div>
BLADE;

    $expressions = $analyzer->extractAllExpressions($blade);

    // Verify $ticket->id
    $idExpr = collect($expressions)->firstWhere('name', 'id');
    expect($idExpr)->not->toBeNull();
    expect($idExpr['rootVar'])->toBe('ticket');
    expect($idExpr['kind'])->toBe('property');

    // Verify $ticket->status?->value
    $valueExpr = collect($expressions)->firstWhere('name', 'value');
    expect($valueExpr)->not->toBeNull();
    expect($valueExpr['rootVar'])->toBe('ticket');
    expect($valueExpr['chain'])->toBe('->status');

    // Verify app('db.connection')->getDatabaseName()
    $dbExpr = collect($expressions)->firstWhere('name', 'getDatabaseName');
    expect($dbExpr)->not->toBeNull();
    expect($dbExpr['rootCall'])->toBe('app');
    expect($dbExpr['rootCallArg'])->toBe('db.connection');

    // Verify auth()->user()->name
    $authExpr = collect($expressions)->firstWhere('name', 'name');
    expect($authExpr)->not->toBeNull();
    expect($authExpr['rootCall'])->toBe('auth');
    expect($authExpr['chain'])->toBe('->user()');
});

test('blade php ast analyzer finds expression at exact line and character position', function () {
    $analyzer = new BladePhpAstAnalyzer();

    $blade = <<<'BLADE'
<h2>{{ $ticket->status?->value }}</h2>
BLADE;

    // 'status' is at line 0, char 15..20
    $statusExpr = $analyzer->findExpressionAtPosition($blade, 0, 16);
    expect($statusExpr)->not->toBeNull();
    expect($statusExpr['name'])->toBe('status');
    expect($statusExpr['rootVar'])->toBe('ticket');

    // 'value' is at line 0, char 24..28
    $valueExpr = $analyzer->findExpressionAtPosition($blade, 0, 25);
    expect($valueExpr)->not->toBeNull();
    expect($valueExpr['name'])->toBe('value');
    expect($valueExpr['rootVar'])->toBe('ticket');
    expect($valueExpr['chain'])->toBe('->status');
});

test('blade php ast analyzer extracts expressions from inline directives and attributes accurately', function () {
    $analyzer = new BladePhpAstAnalyzer();

    $blade = <<<'BLADE'
@if($user->name)
    <div :title="$user->email">
        @unless($user->isAdmin())
            <span>{{ $user->profile?->phone }}</span>
        @endunless
    </div>
@endif
BLADE;

    $expressions = $analyzer->extractAllExpressions($blade);

    // 1. @if($user->name)
    $nameExpr = collect($expressions)->firstWhere('name', 'name');
    expect($nameExpr)->not->toBeNull();
    expect($nameExpr['rootVar'])->toBe('user');
    expect($nameExpr['startLine'])->toBe(0);

    // Exact cursor hit inside @if($user->name) on line 0, char 11
    $hitName = $analyzer->findExpressionAtPosition($blade, 0, 11);
    expect($hitName)->not->toBeNull();
    expect($hitName['name'])->toBe('name');

    // 2. Bound attribute :title="$user->email" on line 1
    $emailExpr = collect($expressions)->firstWhere('name', 'email');
    expect($emailExpr)->not->toBeNull();
    expect($emailExpr['rootVar'])->toBe('user');
    expect($emailExpr['startLine'])->toBe(1);

    // Exact cursor hit inside :title="$user->email" on line 1, char 24
    $hitEmail = $analyzer->findExpressionAtPosition($blade, 1, 24);
    expect($hitEmail)->not->toBeNull();
    expect($hitEmail['name'])->toBe('email');

    // 3. @unless($user->isAdmin()) on line 2
    $adminExpr = collect($expressions)->firstWhere('name', 'isAdmin');
    expect($adminExpr)->not->toBeNull();
    expect($adminExpr['rootVar'])->toBe('user');
    expect($adminExpr['startLine'])->toBe(2);

    // 4. {{ $user->profile?->phone }} on line 3
    $phoneExpr = collect($expressions)->firstWhere('name', 'phone');
    expect($phoneExpr)->not->toBeNull();
    expect($phoneExpr['rootVar'])->toBe('user');
    expect($phoneExpr['chain'])->toBe('->profile');
    expect($phoneExpr['startLine'])->toBe(3);
});
