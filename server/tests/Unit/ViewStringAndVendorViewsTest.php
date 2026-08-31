<?php

declare(strict_types=1);

use App\Lsp\Analysis\PhpAstViewAnalyzer;
use App\Lsp\Document;
use App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeVariableHoverProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Illuminate\Container\Container;

test('php ast view analyzer extracts views and variables from @param view-string custom functions', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Custom view helper function.
 *
 * @param view-string $template
 * @param array<string, mixed> $data
 */
function renderPdf(string $template, array $data = [])
{
    // ...
}

class InvoiceService
{
    /**
     * Custom view helper method.
     *
     * @param view-string $view
     * @param array<string, mixed> $context
     */
    public function renderModal(string $view, array $context = [])
    {
        // ...
    }

    public function generate(Invoice $invoice)
    {
        renderPdf('invoices.pdf', [
            'invoice' => $invoice,
            'amount' => 100.50,
        ]);

        $this->renderModal('courier::invoice-modal', [
            'invoice' => $invoice,
            'title' => 'Invoice Modal',
        ]);
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Services/InvoiceService.php');

    expect($result)->toHaveKeys(['invoices.pdf', 'courier::invoice-modal']);

    $pdfVars = $result['invoices.pdf']['variables'];
    expect($pdfVars)->toHaveKeys(['invoice', 'amount']);
    expect($pdfVars['invoice']['type'])->toBe('\\App\\Models\\Invoice');
    expect($pdfVars['amount']['type'])->toBe('float');

    $modalVars = $result['courier::invoice-modal']['variables'];
    expect($modalVars)->toHaveKeys(['invoice', 'title']);
    expect($modalVars['invoice']['type'])->toBe('\\App\\Models\\Invoice');
    expect($modalVars['title']['type'])->toBe('string');
});

test('blade variable completion provider resolves overridden vendor view keys and provides intellisense', function () {
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'courier::emails.order' => [
                'key' => 'courier::emails.order',
                'variables' => [
                    'order' => [
                        'name' => 'order',
                        'type' => '\\App\\Models\\Order',
                        'origin' => 'Controller',
                        'source' => 'app/Http/Controllers/OrderController.php',
                    ],
                ],
                'sources' => ['app/Http/Controllers/OrderController.php'],
            ],
        ],
        'globals' => [
            ['name' => 'errors', 'type' => '\\Illuminate\\Support\\ViewErrorBag', 'origin' => 'Global'],
        ],
    ]);

    $mockIndex->shouldReceive('views')->andReturn(collect([
        [
            'key' => 'courier::emails.order',
            'path' => 'resources/views/vendor/courier/emails/order.blade.php',
            'isVendor' => true,
        ],
    ]));

    $container = new Container();
    $uri = FileUri::of('/path/to/laravel-app');
    $scripts = new ScriptRunner($uri->path(), ['php']);
    $project = new Project($uri, [], $mockIndex, $scripts);

    $provider = new BladeVariableCompletionProvider($project);

    $bladeContent = "<h1>Order Shipped</h1>\n<div>\n    <p>{{ \$\n</div>";
    $document = new Document('file:///path/to/laravel-app/resources/views/vendor/courier/emails/order.blade.php', $bladeContent);

    $completions = $provider->get($document, ['line' => 2, 'character' => 11]);

    $labels = collect($completions)->pluck('label')->all();
    expect($labels)->toContain('$order')
        ->toContain('$errors');

    $orderCompletion = collect($completions)->firstWhere('label', '$order');
    expect($orderCompletion['detail'])->toBe('\\App\\Models\\Order');
});

test('blade variable hover provider displays correct hover for vendor views', function () {
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'courier::emails.order' => [
                'key' => 'courier::emails.order',
                'variables' => [
                    'order' => [
                        'name' => 'order',
                        'type' => '\\App\\Models\\Order',
                        'origin' => 'Controller',
                        'source' => 'app/Http/Controllers/OrderController.php',
                    ],
                ],
                'sources' => ['app/Http/Controllers/OrderController.php'],
            ],
        ],
        'globals' => [],
    ]);

    $mockIndex->shouldReceive('views')->andReturn(collect([]));

    $container = new Container();
    $uri = FileUri::of('/path/to/laravel-app');
    $scripts = new ScriptRunner($uri->path(), ['php']);
    $project = new Project($uri, [], $mockIndex, $scripts);

    $hoverProvider = new BladeVariableHoverProvider($project);

    $bladeContent = "<h1>{{ \$order->total }}</h1>";
    $document = new Document('file:///path/to/laravel-app/resources/views/vendor/courier/emails/order.blade.php', $bladeContent);

    $hover = $hoverProvider->get($document, ['line' => 0, 'character' => 10]);
    expect($hover)->not->toBeNull();
    expect($hover['contents']['value'])->toContain('$order')
        ->toContain('\\App\\Models\\Order')
        ->toContain('OrderController.php');
});
