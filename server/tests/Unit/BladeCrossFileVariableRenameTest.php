<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\Features\BladeVariables\BladeVariableRenameProvider;
use App\Lsp\Methods\TextDocumentPrepareRename;
use App\Lsp\Methods\TextDocumentRename;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Mockery;

test('cross-file rename from Blade template updates controller compact, array data, and with calls', function () {
    $tempDir = sys_get_temp_dir() . '/cross_file_rename_ctrl_' . uniqid();
    @mkdir($tempDir . '/app/Http/Controllers', 0777, true);
    @mkdir($tempDir . '/resources/views/users', 0777, true);

    $controllerCode = <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController
{
    public function show(User $user)
    {
        $view1 = view('users.profile', compact('user'));
        $view2 = view('users.profile', [
            'user' => $user,
            'title' => 'User Profile',
        ]);
        $view3 = view('users.profile')->with('user', $user);
        return $view1;
    }
}
PHP;

    $bladeCode = <<<'BLADE'
<div class="profile">
    <h1>{{ $user->name }}</h1>
    <p>{{ $user->email }}</p>
    @foreach($user->posts as $post)
        <div>{{ $post->title }}</div>
    @endforeach
</div>
BLADE;

    $controllerPath = $tempDir . '/app/Http/Controllers/UserController.php';
    $bladePath = $tempDir . '/resources/views/users/profile.blade.php';

    file_put_contents($controllerPath, $controllerCode);
    file_put_contents($bladePath, $bladeCode);

    $viewVarsData = [
        'views' => [
            'users.profile' => [
                'key' => 'users.profile',
                'variables' => [
                    'user' => [
                        'name' => 'user',
                        'type' => '\\App\\Models\\User',
                        'origin' => 'compact()',
                        'source' => 'app/Http/Controllers/UserController.php',
                    ],
                ],
                'sources' => ['app/Http/Controllers/UserController.php'],
            ],
        ],
        'globals' => [],
    ];

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn($viewVarsData);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'users.profile', 'path' => 'resources/views/users/profile.blade.php'],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    $bladeDoc = new Document((string) FileUri::fromPath($bladePath), $bladeCode);

    // 1. prepareRename on $user in Blade
    $prepare = $provider->prepareRename($bladeDoc, ['line' => 1, 'character' => 12]);
    expect($prepare)->not->toBeNull();
    expect($prepare['placeholder'])->toBe('user');

    // 2. rename $user to $customer from Blade
    $result = $provider->rename($bladeDoc, ['line' => 1, 'character' => 12], 'customer');
    expect($result)->not->toBeNull();
    expect($result)->toHaveKey('changes');

    $changes = $result['changes'];
    $bladeUri = (string) FileUri::fromPath($bladePath);
    $controllerUri = (string) FileUri::fromPath($controllerPath);

    expect($changes)->toHaveKey($bladeUri);
    expect($changes)->toHaveKey($controllerUri);

    // Blade edits: $user in h1, p, and @foreach source (3 occurrences)
    $bladeEdits = $changes[$bladeUri];
    expect($bladeEdits)->toHaveCount(3);
    foreach ($bladeEdits as $edit) {
        expect($edit['newText'])->toBe('customer');
    }

    // Controller edits: compact('user'), 'user' => $user, and ->with('user', ...)
    $controllerEdits = $changes[$controllerUri];
    expect($controllerEdits)->toHaveCount(3);
    foreach ($controllerEdits as $edit) {
        expect($edit['newText'])->toBe('customer');
    }

    @unlink($controllerPath);
    @unlink($bladePath);
    @rmdir($tempDir . '/resources/views/users');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app/Http/Controllers');
    @rmdir($tempDir . '/app/Http');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('cross-file rename from Blade template updates View::share and View::composer sources', function () {
    $tempDir = sys_get_temp_dir() . '/cross_file_rename_share_' . uniqid();
    @mkdir($tempDir . '/app/Providers', 0777, true);
    @mkdir($tempDir . '/resources/views', 0777, true);

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::share('siteBrand', 'My Super App');
        View::composer('dashboard', function ($view) {
            $view->with('recentActivity', []);
        });
    }
}
PHP;

    $bladeCode = <<<'BLADE'
<header>
    <h1>{{ $siteBrand }}</h1>
    <nav>{{ $siteBrand }}</nav>
</header>
BLADE;

    $providerPath = $tempDir . '/app/Providers/AppServiceProvider.php';
    $bladePath = $tempDir . '/resources/views/welcome.blade.php';

    file_put_contents($providerPath, $providerCode);
    file_put_contents($bladePath, $bladeCode);

    $viewVarsData = [
        'views' => [
            '*' => [
                'key' => '*',
                'variables' => [
                    'siteBrand' => [
                        'name' => 'siteBrand',
                        'type' => 'string',
                        'origin' => 'View::share()',
                        'source' => 'app/Providers/AppServiceProvider.php',
                    ],
                ],
                'sources' => ['app/Providers/AppServiceProvider.php'],
            ],
            'dashboard' => [
                'key' => 'dashboard',
                'variables' => [
                    'recentActivity' => [
                        'name' => 'recentActivity',
                        'type' => 'array',
                        'origin' => 'View::composer()',
                        'source' => 'app/Providers/AppServiceProvider.php',
                    ],
                ],
                'sources' => ['app/Providers/AppServiceProvider.php'],
            ],
        ],
        'globals' => [
            [
                'name' => 'siteBrand',
                'type' => 'string',
                'source' => 'app/Providers/AppServiceProvider.php',
            ],
        ],
    ];

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn($viewVarsData);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'welcome', 'path' => 'resources/views/welcome.blade.php'],
    ]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    $bladeDoc = new Document((string) FileUri::fromPath($bladePath), $bladeCode);

    // Rename $siteBrand to $companyName
    $result = $provider->rename($bladeDoc, ['line' => 1, 'character' => 12], 'companyName');
    expect($result)->not->toBeNull();

    $changes = $result['changes'];
    $bladeUri = FileUri::fromPath($bladePath)->toString();
    $providerUri = FileUri::fromPath($providerPath)->toString();

    expect($changes)->toHaveKey($bladeUri);
    expect($changes)->toHaveKey($providerUri);

    expect($changes[$bladeUri])->toHaveCount(2);
    expect($changes[$providerUri])->toHaveCount(1);
    expect($changes[$providerUri][0]['newText'])->toBe('companyName');

    @unlink($providerPath);
    @unlink($bladePath);
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app/Providers');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('cross-file rename from PHP Controller updates both Controller and Blade template', function () {
    $tempDir = sys_get_temp_dir() . '/cross_file_rename_from_php_' . uniqid();
    @mkdir($tempDir . '/app/Http/Controllers', 0777, true);
    @mkdir($tempDir . '/resources/views/admin', 0777, true);

    $controllerCode = <<<'PHP'
