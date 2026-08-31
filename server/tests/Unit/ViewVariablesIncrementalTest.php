<?php

declare(strict_types=1);

use App\Lsp\Data\ViewVariables;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

test('view variables data provider caches AST per file and updates incrementally on file change', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_inc_test_' . uniqid();
    mkdir($tempDir . '/app/Mail', 0777, true);
    mkdir($tempDir . '/resources/views/components', 0777, true);

    $mailFile = $tempDir . '/app/Mail/AlertMail.php';
    $mailCode1 = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

class AlertMail extends Mailable
{
    public string $message;

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
PHP;
    file_put_contents($mailFile, $mailCode1);

    $componentFile = $tempDir . '/resources/views/components/card.blade.php';
    file_put_contents($componentFile, "@props(['title' => 'Default Title'])");

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $uri = FileUri::of($tempDir);
    $scripts = new ScriptRunner($uri->path(), ['php']);
    $project = new Project($uri, [], $mockIndex, $scripts);

    $provider = new ViewVariables($project);

    // Initial Scan
    $data1 = $provider->parse([]);
    expect($data1['views'])->toHaveKeys(['mail.alert', 'components.card']);
    expect($data1['views']['mail.alert']['variables']['message']['type'])->toBe('string');
    expect($data1['views']['components.card']['variables']['title']['type'])->toContain('string');

    // Modify AlertMail with new property and sleep 1s to ensure mtime change
    sleep(1);
    $mailCode2 = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

class AlertMail extends Mailable
{
    public string $message;
    public int $statusCode;

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
PHP;
    file_put_contents($mailFile, $mailCode2);

    // Second scan (Incremental)
    $data2 = $provider->parse([]);
    expect($data2['views']['mail.alert']['variables'])->toHaveKeys(['message', 'statusCode']);
    expect($data2['views']['mail.alert']['variables']['statusCode']['type'])->toBe('int');

    // Verify component file was re-used from cache
    expect($data2['views']['components.card']['variables']['title']['type'])->toContain('string');

    // Clean up
    unlink($mailFile);
    unlink($componentFile);
});
