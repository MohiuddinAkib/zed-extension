<?php

declare(strict_types=1);

use App\Lsp\Analysis\BladeAstAnalyzer;
use App\Lsp\Analysis\PhpAstViewAnalyzer;

test('php ast view analyzer extracts view calls with parameter types and elqouent models', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Post;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Show user details.
     *
     * @param User $user
     * @param Request $request
     */
    public function show(User $user, Request $request)
    {
        $posts = $user->posts()->paginate(10);
        $title = "User Profile: " . $user->name;

        return view('users.show', [
            'user' => $user,
            'posts' => $posts,
            'title' => $title,
            // Nested closure test:
            'formatter' => fn ($item) => strtoupper($item),
        ]);
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Http/Controllers/UserController.php');

    expect($result)->toHaveKey('users.show');

    $viewData = $result['users.show'];
    expect($viewData['variables'])->toHaveKeys(['user', 'posts', 'title', 'formatter']);

    expect($viewData['variables']['user']['type'])->toBe('\\App\\Models\\User');
    expect($viewData['variables']['posts']['type'])->toContain('Paginator');
    expect($viewData['variables']['title']['type'])->toBe('string');
});

test('php ast view analyzer extracts view calls with compact() and chained with()', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::all();
        $category = 'Electronics';
        $discount = 15.5;
        $inStock = true;

        return view('products.index')
            ->with('products', $products)
            ->with('category', $category)
            ->with(['discount' => $discount, 'inStock' => $inStock]);
    }

    public function detail(Product $product)
    {
        $rating = 4.8;
        return view('products.detail', compact('product', 'rating'));
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Http/Controllers/ProductController.php');

    expect($result)->toHaveKeys(['products.index', 'products.detail']);

    $indexVars = $result['products.index']['variables'];
    expect($indexVars)->toHaveKeys(['products', 'category', 'discount', 'inStock']);
    expect($indexVars['products']['type'])->toContain('Collection');
    expect($indexVars['category']['type'])->toBe('string');
    expect($indexVars['discount']['type'])->toBe('float');
    expect($indexVars['inStock']['type'])->toBe('bool');

    $detailVars = $result['products.detail']['variables'];
    expect($detailVars)->toHaveKeys(['product', 'rating']);
    expect($detailVars['product']['type'])->toBe('\\App\\Models\\Product');
    expect($detailVars['rating']['type'])->toBe('float');
});

test('php ast view analyzer extracts mailable public primitive properties and Content markdown views', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class SystemAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $exceptionClass;
    public string $errorMessage;
    public string $file;
    public int $line;
    public ?string $traceId;
    public ?string $correlationId;
    public string $url;
    public array $device;
    public string $stackTrace;
    public string $environment;

    public function __construct(
        \Throwable $e,
        ?string $traceId,
        ?string $correlationId,
        string $url,
        array $device,
        string $environment = 'production'
    ) {
        $this->exceptionClass = get_class($e);
        $this->errorMessage = $e->getMessage();
        $this->file = $e->getFile();
        $this->line = $e->getLine();
        $this->traceId = $traceId;
        $this->correlationId = $correlationId;
        $this->url = $url;
        $this->device = $device;
        $this->stackTrace = $e->getTraceAsString();
        $this->environment = $environment;
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.system-alert',
        );
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Mail/SystemAlertMail.php');

    expect($result)->toHaveKey('mail.system-alert');

    $vars = $result['mail.system-alert']['variables'];
    expect($vars)->toHaveKeys([
        'exceptionClass',
        'errorMessage',
        'file',
        'line',
        'traceId',
        'correlationId',
        'url',
        'device',
        'stackTrace',
        'environment',
    ]);

    expect($vars['exceptionClass']['type'])->toBe('string');
    expect($vars['errorMessage']['type'])->toBe('string');
    expect($vars['file']['type'])->toBe('string');
    expect($vars['line']['type'])->toBe('int');
    expect($vars['traceId']['type'])->toBe('?string');
    expect($vars['correlationId']['type'])->toBe('?string');
    expect($vars['url']['type'])->toBe('string');
    expect($vars['device']['type'])->toBe('array');
    expect($vars['stackTrace']['type'])->toBe('string');
    expect($vars['environment']['type'])->toBe('string');
});