<?php

namespace App\Http\Controllers;

class AdminController
{
    public function index()
    {
        $stats = ['visits' => 100];
        return view('admin.dashboard', compact('stats'));
    }
}
PHP;

    $bladeCode = <<<'BLADE'
<div>
    <h1>Admin Panel</h1>
    <p>Total visits: {{ $stats['visits'] }}</p>
</div>
BLADE;

    $controllerPath = $tempDir . '/app/Http/Controllers/AdminController.php';
    $bladePath = $tempDir . '/resources/views/admin/dashboard.blade.php';

    file_put_contents($controllerPath, $controllerCode);
    file_put_contents($bladePath, $bladeCode);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'admin.dashboard', 'path' => 'resources/views/admin/dashboard.blade.php'],
    ]));
    $mockIndex->shouldReceive('viewVariables')->andReturn(['views' => [], 'globals' => []]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $provider = new BladeVariableRenameProvider($project);

    $ctrlDoc = new Document((string) FileUri::fromPath($controllerPath), $controllerCode);

    // 1. prepareRename on 'stats' in compact('stats') in Controller (line 9, col 48)
    $prepare = $provider->prepareRename($ctrlDoc, ['line' => 9, 'character' => 48]);
    expect($prepare)->not->toBeNull();
    expect($prepare['placeholder'])->toBe('stats');

    // 2. rename stats -> metrics from Controller
    $result = $provider->rename($ctrlDoc, ['line' => 9, 'character' => 48], 'metrics');
    expect($result)->not->toBeNull();

    $changes = $result['changes'];
    $ctrlUri = FileUri::fromPath($controllerPath)->toString();
    $bladeUri = FileUri::fromPath($bladePath)->toString();

    expect($changes)->toHaveKey($ctrlUri);
    expect($changes)->toHaveKey($bladeUri);

    expect($changes[$ctrlUri])->toHaveCount(1);
    expect($changes[$ctrlUri][0]['newText'])->toBe('metrics');

    expect($changes[$bladeUri])->toHaveCount(1);
    expect($changes[$bladeUri][0]['newText'])->toBe('metrics');

    @unlink($controllerPath);
    @unlink($bladePath);
    @rmdir($tempDir . '/resources/views/admin');
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app/Http/Controllers');
    @rmdir($tempDir . '/app/Http');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});

