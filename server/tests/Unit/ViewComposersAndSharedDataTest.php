<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Analysis\BladeScopeResolver;
use App\Lsp\Analysis\PhpAstViewAnalyzer;
use App\Lsp\Data\ViewVariables;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use Mockery;

test('php ast view analyzer extracts View::share and view()->share variables for all views', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::share('appName', 'Fleepness Backend');
        View::share([
            'currentUser' => User::first(),
            'siteConfig' => ['env' => 'production', 'debug' => false],
        ]);

        view()->share('apiVersion', 'v1');
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Providers/AppServiceProvider.php');

    expect($result)->toHaveKey('*');
    $shared = $result['*']['variables'];

    expect($shared)->toHaveKeys(['appName', 'currentUser', 'siteConfig', 'apiVersion']);
    expect($shared['appName']['type'])->toBe('string');
    expect($shared['currentUser']['type'])->toBe('\\App\\Models\\User');
    expect($shared['apiVersion']['type'])->toBe('string');
});

test('php ast view analyzer extracts closure-based View::composer and wildcard views', function () {
    $analyzer = new PhpAstViewAnalyzer();

    $code = <<<'PHP'
<?php

namespace App\Providers;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Single view composer
        View::composer('dashboard', function ($view) {
            $posts = Post::all();
            $view->with('recentPosts', $posts);
        });

        // Multi-view composer with array data
        View::composer(['profile.show', 'profile.edit'], function ($view) {
            $view->with([
                'user' => User::find(1),
                'avatarUrl' => 'https://example.com/avatar.png',
            ]);
        });

        // Wildcard view composer
        View::composer('admin.*', function ($view) {
            $view->with('adminRole', 'superadmin');
        });
    }
}
PHP;

    $result = $analyzer->analyze($code, 'app/Providers/AppServiceProvider.php');

    expect($result)->toHaveKey('dashboard');
    expect($result['dashboard']['variables'])->toHaveKey('recentPosts');
    expect($result['dashboard']['variables']['recentPosts']['type'])->toContain('Collection');

    expect($result)->toHaveKey('profile.show');
    expect($result['profile.show']['variables'])->toHaveKeys(['user', 'avatarUrl']);

    expect($result)->toHaveKey('profile.edit');
    expect($result['profile.edit']['variables'])->toHaveKeys(['user', 'avatarUrl']);

    expect($result)->toHaveKey('admin.*');
    expect($result['admin.*']['variables'])->toHaveKey('adminRole');
});

test('php ast view analyzer extracts class-based View::composer and resolves bindings', function () {
    $analyzer = new PhpAstViewAnalyzer();
    $composerBindings = [];
    $composerClasses = [];

    $providerCode = <<<'PHP'
<?php

namespace App\Providers;

use App\View\Composers\ProfileComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('user.profile', ProfileComposer::class);
    }
}
PHP;

    $composerCode = <<<'PHP'
<?php

namespace App\View\Composers;

use App\Models\User;
use Illuminate\View\View;

class ProfileComposer
{
    public function compose(View $view): void
    {
        $view->with('userCount', User::count());
        $view->with('activeUsers', User::all());
    }
}
PHP;

    // Scan provider first, then composer
    $providerViews = $analyzer->analyze($providerCode, 'app/Providers/AppServiceProvider.php', $composerBindings, $composerClasses);
    $composerViews = $analyzer->analyze($composerCode, 'app/View/Composers/ProfileComposer.php', $composerBindings, $composerClasses);

    expect($composerBindings)->toHaveKey('App\View\Composers\ProfileComposer');
    expect($composerBindings['App\View\Composers\ProfileComposer'])->toContain('user.profile');

    expect($composerClasses)->toHaveKey('App\View\Composers\ProfileComposer');
    expect($composerClasses['App\View\Composers\ProfileComposer'])->toHaveKeys(['userCount', 'activeUsers']);

    // When composer class is parsed, it immediately populates bound target views
    expect($composerViews)->toHaveKey('user.profile');
    expect($composerViews['user.profile']['variables'])->toHaveKeys(['userCount', 'activeUsers']);
});

test('blade scope resolver provides View::share and wildcard composer variables to views', function () {
    $tempDir = sys_get_temp_dir() . '/blade_view_composers_test_' . uniqid();
    @mkdir($tempDir, 0777, true);

    $viewVarsData = [
        'views' => [
            '*' => [
                'key' => '*',
                'variables' => [
                    'appName' => [
                        'name' => 'appName',
                        'type' => 'string',
                        'origin' => 'View::share()',
                    ],
                    'globalUser' => [
                        'name' => 'globalUser',
                        'type' => '\App\Models\User',
                        'origin' => 'View::share()',
                    ],
                ],
                'sources' => ['app/Providers/AppServiceProvider.php'],
            ],
            'admin.*' => [
                'key' => 'admin.*',
                'variables' => [
                    'adminSidebar' => [
                        'name' => 'adminSidebar',
                        'type' => 'array',
                        'origin' => 'View::composer()',
                    ],
                ],
                'sources' => ['app/Providers/AppServiceProvider.php'],
            ],
            'admin.dashboard' => [
                'key' => 'admin.dashboard',
                'variables' => [
                    'totalSales' => [
                        'name' => 'totalSales',
                        'type' => 'int',
                        'origin' => 'Controller Data',
                    ],
                ],
                'sources' => ['app/Http/Controllers/AdminController.php'],
            ],
        ],
        'globals' => ViewVariables::defaultGlobals(),
    ];

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn($viewVarsData);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));

    $project = new Project(FileUri::of($tempDir), [], $mockIndex, new ScriptRunner($tempDir, ['php']));
    $resolver = new BladeScopeResolver($project);

    // Test a normal view (e.g. resources/views/welcome.blade.php)
    $welcomeDoc = new Document('file://' . $tempDir . '/resources/views/welcome.blade.php', '<h1>Welcome</h1>');
    $welcomeScope = $resolver->resolve($welcomeDoc, 'welcome');

    expect($welcomeScope->variables)->toHaveKeys(['appName', 'globalUser', '__env', 'errors', 'app', 'request']);
    expect((string) $welcomeScope->variables['appName']->type)->toBe('string');
    expect((string) $welcomeScope->variables['globalUser']->type)->toBe('\App\Models\User');
    expect($welcomeScope->variables)->not->toHaveKey('adminSidebar');

    // Test an admin view (e.g. resources/views/admin/dashboard.blade.php)
    $adminDoc = new Document('file://' . $tempDir . '/resources/views/admin/dashboard.blade.php', '<div>Admin</div>');
    $adminScope = $resolver->resolve($adminDoc, 'admin.dashboard');

    expect($adminScope->variables)->toHaveKeys(['appName', 'globalUser', 'adminSidebar', 'totalSales']);
    expect((string) $adminScope->variables['adminSidebar']->type)->toBe('array');
    expect((string) $adminScope->variables['totalSales']->type)->toBe('int');

    @unlink($tempDir);
});
