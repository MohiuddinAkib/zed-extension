<?php

declare(strict_types=1);

use App\Lsp\Analysis\ComponentRegistry;
use App\Lsp\Document;
use App\Lsp\Features\BladeComponents\BladeComponentCompletionProvider;
use App\Lsp\Features\BladeComponents\BladeComponentDocumentMapper;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Semantics\ComponentPropSymbol;
use App\Lsp\Semantics\ComponentSymbol;
use App\Lsp\Semantics\SlotSymbol;
use App\Lsp\Semantics\TypeRef;
use App\Lsp\Support\FileUri;

test('component registry extracts props and slots and completes them in blade templates', function () {
    $tempDir = sys_get_temp_dir() . '/comp_props_' . uniqid();
    mkdir($tempDir . '/resources/views/components', 0777, true);
    mkdir($tempDir . '/app/View/Components', 0777, true);

    // Create an anonymous component with @props
    file_put_contents($tempDir . '/resources/views/components/alert.blade.php', <<<'BLADE'
@props([
    'type' => 'info',
    'dismissible' => false,
    'title' => null,
])

<div {{ $attributes->class(['alert', "alert-{$type}"]) }}>
    {{ $slot }}
</div>
BLADE);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('bladeComponents')->andReturn([
        'components' => [],
        'prefixes' => ['x-'],
    ]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $registry = new ComponentRegistry($project);

    $component = $registry->getComponent('alert');
    expect($component)->not->toBeNull();
    expect($component->name)->toBe('alert');
    expect($component->props)->toHaveKeys(['type', 'dismissible', 'title']);
    expect($component->props['type']->defaultValue)->toBe("'info'");
    expect($component->props['type']->required)->toBeFalse();

    // Test attribute completion on <x-alert 
    $mapper = new BladeComponentDocumentMapper($project);
    $doc = new Document('file://' . $tempDir . '/resources/views/welcome.blade.php', '<x-alert ');
    $completions = $mapper->completions($doc, ['line' => 0, 'character' => 9]);

    $labels = array_column($completions, 'label');
    expect($labels)->toContain('type');
    expect($labels)->toContain('dismissible');
    expect($labels)->toContain('title');

    $typeComp = collect($completions)->firstWhere('label', 'type');
    expect($typeComp['insertTextFormat'])->toBe(2);
    expect($typeComp['insertText'])->toBe('type="$1"');

    // Test slot completion on <x-slot:
    $slotDoc = new Document('file://' . $tempDir . '/resources/views/welcome.blade.php', "<x-alert>\n    <x-slot:");
    $slotCompletions = $mapper->completions($slotDoc, ['line' => 1, 'character' => 12]);
    $slotLabels = array_column($slotCompletions, 'label');
    expect($slotLabels)->toContain('header');
    expect($slotLabels)->toContain('footer');

    // Test hover on attribute
    $hoverDoc = new Document('file://' . $tempDir . '/resources/views/welcome.blade.php', '<x-alert type="warning">');
    $hover = $mapper->hover($hoverDoc, ['line' => 0, 'character' => 10]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('Component Prop:');
    expect($hover['contents']['value'])->toContain('type');

    // Test class component with union, nullable, docblock types
    file_put_contents($tempDir . '/app/View/Components/Modal.php', <<<'PHP'
<?php

namespace App\View\Components;

use App\Models\User;

class Modal
{
    /**
     * @param string|int $size
     */
    public function __construct(
        public ?User $author,
        $size = 'lg',
        public bool $backdrop = true,
    ) {}
}
PHP);

    $classProps = $registry->extractPropsFromPhpFile($tempDir . '/app/View/Components/Modal.php', 'App\\View\\Components\\Modal');
    expect($classProps)->toHaveKeys(['author', 'size', 'backdrop']);
    expect($classProps['author']->type->displayName)->toBe('?\\App\\Models\\User');
    expect($classProps['author']->required)->toBeTrue();
    expect($classProps['size']->type->displayName)->toBe('string|int');
    expect($classProps['size']->defaultValue)->toBe("'lg'");
    expect($classProps['size']->required)->toBeFalse();
    expect($classProps['backdrop']->type->displayName)->toBe('bool');
    expect($classProps['backdrop']->defaultValue)->toBe('true');
});
