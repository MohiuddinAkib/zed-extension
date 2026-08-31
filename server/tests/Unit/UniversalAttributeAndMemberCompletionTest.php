<?php

declare(strict_types=1);

use App\Lsp\Analysis\AttributeIntelligenceRegistry;
use App\Lsp\Analysis\DriverRegistry;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Features\Attributes\AttributeCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Methods\TextDocumentCompletion;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;

function createUniversalAttributeTestProject(string $tempDir): Project
{
    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('configs')->andReturn([
        'configs' => collect([
            ['name' => 'auth.guards.web', 'file' => $tempDir . '/config/auth.php', 'line' => 10],
            ['name' => 'auth.guards.api', 'file' => $tempDir . '/config/auth.php', 'line' => 15],
            ['name' => 'auth.guards.admin', 'file' => $tempDir . '/config/auth.php', 'line' => 20],
            ['name' => 'cache.stores.redis', 'file' => $tempDir . '/config/cache.php', 'line' => 10],
            ['name' => 'cache.stores.file', 'file' => $tempDir . '/config/cache.php', 'line' => 15],
            ['name' => 'cache.stores.database', 'file' => $tempDir . '/config/cache.php', 'line' => 20],
            ['name' => 'database.connections.mysql', 'file' => $tempDir . '/config/database.php', 'line' => 10],
            ['name' => 'database.connections.pgsql', 'file' => $tempDir . '/config/database.php', 'line' => 15],
            ['name' => 'database.connections.sqlite', 'file' => $tempDir . '/config/database.php', 'line' => 20],
            ['name' => 'filesystems.disks.local', 'file' => $tempDir . '/config/filesystems.php', 'line' => 10],
            ['name' => 'filesystems.disks.public', 'file' => $tempDir . '/config/filesystems.php', 'line' => 15],
            ['name' => 'filesystems.disks.s3', 'file' => $tempDir . '/config/filesystems.php', 'line' => 20],
            ['name' => 'filesystems.disks.scoped', 'file' => $tempDir . '/config/filesystems.php', 'line' => 25],
            ['name' => 'queue.connections.redis', 'file' => $tempDir . '/config/queue.php', 'line' => 10],
            ['name' => 'queue.connections.database', 'file' => $tempDir . '/config/queue.php', 'line' => 15],
            ['name' => 'queue.connections.sync', 'file' => $tempDir . '/config/queue.php', 'line' => 20],
            ['name' => 'mail.mailers.smtp', 'file' => $tempDir . '/config/mail.php', 'line' => 10],
            ['name' => 'mail.mailers.ses', 'file' => $tempDir . '/config/mail.php', 'line' => 15],
            ['name' => 'mail.mailers.postmark', 'file' => $tempDir . '/config/mail.php', 'line' => 20],
            ['name' => 'app.name', 'value' => 'Laravel', 'file' => $tempDir . '/config/app.php', 'line' => 5],
            ['name' => 'app.env', 'value' => 'local', 'file' => $tempDir . '/config/app.php', 'line' => 6],
        ]),
        'paths' => collect([]),
    ]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'dashboard', 'path' => 'resources/views/dashboard.blade.php'],
        ['key' => 'auth.login', 'path' => 'resources/views/auth/login.blade.php'],
    ]));
    $mockIndex->shouldReceive('routes')->andReturn(collect([
        ['name' => 'home', 'uri' => '/', 'action' => 'HomeController@index'],
        ['name' => 'login', 'uri' => '/login', 'action' => 'AuthController@login'],
    ]));
    $mockIndex->shouldReceive('middleware')->andReturn(collect([
        ['name' => 'auth', 'class' => 'App\Http\Middleware\Authenticate'],
        ['name' => 'auth:sanctum', 'class' => 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful'],
        ['name' => 'guest', 'class' => 'App\Http\Middleware\RedirectIfAuthenticated'],
    ]));
    $mockIndex->shouldReceive('auth')->andReturn([
        'policies' => [
            'view' => [['ability' => 'view', 'policy' => 'App\Policies\PostPolicy', 'uri' => $tempDir . '/app/Policies/PostPolicy.php', 'line' => 10]],
            'update' => [['ability' => 'update', 'policy' => 'App\Policies\PostPolicy', 'uri' => $tempDir . '/app/Policies/PostPolicy.php', 'line' => 20]],
        ],
    ]);
    $mockIndex->shouldReceive('models')->andReturn([
        'App\Models\Ticket' => [
            'class' => 'App\Models\Ticket',
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'subject', 'type' => 'string'],
                ['name' => 'status', 'type' => 'string'],
            ],
            'relations' => [
                ['name' => 'user', 'type' => 'BelongsTo', 'related' => 'App\Models\User'],
            ],
        ],
        'App\Models\User' => [
            'class' => 'App\Models\User',
            'attributes' => [
                ['name' => 'id', 'type' => 'int'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);

    return new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
}

test('DriverRegistry discovers configured project drivers and resolves concrete types with contract fallbacks', function () {
    $tempDir = sys_get_temp_dir() . '/driver_test_' . uniqid();
    $project = createUniversalAttributeTestProject($tempDir);
    $registry = new DriverRegistry($project);

    // 1. Auth guards
    $guards = $registry->getDrivers('auth_guards');
    expect($guards)->toHaveKeys(['web', 'api', 'admin', 'sanctum']);
    expect($registry->resolveDriverType('auth_guards', 'web'))->toBe('\Illuminate\Auth\SessionGuard');
    expect($registry->resolveDriverType('auth_guards', 'api'))->toBe('\Illuminate\Auth\TokenGuard');
    expect($registry->resolveDriverType('auth_guards', 'sanctum'))->toBe('\Laravel\Sanctum\Guard');
    expect($registry->resolveDriverType('auth_guards', 'unknown_guard'))->toBe('\Illuminate\Contracts\Auth\Guard');

    // 2. Cache stores
    $stores = $registry->getDrivers('cache_stores');
    expect($stores)->toHaveKeys(['redis', 'file', 'database', 'array']);
    expect($registry->resolveDriverType('cache_stores', 'redis'))->toBe('\Illuminate\Contracts\Cache\Repository');

    // 3. Database connections
    $connections = $registry->getDrivers('database_connections');
    expect($connections)->toHaveKeys(['mysql', 'pgsql', 'sqlite']);
    expect($registry->resolveDriverType('database_connections', 'mysql'))->toBe('\Illuminate\Database\MySqlConnection');
    expect($registry->resolveDriverType('database_connections', 'pgsql'))->toBe('\Illuminate\Database\PostgresConnection');
    expect($registry->resolveDriverType('database_connections', 'sqlite'))->toBe('\Illuminate\Database\SQLiteConnection');
    expect($registry->resolveDriverType('database_connections', 'custom_db'))->toBe('\Illuminate\Database\Connection');

    // 4. Filesystem disks
    $disks = $registry->getDrivers('filesystem_disks');
    expect($disks)->toHaveKeys(['local', 'public', 's3', 'scoped']);
    expect($registry->resolveDriverType('filesystem_disks', 's3'))->toBe('\Illuminate\Filesystem\FilesystemAdapter');
    expect($registry->resolveDriverType('filesystem_disks', 'local'))->toBe('\Illuminate\Filesystem\FilesystemAdapter');

    // 5. Queue connections
    $queues = $registry->getDrivers('queue_connections');
    expect($queues)->toHaveKeys(['redis', 'database', 'sync']);
    expect($registry->resolveDriverType('queue_connections', 'redis'))->toBe('\Illuminate\Queue\RedisQueue');
    expect($registry->resolveDriverType('queue_connections', 'database'))->toBe('\Illuminate\Queue\DatabaseQueue');

    // 6. Mailers
    $mailers = $registry->getDrivers('mailers');
    expect($mailers)->toHaveKeys(['smtp', 'ses', 'postmark']);
    expect($registry->resolveDriverType('mailers', 'smtp'))->toBe('\Illuminate\Mail\Mailer');
});

test('AttributeIntelligenceRegistry defines supported attributes, arguments, domains, and injected types', function () {
    $tempDir = sys_get_temp_dir() . '/attr_registry_test_' . uniqid();
    $project = createUniversalAttributeTestProject($tempDir);
    $registry = new AttributeIntelligenceRegistry($project);

    // Auth attribute
    $authAttr = $registry->findAttribute('Auth');
    expect($authAttr)->not->toBeNull();
    expect($registry->getAttributeArgumentDomain('Auth', 0))->toBe('driver:auth_guards');
    expect($registry->resolveInjectedType('Auth', 'web'))->toBe('\Illuminate\Auth\SessionGuard');

    // Storage attribute
    $storageAttr = $registry->findAttribute('Storage');
    expect($storageAttr)->not->toBeNull();
    expect($registry->getAttributeArgumentDomain('Storage', 0))->toBe('driver:filesystem_disks');
    expect($registry->resolveInjectedType('Storage', 's3'))->toBe('\Illuminate\Filesystem\FilesystemAdapter');

    // Database attribute
    expect($registry->getAttributeArgumentDomain('Database', 0))->toBe('driver:database_connections');
    expect($registry->resolveInjectedType('Database', 'mysql'))->toBe('\Illuminate\Database\MySqlConnection');

    // Cache attribute
    expect($registry->getAttributeArgumentDomain('Cache', 0))->toBe('driver:cache_stores');
    expect($registry->resolveInjectedType('Cache', 'redis'))->toBe('\Illuminate\Contracts\Cache\Repository');

    // Config attribute
    expect($registry->getAttributeArgumentDomain('Config', 0))->toBe('config_keys');
    expect($registry->resolveInjectedType('Config', 'app.name'))->toBe('string');

    // Middleware attribute
    expect($registry->getAttributeArgumentDomain('Middleware', 0))->toBe('middleware');

    // CurrentUser attribute
    expect($registry->getAttributeArgumentDomain('CurrentUser', 0))->toBe('driver:auth_guards');
    expect($registry->resolveInjectedType('CurrentUser', 'web'))->toBe('\App\Models\User');
});

test('AttributeCompletionProvider completes driver, config, route, view, and middleware arguments inside attribute constructors', function () {
    $tempDir = sys_get_temp_dir() . '/attr_comp_test_' . uniqid();
    $project = createUniversalAttributeTestProject($tempDir);
    $provider = new AttributeCompletionProvider($project);

    // 1. #[Storage('s|')]
    $code1 = "<?php #[Storage('s')] class TestService {}";
    $doc1 = new Document('file://' . $tempDir . '/app/Services/TestService.php', $code1);
    $char1 = strrpos($code1, "'s") + strlen("'s");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => $char1]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('s3', 'scoped');
    expect($labels1)->not->toContain('local');

    // 2. #[Auth('w|')]
    $code2 = "<?php class Controller { public function __construct(#[Auth('w')] \$guard) {} }";
    $doc2 = new Document('file://' . $tempDir . '/app/Http/Controllers/Controller.php', $code2);
    $char2 = strrpos($code2, "'w") + strlen("'w");
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => $char2]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('web');
    expect($labels2)->not->toContain('api');

    // 3. #[Database('my|')]
    $code3 = "<?php #[Database('my')] class Repo {}";
    $doc3 = new Document('file://' . $tempDir . '/app/Repo.php', $code3);
    $char3 = strrpos($code3, "'my") + strlen("'my");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => $char3]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('mysql');

    // 4. #[Middleware('au|')]
    $code4 = "<?php #[Middleware('au')] class UserController {}";
    $doc4 = new Document('file://' . $tempDir . '/app/Http/Controllers/UserController.php', $code4);
    $char4 = strrpos($code4, "'au") + strlen("'au");
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => $char4]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('auth', 'auth:sanctum');

    // 5. #[Config('app.|')]
    $code5 = "<?php #[Config('app.')] class AppService {}";
    $doc5 = new Document('file://' . $tempDir . '/app/AppService.php', $code5);
    $char5 = strrpos($code5, "'app.") + strlen("'app.");
    $items5 = $provider->get($doc5, ['line' => 0, 'character' => $char5]);
    $labels5 = array_column($items5, 'label');
    expect($labels5)->toContain('app.name', 'app.env');
});