test('php ast view analyzer extracts class and constructor phpdoc property types', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

/**
 * @property-read "staging"|"production" $environment
 * @property array<string, mixed> $metadata
 */
class SystemAlertMail extends Mailable
{
    public function __construct(
        public string $environment = 'production',
        public array $metadata = []
    ) {}

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.system-alert',
        );
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Mail/SystemAlertMail.php');

    expect($result)->toHaveKey('mail.system-alert');
    $vars = $result['mail.system-alert']['variables'];

    expect($vars['environment']['type'])->toBe('"staging"|"production"');
    expect($vars['metadata']['type'])->toBe('array<string, mixed>');
});

test('php ast view analyzer extracts exact array shape key value types from phpdoc', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

class SystemAlertMail extends Mailable
{
    /**
     * @var array{
     *     ip?: string,
     *     user_agent?: string,
     *     headers?: array<string, string>
     * }
     */
    public array $device;

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.system-alert',
        );
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Mail/SystemAlertMail.php');

    expect($result)->toHaveKey('mail.system-alert');
    $vars = $result['mail.system-alert']['variables'];

    expect($vars['device']['type'])->toBe('array{ip?: string, user_agent?: string, headers?: array<string, string>}');
});

test('blade ast analyzer extracts @props, @inject, and inline @php directives', function () {
    $analyzer = new BladeAstAnalyzer();

    $blade = <<<'BLADE'
@props([
    'title',
    'type' => 'info',
    'count' => 10,
    'active' => true,
    'items' => [],
])

@inject('metrics', 'App\Services\MetricsService')
@php($featuredPost = new \App\Models\Post())

<div class="alert alert-{{ $type }}">
    <h1>{{ $title }}</h1>
</div>
BLADE;

    $vars = $analyzer->extractTemplateVariables($blade);

    expect($vars)->toHaveKeys(['title', 'type', 'count', 'active', 'items', 'metrics', 'featuredPost']);

    expect($vars['title']['type'])->toBe('mixed');
    expect($vars['type']['type'])->toContain('string');
    expect($vars['count']['type'])->toContain('int');
    expect($vars['active']['type'])->toContain('bool');
    expect($vars['items']['type'])->toContain('array');

    expect($vars['metrics']['type'])->toBe('\\App\\Services\\MetricsService');
    expect($vars['metrics']['origin'])->toBe('@inject');

    expect($vars['featuredPost']['type'])->toBe('\\App\\Models\\Post');
    expect($vars['featuredPost']['origin'])->toBe('@php');
});

test('php ast view analyzer extracts views from MailMessage chains and static view properties', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $notificationCode = <<<'PHP'
<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaidNotification extends Notification
{
    public function toMail(object $notifiable): MailMessage
    {
        $amount = 199.99;
        return (new MailMessage)
            ->subject('Invoice Paid')
            ->view('notifications.invoice-paid', [
                'amount' => $amount,
                'notifiable' => $notifiable,
            ]);
    }
}
PHP;

    $res = $analyzer->analyze($notificationCode, 'app/Notifications/InvoicePaidNotification.php');
    expect($res)->toHaveKey('notifications.invoice-paid');
    expect($res['notifications.invoice-paid']['variables'])->toHaveKeys(['amount', 'notifiable']);
    expect($res['notifications.invoice-paid']['variables']['amount']['type'])->toBe('float');

    $filamentPageCode = <<<'PHP'
<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static string $view = 'filament.pages.dashboard';
    public string $statsTitle = 'Overview';
}
PHP;

    $resPage = $analyzer->analyze($filamentPageCode, 'app/Filament/Pages/Dashboard.php');
    expect($resPage)->toHaveKey('filament.pages.dashboard');
    expect($resPage['filament.pages.dashboard']['variables'])->toHaveKey('statsTitle');
    expect($resPage['filament.pages.dashboard']['variables']['statsTitle']['type'])->toBe('string');
});
