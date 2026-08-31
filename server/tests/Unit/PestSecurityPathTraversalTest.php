<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Watchers\PestHelperWatcher;
use Mockery;

test('PestHelperWatcher rejects malicious path traversal in pestHelperFilePath', function () {
    $tempDir = sys_get_temp_dir() . '/laravel_test_pest_sec_' . uniqid();
    @mkdir($tempDir . '/storage/framework/testing', 0777, true);

    $mockIndex = Mockery::mock(ProjectIndex::class);
    $scripts = new ScriptRunner($tempDir, ['php']);

    // Pass malicious traversal path in initialization options
    $project = new Project(
        FileUri::of($tempDir),
        ['pestHelperFilePath' => '../../../../etc/malicious_pest.php'],
        $mockIndex,
        $scripts
    );

    $watcher = new class($project) extends PestHelperWatcher {
        public function getHelperFilePath(): string
        {
            return $this->helperFilePath();
        }
    };

    $resolvedPath = $watcher->getHelperFilePath();

    // Must be confined within the workspace root
    expect(str_starts_with($resolvedPath, $tempDir))->toBeTrue();
    expect($resolvedPath)->not->toContain('etc/malicious_pest.php');
    expect($resolvedPath)->toBe($tempDir . '/storage/framework/testing/_pest.php');

    @unlink($tempDir);
});