test('AttributeCompletionProvider covers model and model attribute domains', function () {
    $tempDir = sys_get_temp_dir() . '/attr_domain_test_' . uniqid();
    $project = createUniversalAttributeTestProject($tempDir);
    $provider = new AttributeCompletionProvider($project);

    $code1 = "<?php #[UseEloquentModel('Ticket')] class UsesModel {}";
    $doc1 = new Document('file://' . $tempDir . '/app/UsesModel.php', $code1);
    $char1 = strrpos($code1, "'Ticket") + strlen("'Ticket");
    $modelLabels = array_column($provider->get($doc1, ['line' => 0, 'character' => $char1]), 'label');
    expect($modelLabels)->toContain('\\App\\Models\\Ticket');

    $code2 = "<?php #[Fillable('su')] class Ticket {}";
    $doc2 = new Document('file://' . $tempDir . '/app/Models/Ticket.php', $code2);
    $char2 = strrpos($code2, "'su") + strlen("'su");
    $attributeLabels = array_column($provider->get($doc2, ['line' => 0, 'character' => $char2]), 'label');
    expect($attributeLabels)->toContain('subject');
});

test('Helper and facade driver completion suggests configured drivers', function () {
    $tempDir = sys_get_temp_dir() . '/helper_facade_test_' . uniqid();
    $project = createUniversalAttributeTestProject($tempDir);
    $provider = new AttributeCompletionProvider($project);

    // 1. auth('w|')
    $code1 = "<?php auth('w');";
    $doc1 = new Document('file://' . $tempDir . '/test.php', $code1);
    $char1 = strrpos($code1, "'w") + strlen("'w");
    $items1 = $provider->get($doc1, ['line' => 0, 'character' => $char1]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('web');

    // 2. Auth::guard('w|')
    $code2 = "<?php Auth::guard('w');";
    $doc2 = new Document('file://' . $tempDir . '/test.php', $code2);
    $char2 = strrpos($code2, "'w") + strlen("'w");
    $items2 = $provider->get($doc2, ['line' => 0, 'character' => $char2]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('web');

    // 3. Storage::disk('s|')
    $code3 = "<?php Storage::disk('s');";
    $doc3 = new Document('file://' . $tempDir . '/test.php', $code3);
    $char3 = strrpos($code3, "'s") + strlen("'s");
    $items3 = $provider->get($doc3, ['line' => 0, 'character' => $char3]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('s3', 'scoped');

    // 4. DB::connection('my|')
    $code4 = "<?php DB::connection('my');";
    $doc4 = new Document('file://' . $tempDir . '/test.php', $code4);
    $char4 = strrpos($code4, "'my") + strlen("'my");
    $items4 = $provider->get($doc4, ['line' => 0, 'character' => $char4]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('mysql');

    // 5. Cache::store('r|')
    $code5 = "<?php Cache::store('r');";
    $doc5 = new Document('file://' . $tempDir . '/test.php', $code5);
    $char5 = strrpos($code5, "'r") + strlen("'r");
    $items5 = $provider->get($doc5, ['line' => 0, 'character' => $char5]);
    $labels5 = array_column($items5, 'label');
    expect($labels5)->toContain('redis');
});

test('Chained member completion resolves concrete types after attributes, helpers, and facades', function () {
    $tempDir = sys_get_temp_dir() . '/chained_member_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);
    $project = createUniversalAttributeTestProject($tempDir);
    $memberProvider = new BladeMemberCompletionProvider($project);

    // 1. auth('web')->
    $code1 = "<?php auth('web')->";
    $doc1 = new Document('file://' . $tempDir . '/test.php', $code1);
    $char1 = strrpos($code1, '->') + 2;
    $items1 = $memberProvider->get($doc1, ['line' => 0, 'character' => $char1]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('user', 'check', 'id', 'login', 'logout', 'attempt');

    // 2. Storage::disk('s3')->
    $code2 = "<?php Storage::disk('s3')->";
    $doc2 = new Document('file://' . $tempDir . '/test.php', $code2);
    $char2 = strrpos($code2, '->') + 2;
    $items2 = $memberProvider->get($doc2, ['line' => 0, 'character' => $char2]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('get', 'put', 'delete', 'url', 'exists');

    // 3. DB::connection('mysql')->
    $code3 = "<?php DB::connection('mysql')->";
    $doc3 = new Document('file://' . $tempDir . '/test.php', $code3);
    $char3 = strrpos($code3, '->') + 2;
    $items3 = $memberProvider->get($doc3, ['line' => 0, 'character' => $char3]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('table', 'select', 'statement', 'transaction');

    // 4. Cache::store('redis')->
    $code4 = "<?php Cache::store('redis')->";
    $doc4 = new Document('file://' . $tempDir . '/test.php', $code4);
    $char4 = strrpos($code4, '->') + 2;
    $items4 = $memberProvider->get($doc4, ['line' => 0, 'character' => $char4]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('get', 'put', 'remember', 'forget');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('Error-tolerant member completion works during incomplete syntax in Blade and PHP documents', function () {
    $tempDir = sys_get_temp_dir() . '/error_tolerant_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);
    $project = createUniversalAttributeTestProject($tempDir);
    $memberProvider = new BladeMemberCompletionProvider($project);

    // 1. Incomplete expression in Blade with missing closing delimiter: $ticket->
    $bladeCode1 = '<div>{{ $ticket-> }}</div>';
    $bladeDoc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', $bladeCode1);
    // Add variable to scope via @var docblock
    $bladeDoc1 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "/** @var \App\Models\Ticket \$ticket */\n" . $bladeCode1);
    $char1 = strrpos($bladeCode1, '->') + 2;
    $items1 = $memberProvider->get($bladeDoc1, ['line' => 1, 'character' => $char1]);
    $labels1 = array_column($items1, 'label');
    expect($labels1)->toContain('subject', 'status', 'user');

    // 2. Incomplete expression with partial member prefix: $ticket->su|
    $bladeCode2 = '<div>{{ $ticket->su }}</div>';
    $bladeDoc2 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "/** @var \App\Models\Ticket \$ticket */\n" . $bladeCode2);
    $char2 = strrpos($bladeCode2, '->su') + 4;
    $items2 = $memberProvider->get($bladeDoc2, ['line' => 1, 'character' => $char2]);
    $labels2 = array_column($items2, 'label');
    expect($labels2)->toContain('subject');

    // 3. Nullsafe access: $ticket?->
    $bladeCode3 = '<div>{{ $ticket?-> }}</div>';
    $bladeDoc3 = new Document('file://' . $tempDir . '/resources/views/test.blade.php', "/** @var \App\Models\Ticket \$ticket */\n" . $bladeCode3);
    $char3 = strrpos($bladeCode3, '?->') + 3;
    $items3 = $memberProvider->get($bladeDoc3, ['line' => 1, 'character' => $char3]);
    $labels3 = array_column($items3, 'label');
    expect($labels3)->toContain('subject', 'status', 'user');

    // 4. Incomplete expression in ordinary PHP document: $ticket->
    $phpCode = <<<'PHP'
<?php
/** @var \App\Models\Ticket $ticket */
$ticket->
PHP;
    $phpDoc = new Document('file://' . $tempDir . '/app/Test.php', $phpCode);
    $lines = explode("\n", $phpCode);
    $char4 = strrpos($lines[2], '->') + 2;
    $items4 = $memberProvider->get($phpDoc, ['line' => 2, 'character' => $char4]);
    $labels4 = array_column($items4, 'label');
    expect($labels4)->toContain('subject', 'status', 'user');

    // 5. Incomplete array access in ordinary PHP document: $ticket['
    $phpCode2 = <<<'PHP'
<?php
/** @var \App\Models\Ticket $ticket */
$ticket['
PHP;
    $phpDoc2 = new Document('file://' . $tempDir . '/app/Test.php', $phpCode2);
    $lines2 = explode("\n", $phpCode2);
    $char5 = strrpos($lines2[2], "['") + 2;
    $items5 = $memberProvider->get($phpDoc2, ['line' => 2, 'character' => $char5]);
    $labels5 = array_column($items5, 'label');
    expect($labels5)->toContain('subject', 'status');

    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});

test('Blade member completion suggests conventional Eloquent id when model index omits it', function () {
    $tempDir = sys_get_temp_dir() . '/livewire_id_fallback_' . uniqid();
    @mkdir($tempDir . '/resources/views/livewire', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('configs')->andReturn(['configs' => collect([]), 'paths' => collect([])]);
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));
    $mockIndex->shouldReceive('models')->andReturn([
        'App\Models\Ticket' => [
            'class' => 'App\Models\Ticket',
            'attributes' => [
                ['name' => 'subject', 'type' => 'string'],
                ['name' => 'status', 'type' => 'string'],
            ],
            'relations' => [],
        ],
    ]);
    $mockIndex->shouldReceive('bladeComponents')->andReturn(['components' => [], 'prefixes' => ['x-']]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeMemberCompletionProvider($project);

    $code = "/** @var \\App\\Models\\Ticket \$ticket */\n<div>{{ \$ticket->i }}</div>";
    $doc = new Document('file://' . $tempDir . '/resources/views/livewire/support-ticket-chat-stream.blade.php', $code);
    $lines = explode("\n", $code);
    $char = strrpos($lines[1], '->i') + 3;
    $items = $provider->get($doc, ['line' => 1, 'character' => $char]);
    $idItem = collect($items)->firstWhere('label', 'id');

    expect($idItem)->not->toBeNull();
    expect($idItem['labelDetails']['description'] ?? $idItem['detail'] ?? '')->toContain('int|string');
});

test('protocol level TextDocumentCompletion handles simultaneous syntax diagnostics and completion results', function () {
    $tempDir = sys_get_temp_dir() . '/proto_error_test_' . uniqid();
    @mkdir($tempDir . '/resources/views', 0777, true);

    $project = createUniversalAttributeTestProject($tempDir);
    $container = new Container();
    $container->instance(Project::class, $project);

    $docUri = 'file://' . $tempDir . '/resources/views/test.blade.php';
    $docManager = new DocumentManager();
    $featureRegistry = new FeatureRegistry($container);
    $featureRegistry->completions = [
        BladeMemberCompletionProvider::class,
        AttributeCompletionProvider::class,
    ];

    $handler = new TextDocumentCompletion($docManager, $featureRegistry, $project);

    // Document with incomplete syntax on line 1: $ticket->
    $code = "/** @var \\App\Models\\Ticket \$ticket */\n<div>{{ \$ticket-> }}</div>";
    $lines = explode("\n", $code);
    $char = strrpos($lines[1], '->') + 2;
    $docManager->open($docUri, $code);

    $req = new JsonRpcRequest(1, 'textDocument/completion', [
        'textDocument' => ['uri' => $docUri],
        'position' => ['line' => 1, 'character' => $char],
    ]);

    $resp = $handler->handle($req);
    $res = $resp->toArray()['result'] ?? [];
    $labels = array_column($res, 'label');
    expect($labels)->toContain('subject', 'status', 'user');

    @unlink($tempDir . '/resources/views/test.blade.php');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir);
});
