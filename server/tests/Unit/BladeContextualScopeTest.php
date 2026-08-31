<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\ScopeOrigin;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VariableSymbol;
use App\Lsp\Support\FileUri;

test('blade scope resolver unwraps generic collection item types and injects loop in foreach', function () {
    $tempDir = sys_get_temp_dir() . '/context_scope_' . uniqid();
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'tickets.index' => [
                'variables' => [
                    'tickets' => [
                        'name' => 'tickets',
                        'type' => '\Illuminate\Support\Collection<\App\Models\Ticket>',
                        'origin' => 'Controller',
                    ],
                ],
                'sources' => ['TicketController.php'],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $resolver = new BladeScopeResolver($project);

    $blade = <<<'BLADE'
@extends('layouts.app')

@section('content')
    @foreach($tickets as $key => $ticket)
        <p>{{ $ticket->title }}</p>
    @endforeach

    @error('email')
        <p>{{ $message }}</p>
    @enderror
@endsection
BLADE;

    $doc = new Document('file://' . $tempDir . '/resources/views/tickets/index.blade.php', $blade);

    // Resolve at line 4 (inside @foreach)
    $scope = $resolver->resolveAtPosition($doc, 4, 15);

    expect($scope->variables)->toHaveKeys(['tickets', 'ticket', 'key', 'loop']);
    expect($scope->variables)->not->toHaveKey('message');

    // Check $ticket unwrapped type
    expect($scope->variables['ticket']->type->displayName)->toBe('\App\Models\Ticket');
    expect($scope->variables['key']->type->displayName)->toBe('int|string');
    expect($scope->variables['loop']->name)->toBe('loop');

    // Resolve at line 8 (inside @error)
    $errorScope = $resolver->resolveAtPosition($doc, 8, 15);
    expect($errorScope->variables)->toHaveKeys(['tickets', 'message']);
    expect($errorScope->variables)->not->toHaveKey('ticket');
    expect($errorScope->variables)->not->toHaveKey('loop');
    expect($errorScope->variables['message']->type->displayName)->toBe('string');

    // Resolve outside both at line 0
    $globalScope = $resolver->resolveAtPosition($doc, 0, 0);
    expect($globalScope->variables)->toHaveKey('tickets');
    expect($globalScope->variables)->not->toHaveKey('ticket');
    expect($globalScope->variables)->not->toHaveKey('message');
});
