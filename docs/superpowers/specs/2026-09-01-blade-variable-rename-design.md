# Blade Local Variable Renaming Specification

## Overview
This specification details the design for enabling and polishing symbol renaming in Blade templates within the Laravel Zed extension language server (`laravel-lsp`).

It enables IDE-grade symbol renaming (`textDocument/prepareRename` and `textDocument/rename`) for PHP variables in Blade files (`.blade.php`), supporting template variables, directive loops (`@foreach`, `@forelse`, `@for`, `@while`), bound attributes, `@php` blocks, and controller-provided variables, while preventing cross-scope collisions and protecting reserved framework variables.

---

## 1. LSP Server Capabilities & Protocol Dispatch

### 1.1 Server Initialization
In `server/app/Lsp/Methods/Initialize.php`, update the server capabilities registration:
```php
'renameProvider' => [
    'prepareProvider' => true,
],
```

### 1.2 Protocol Handlers
- **`TextDocumentPrepareRename` (`textDocument/prepareRename`)**:
  - Validates document URI and position.
  - Calls `BladeVariableRenameProvider::prepareRename($document, $position)`.
  - Returns `{ range: Range, placeholder: string }` if valid, or `null` if the token cannot be renamed.
- **`TextDocumentRename` (`textDocument/rename`)**:
  - Validates `newName` against standard PHP variable identifier naming rules.
  - Calls `BladeVariableRenameProvider::rename($document, $position, $newName)`.
  - Returns a standard `WorkspaceEdit` (`{ changes: { [uri]: TextEdit[] } }`) or `null` on error/invalid input.

---

## 2. Token Extraction & Validation (`prepareRename`)

### 2.1 Supported Variable Contexts
A symbol at position `(line, character)` is eligible for renaming if it is a PHP variable token (`$varName`) situated in:
1. **Echo Nodes:** `{{ $var }}`, `{!! $var !!}`
2. **Directive Arguments:** `@if($var)`, `@foreach($items as $key => $var)`, `@forelse($items as $var)`, `@for($var = 0; $var < 10; $var++)`, `@while($var)`, `@switch($var)`, `@case($var)`, `@empty($var)`, `@isset($var)`
3. **Bound Attributes:** `:prop="$var"`, `wire:model="$var"`, `x-bind:data="$var"`
4. **Embedded PHP:** `@php ... @endphp` and `@php($var = ...)`

### 2.2 Rejections & Guards
`prepareRename` returns `null` for:
- Non-PHP tokens: HTML tags, raw HTML text, CSS `<style>` blocks, JavaScript `<script>` blocks, static HTML attributes.
- Comments: Blade comments (`{{-- ... --}}`) and PHP comments (`//`, `/* ... */`).
- Non-variable identifiers: Method calls (`->method()`), property fetches (`->prop`), class names (`User::class`), functions (`compact()`).
- **Framework Reserved Variables:** `$loop`, `$errors`, `$__env`, `$this`, `$app`.

### 2.3 Output Format
Returns the UTF-16 character range spanning the variable identifier without the leading `$` and the placeholder name:
```json
{
  "range": {
    "start": { "line": 2, "character": 7 },
    "end": { "line": 2, "character": 11 }
  },
  "placeholder": "user"
}
```

---

## 3. Scope Tree & Lexical Shadow Resolution (`rename`)

### 3.1 Directive Scope Tree Construction
`BladeVariableRenameProvider` parses directive blocks into a hierarchical tree:
1. **Root Scope (Template Level):**
   - Covers lines `0` to end-of-file.
   - Contains controller/route variables (`compact()`, `with()`), `@props`, `@inject`, and top-level `@php` declarations.
2. **Loop Scopes:**
   - Enclosed by paired directives: `@foreach ... @endforeach`, `@forelse ... @endforelse`, `@for ... @endfor`, `@while ... @endwhile`.
   - Defines loop variables (`$item`, `$key`, `$i`).
   - Line range: `[startLine, endLine]`.

### 3.2 Scope Resolution & Shadowing Rules
Given target variable `$targetVarName` at cursor position `(line, col)`:
1. **Local Loop Variable:**
   - If the variable is bound by an enclosing loop directive, the rename scope is strictly the loop's `[startLine, endLine]`.
2. **Template-Level / Controller Variable:**
   - If the variable belongs to template scope, occurrences across the entire file are eligible.
   - **Shadow Exclusion:** If an inner loop re-declares the same variable name (e.g., `@foreach($item->children as $item)`), references inside that child loop belong to its own scope and are excluded from the outer rename.
3. **Inner Shadow Variable:**
   - If renaming `$item` declared by an inner child loop, only occurrences within that child loop are renamed; outer `$item` references remain untouched.

### 3.3 Edit Generation
- Scans AST expressions via `BladePhpAstAnalyzer::extractAllExpressions()`.
- Matches expressions where `kind === 'variable'` and `name === $targetVarName`.
- Filters matches that reside within the determined scope boundaries and outside any shadowing child scopes.
- Converts byte offsets to UTF-16 character columns.
- Generates and returns `WorkspaceEdit`:
```json
{
  "changes": {
    "file:///path/to/view.blade.php": [
      {
        "range": {
          "start": { "line": 2, "character": 7 },
          "end": { "line": 2, "character": 11 }
        },
        "newText": "renamedUser"
      }
    ]
  }
}
```

---

## 4. Testing & Verification

1. **Unit Tests:**
   - `prepareRename` tests across echos, directives, bound attributes, `@php` blocks, comments, HTML, JS, and reserved variable rejections.
   - `rename` tests for template variables, loop variables, nested loops with variable shadowing, and invalid name validation.
   - Protocol-level tests via LSP server JSON-RPC dispatcher.
2. **Full Suite:**
   - Run `./vendor/bin/pest` to verify all 200+ unit tests pass.
3. **Build & Package:**
   - Run `./build.sh` to install the extension to Zed.
