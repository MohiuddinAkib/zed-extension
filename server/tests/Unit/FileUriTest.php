<?php

use App\Lsp\Support\FileUri;

test('relative path for unix paths', function () {
    $uri = FileUri::of('file:///home/runner/work/project');

    expect($uri->relativePath('/home/runner/work/project/app/Models/User.php'))
        ->toBe('app/Models/User.php');
});

test('relative path for windows backslash paths with uppercase drive letter', function () {
    $uri = FileUri::of('file:///d%3A/a/project/project');

    expect($uri->relativePath('D:\\a\\project\\project\\app\\Models\\User.php'))
        ->toBe('app/Models/User.php');
});

test('relative path for windows paths with mixed separators', function () {
    $uri = FileUri::of('file:///d%3A/a/project/project');

    expect($uri->relativePath('d:/a/project\\project\\app/Models/User.php'))
        ->toBe('app/Models/User.php');
});

test('relative path for windows paths with mismatched drive letter case', function () {
    $uri = FileUri::of('file:///D%3A/a/project/project');

    expect($uri->relativePath('d:\\a\\project\\project\\app\\Models\\User.php'))
        ->toBe('app/Models/User.php');
});

test('relative path returns the original path when outside the base path', function () {
    $uri = FileUri::of('file:///home/runner/work/project');

    expect($uri->relativePath('/tmp/other/file.php'))->toBe('/tmp/other/file.php');
});

test('relative path does not match a sibling directory sharing the base path prefix', function () {
    $uri = FileUri::of('file:///home/runner/work/project');

    expect($uri->relativePath('/home/runner/work/project-two/file.php'))
        ->toBe('/home/runner/work/project-two/file.php');
});

test('relative path returns an empty string for the base path itself', function () {
    $uri = FileUri::of('file:///d%3A/a/project/project');

    expect($uri->relativePath('D:\\a\\project\\project'))->toBe('');
});
