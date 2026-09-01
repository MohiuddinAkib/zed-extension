# Blade Local Variable Renaming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable and polish Blade local variable renaming in the Laravel Zed extension language server with robust directive scope and shadowing resolution.

**Architecture:** Advertise `renameProvider` in `Initialize.php`, guard target extraction in `prepareRename()`, and build a directive scope hierarchy in `rename()` to safely rename variables without touching shadowed variables, reserved framework globals, comments, or non-PHP tokens.

**Tech Stack:** PHP 8.2+, Laravel Zero, Pest, `stillat/blade-parser`, `nikic/php-parser`.

## Global Constraints

- Do not execute project code at runtime during rename.
- Exclude framework reserved variables: `$loop`, `$errors`, `$__env`, `$this`, `$app`.
- Reject non-PHP regions: HTML tags/text, `<style>`, `<script>`, Blade comments `{{-- ... --}}`, PHP comments.
- UTF-16 character offsets for LSP ranges.

---

### Task 1: Advertise Rename Capabilities in Server Initialize

**Files:**
- Modify: `server/app/Lsp/Methods/Initialize.php`
- Test: `server/tests/Unit/BladeRenameSafetyTest.php`

**Interfaces:**
- Produces: `capabilities.renameProvider = ['prepareProvider' => true]` in `Initialize::handle()` JSON-RPC response.

- [ ] **Step 1: Write test verifying Initialize response contains renameProvider**

```php
test('initialize response advertises renameProvider with prepareProvider', function () {
    $container = new \Illuminate\Container\Container();
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
    $init = new \App\Lsp\Methods\Initialize($container, $logger);

    $tempDir = sys_get_temp_dir() . '/init_rename_' . uniqid();
    @mkdir($tempDir, 0777, true);

    $request = new \App\Lsp\JsonRpcRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'rootUri' => 'file://' . $tempDir,
            'capabilities' => [],
        ],
    ]);

    $response = $init->handle($request);
    $result = $response->toArray()['result'] ?? [];

    expect($result['capabilities'])->toHaveKey('renameProvider');
    expect($result['capabilities']['renameProvider'])->toBe(['prepareProvider' => true]);

    @rmdir($tempDir);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/BladeRenameSafetyTest.php --filter="initialize response advertises renameProvider"`
Expected: FAIL with `renameProvider` being `false`.

- [ ] **Step 3: Update `server/app/Lsp/Methods/Initialize.php`**

Change line 86 of `server/app/Lsp/Methods/Initialize.php`:
```php
'renameProvider' => [
    'prepareProvider' => true,
],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/BladeRenameSafetyTest.php --filter="initialize response advertises renameProvider"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/Methods/Initialize.php server/tests/Unit/BladeRenameSafetyTest.php
git commit -m "feat(lsp): enable renameProvider with prepareProvider in initialize capabilities"
```

---

### Task 2: Implement Token Extraction, Validation & Target Guarding in `prepareRename`

**Files:**
- Modify: `server/app/Lsp/Features/BladeVariables/BladeVariableRenameProvider.php`
- Test: `server/tests/Unit/BladeRenameSafetyTest.php`

**Interfaces:**
- Produces: `prepareRename(Document $document, array $position): ?array` returning `{ range: Range, placeholder: string }` or `null`.

- [ ] **Step 1: Write failing tests for `prepareRename`**

Add tests covering:
- Valid extraction in `{{ $user }}`, `@if($user)`, `:user="$user"`, and `@php $user = 1; @endphp`.
- Rejections for `$loop`, `$errors`, `$__env`, `$this`, `$app`.
- Rejections for Blade comments `{{-- $user --}}`, PHP comments, JavaScript strings, HTML tags/text, method calls `$user->run()`, class names `User::class`.

- [ ] **Step 2: Run tests to verify failure**

Run: `./vendor/bin/pest tests/Unit/BladeRenameSafetyTest.php`
Expected: FAIL on reserved variable rejections and comment/script checks.

- [ ] **Step 3: Implement validation and guards in `BladeVariableRenameProvider::prepareRename`**

1. Extract variable token under position using byte-to-UTF16 mapping.
2. Check if variable is in reserved list: `['loop', 'errors', '__env', 'this', 'app']` -> return `null`.
3. Verify that the variable token at position belongs to a valid extracted AST expression from `BladePhpAstAnalyzer::extractAllExpressions()`.
4. If inside a comment or non-PHP region -> return `null`.
5. Return formatted range and placeholder string.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/BladeRenameSafetyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/Features/BladeVariables/BladeVariableRenameProvider.php server/tests/Unit/BladeRenameSafetyTest.php
git commit -m "feat(blade): guard and validate prepareRename targets against comments, reserved vars, and non-PHP tokens"
```

---

### Task 3: Implement Directive Scope Hierarchy & Lexical Shadow Resolution in `rename`

**Files:**
- Modify: `server/app/Lsp/Features/BladeVariables/BladeVariableRenameProvider.php`
- Test: `server/tests/Unit/BladeRenameSafetyTest.php`

**Interfaces:**
- Produces: `rename(Document $document, array $position, string $newName): ?array` returning `['changes' => [$uri => $edits]]` or `null`.

- [ ] **Step 1: Write failing tests for scope & shadow resolution**

Add tests:
- Renaming template variable renames all occurrences in file.
- Renaming loop variable in `@foreach`, `@forelse`, `@for`, `@while` stays within loop.
- Nested loop shadowing:
  - Renaming outer `$item` excludes inner shadowed `@foreach($item->sub as $item)` block.
  - Renaming inner `$item` updates only the inner block.

- [ ] **Step 2: Run tests to verify failure**

Run: `./vendor/bin/pest tests/Unit/BladeRenameSafetyTest.php`
Expected: FAIL on shadow exclusion and directive block ranges.

- [ ] **Step 3: Implement scope tree and shadow resolution**

1. Parse all loop directive pairs (`@foreach`/`@endforeach`, `@forelse`/`@endforelse`, `@for`/`@endfor`, `@while`/`@endwhile`) to determine block spans and declared iterator variables.
2. If cursor is inside a loop declaring `$targetVarName`, scope is `[startLine, endLine]`.
3. If cursor is at template level, scope is `[0, EOF]`, and collect all child loops that shadow `$targetVarName` into an exclusion list.
4. Filter AST expressions from `BladePhpAstAnalyzer::extractAllExpressions()` where `name === $targetVarName`, within scope, and not within any shadow exclusion span.
5. Build `WorkspaceEdit` response.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/BladeRenameSafetyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add server/app/Lsp/Features/BladeVariables/BladeVariableRenameProvider.php server/tests/Unit/BladeRenameSafetyTest.php
git commit -m "feat(blade): implement directive scope hierarchy and shadow exclusion for variable rename"
```

---

### Task 4: Full Test Suite Verification and Extension Deployment

**Files:**
- Test: All tests in `server/tests/Unit/`
- Build: `./build.sh`

- [ ] **Step 1: Run complete Pest test suite**

Run: `./vendor/bin/pest`
Expected: All 200+ tests PASS (0 failures, 0 errors).

- [ ] **Step 2: Build and deploy extension**

Run: `./build.sh`
Expected: WASM built, custom server copied to `~/Library/Application Support/Zed/extensions/installed/laravel-akib`.

- [ ] **Step 3: Commit any final test additions**

```bash
git commit -m "test: add full integration tests for Blade symbol renaming"
```