test('protocol handlers textDocument/prepareRename and textDocument/rename handle cross-file variable renaming', function () {
    $tempDir = sys_get_temp_dir() . '/rename_protocol_cross_' . uniqid();
    @mkdir($tempDir . '/app/Http/Controllers', 0777, true);
    @mkdir($tempDir . '/resources/views', 0777, true);

    $controllerCode = <<<'PHP'
<?php

namespace App\Http\Controllers;

class HomeController
{
    public function index()
    {
        return view('home', ['heroTitle' => 'Welcome Home']);
    }
}
PHP;

    $bladeCode = <<<'BLADE'
<section class="hero">
    <h1>{{ $heroTitle }}</h1>
</section>
BLADE;

    $controllerPath = $tempDir . '/app/Http/Controllers/HomeController.php';
    $bladePath = $tempDir . '/resources/views/home.blade.php';

    file_put_contents($controllerPath, $controllerCode);
    file_put_contents($bladePath, $bladeCode);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('views')->andReturn(collect([
        ['key' => 'home', 'path' => 'resources/views/home.blade.php'],
    ]));
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'home' => [
                'key' => 'home',
                'variables' => [
                    'heroTitle' => [
                        'name' => 'heroTitle',
                        'type' => 'string',
                        'origin' => 'Array Data',
                        'source' => 'app/Http/Controllers/HomeController.php',
                    ],
                ],
                'sources' => ['app/Http/Controllers/HomeController.php'],
            ],
        ],
        'globals' => [],
    ]);

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));

    $docManager = new DocumentManager();
    $bladeDoc = new Document((string) FileUri::fromPath($bladePath), $bladeCode);
    $ctrlDoc = new Document((string) FileUri::fromPath($controllerPath), $controllerCode);
    $docManager->open($bladeDoc->uri, $bladeDoc->content);
    $docManager->open($ctrlDoc->uri, $ctrlDoc->content);

    $prepareHandler = new TextDocumentPrepareRename($docManager, $project);
    $renameHandler = new TextDocumentRename($docManager, $project);

    // 1. Prepare rename on Blade $heroTitle
    $prepReq = new JsonRpcRequest(1, 'textDocument/prepareRename', [
        'textDocument' => ['uri' => $bladeDoc->uri],
        'position' => ['line' => 1, 'character' => 13],
    ]);
    $prepRes = $prepareHandler->handle($prepReq);
    expect($prepRes->result)->toHaveKey('placeholder');
    expect($prepRes->result['placeholder'])->toBe('heroTitle');

    // 2. Perform Rename on Blade $heroTitle -> $bannerTitle
    $renameReq = new JsonRpcRequest(2, 'textDocument/rename', [
        'textDocument' => ['uri' => $bladeDoc->uri],
        'position' => ['line' => 1, 'character' => 13],
        'newName' => 'bannerTitle',
    ]);
    $renameRes = $renameHandler->handle($renameReq);
    expect($renameRes->result)->toHaveKey('changes');
    expect($renameRes->result['changes'])->toHaveKey($bladeDoc->uri);
    expect($renameRes->result['changes'])->toHaveKey($ctrlDoc->uri);

    @unlink($controllerPath);
    @unlink($bladePath);
    @rmdir($tempDir . '/resources/views');
    @rmdir($tempDir . '/app/Http/Controllers');
    @rmdir($tempDir . '/app/Http');
    @rmdir($tempDir . '/app');
    @rmdir($tempDir);
});
