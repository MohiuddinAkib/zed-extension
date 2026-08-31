<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeDocumentCompiler;
use App\Lsp\Document;
use App\Lsp\Semantics\ScopeOrigin;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Semantics\VariableSymbol;
use App\Lsp\Semantics\ViewScope;

test('blade document compiler produces valid php document from template and view scope', function () {
    $compiler = new BladeDocumentCompiler();

    $scope = new ViewScope('users.show');
    $scope->addVariable(new VariableSymbol(
        name: 'user',
        type: TypeRef::fromString('\App\Models\User'),
        origin: new ScopeOrigin('Controller', 'UserController.php', 25),
    ));
    $scope->addVariable(new VariableSymbol(
        name: 'tickets',
        type: TypeRef::fromString('\Illuminate\Support\Collection<\App\Models\Ticket>'),
        origin: new ScopeOrigin('Controller', 'UserController.php', 26),
    ));

    $blade = <<<'BLADE'
<div>
    <h1>{{ $user->name }}</h1>

    @if($user->isAdmin())
        <span>Admin Badge</span>
    @endif

    @foreach($tickets as $ticket)
        <x-card :status="$ticket->status->value" />
    @endforeach

    @error('email')
        <p>{{ $message }}</p>
    @enderror

    <script>
        const config = @js($user->settings);
    </script>
</div>
BLADE;

    $doc = new Document('file:///app/resources/views/users/show.blade.php', $blade);
    $virtualDoc = $compiler->compile($doc, $scope);

    expect($virtualDoc->phpCode)->toContain('/** @var \App\Models\User $user */')
        ->toContain('/** @var \Illuminate\Support\Collection<\App\Models\Ticket> $tickets */')
        ->toContain('$user = null;')
        ->toContain('$__blade_echo = ($user->name);')
        ->toContain('if ($user->isAdmin()) {')
        ->toContain('foreach ($tickets as $ticket) {')
        ->toContain('$loop = (object)')
        ->toContain('$__blade_attr = ($ticket->status->value);')
        ->toContain('$__blade_js = ($user->settings);');

    // Verify the compiled PHP code is 100% syntactically valid PHP
    $tokens = token_get_all($virtualDoc->phpCode);
    expect($tokens)->not->toBeEmpty();

    // Verify source mapping
    $sourceMap = $virtualDoc->sourceMap;

    // '$user->name' in Blade is on line 1
    $bladeOffset = strpos($blade, '$user->name');
    $virtualOffset = $sourceMap->bladeToVirtualOffset($bladeOffset);
    expect($virtualOffset)->not->toBeNull();

    $mappedBladeOffset = $sourceMap->virtualToBladeOffset($virtualOffset);
    expect($mappedBladeOffset)->toBe($bladeOffset);
});
