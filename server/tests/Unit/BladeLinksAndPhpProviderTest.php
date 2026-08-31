<?php

declare(strict_types=1);

use App\Lsp\Document;
use App\Lsp\Features\BladePhp\BladePhpHoverProvider;
use App\Lsp\Features\BladePhp\BladePhpLinkProvider;
use App\Lsp\Features\BladeVariables\BladeVariableLinkProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('blade variable link provider generates definition links with exact line numbers', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_link_test_' . uniqid();
    mkdir($tempDir . '/app/Mail', 0777, true);
    mkdir($tempDir . '/resources/views/mail', 0777, true);

    file_put_contents($tempDir . '/app/Mail/SystemAlertMail.php', "<?php\n\nclass SystemAlertMail {}\n");

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $mockIndex->shouldReceive('viewVariables')->andReturn([
        'views' => [
            'mail.system-alert' => [
                'key' => 'mail.system-alert',
                'variables' => [
                    'correlationId' => [
                        'name' => 'correlationId',
                        'type' => '?string',
                        'origin' => 'Property (SystemAlertMail)',
                        'source' => 'app/Mail/SystemAlertMail.php',
                        'line' => 28,
                    ],
                ],
                'sources' => ['app/Mail/SystemAlertMail.php'],
            ],
        ],
        'globals' => [],
    ]);
    $mockIndex->shouldReceive('views')->andReturn(collect([]));

    $uri = FileUri::of($tempDir);
    $scripts = new ScriptRunner($uri->path(), ['php']);
    $project = new Project($uri, [], $mockIndex, $scripts);

    $provider = new BladeVariableLinkProvider($project);

    $bladeContent = "<p>{{ \$correlationId }}</p>";
    $document = new Document("file://{$tempDir}/resources/views/mail/system-alert.blade.php", $bladeContent);

    $links = $provider->get($document);

    expect($links)->toHaveCount(1);
    expect($links[0]['target'])->toContain('app/Mail/SystemAlertMail.php#L28');
    expect($links[0]['tooltip'])->toContain('Go to definition: app/Mail/SystemAlertMail.php:28');
});

test('blade php provider provides hover and links for php classes and static methods', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_php_test_' . uniqid();
    mkdir($tempDir . '/app/Filament/Pages', 0777, true);

    $classCode = <<<'PHP'
<?php

namespace App\Filament\Pages;

class AdminOverview
{
    /**
     * Get the URL for the dashboard overview.
     */
    public static function getUrl(array $parameters = [], bool $isAbsolute = true): string
    {
        return '/admin';
    }
}
PHP;

    file_put_contents($tempDir . '/app/Filament/Pages/AdminOverview.php', $classCode);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $uri = FileUri::of($tempDir);
    $scripts = new ScriptRunner($uri->path(), ['php']);
    $project = new Project($uri, [], $mockIndex, $scripts);

    $hoverProvider = new BladePhpHoverProvider($project);
    $linkProvider = new BladePhpLinkProvider($project);

    $bladeContent = '<x-mail::button :url="\App\Filament\Pages\AdminOverview::getUrl()">';
    $document = new Document("file://{$tempDir}/resources/views/mail/system-alert.blade.php", $bladeContent);

    // Test Method Hover (character 58 is on 'getUrl')
    $methodHover = $hoverProvider->get($document, ['line' => 0, 'character' => 58]);
    expect($methodHover)->not->toBeNull();
    expect($methodHover['contents']['value'])->toContain('getUrl')
        ->toContain('AdminOverview.php:10');

    // Test Class Hover (character 35 is on 'AdminOverview')
    $classHover = $hoverProvider->get($document, ['line' => 0, 'character' => 35]);
    expect($classHover)->not->toBeNull();
    expect($classHover['contents']['value'])->toContain('class \App\Filament\Pages\AdminOverview')
        ->toContain('AdminOverview.php');

    // Test Links (emits link for class and link for method)
    $links = $linkProvider->get($document);
    expect($links)->toHaveCount(2);
    expect($links[0]['target'])->toContain('app/Filament/Pages/AdminOverview.php#L1');
    expect($links[1]['target'])->toContain('app/Filament/Pages/AdminOverview.php#L10');
});
