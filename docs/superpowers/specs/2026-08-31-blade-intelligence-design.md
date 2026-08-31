# Laravel Blade Intelligence Specification

Status: draft
Owner: Akib
Workspace: `laravel-zed-extension`
Target editor: Zed
Target framework versions: Laravel 10, Laravel 11, Laravel 12

## Summary

Build a local Zed extension and Laravel language server fork that makes Blade files feel like normal PHP files: completions, hovers, definitions, diagnostics, signatures, and refactors should understand the data available to each template and the Laravel APIs used inside it.

The Zed extension must stay thin. It should install or launch the language server and pass settings. All Laravel, Blade, PHP, Livewire, Filament, Inertia, component, and refactoring intelligence belongs in the server.

## Current State

The repository is based on the official Laravel Zed extension. The current Rust extension still downloads and runs the official `laravel/lsp` binary by default, while the custom PHP server work is inside `server/`.

The server already contains early static analyzers for view variables, Blade variables, Blade member completion, Blade PHP hover/linking, and rename. These are useful prototypes, but they are not enough for IDE-grade Blade authoring because they rely heavily on regexes, shallow reflection, and incomplete project indexing.

## Goals

- Provide Blade variable completion from controller, route, Mailable, notification, component, Livewire, Filament, Inertia, and framework-provided scopes.
- Provide type-aware member completion, hover, definition, signature help, diagnostics, and refactors inside Blade expressions.
- Respect native PHP types, PHPDoc, generics, array shapes, object shapes, literal strings, `view-string`, `class-string`, Eloquent model magic, Laravel collections, and framework helper return types.
- Complete Blade component tags, props, attributes, bound expressions, named slots, default slots, slot data, `$attributes`, `$slot`, and `@aware`.
- Support Laravel first-party and ecosystem workflows in priority order: normal views, Blade components, Mailables/notifications, Livewire, Filament, then Inertia.
- Keep the implementation testable, incremental, memory-conscious, and safe by default.

## Non-Goals For V1

- Do not hand-roll a full PHP type engine with regexes and reflection.
- Do not run arbitrary project code on every keystroke.
- Do not advertise refactors until source ranges and symbol confidence are reliable.
- Do not attempt complete Laravel Idea parity in the first milestone.
- Do not put Laravel project analysis into the Zed WebAssembly wrapper.

## Primary User Workflows

### Normal Views

When editing `resources/views/users/show.blade.php`, the developer should get `$user` completion if the view is returned from:

```php
return view('users.show', ['user' => $user]);
return view('users.show')->with('user', $user);
return View::make('users.show', compact('user'));
return response()->view('users.show', ['user' => $user]);
Route::view('/users/{user}', 'users.show', ['user' => $user]);
```

Completion inside Blade should understand `$user->`, collection item types in `@foreach`, `$loop`, nullability, PHPDoc, Eloquent attributes, relations, accessors, casts, and custom methods.

### Blade Components

When consuming `<x-alert />`, the editor should complete:

- Component names and aliases.
- Required and optional props.
- Kebab-case and camelCase prop forms.
- Bound attributes such as `:user="$user"`.
- Attribute value suggestions when the prop has literal, enum, boolean, or class-string types.
- Named slots and default slots.
- Slot props/data passed to the slot body.

When authoring a component template, the editor should expose constructor-promoted public properties, public properties, `@props`, `@aware`, `$attributes`, `$slot`, and named slot variables.

### Mailables And Notifications

When editing a Mailable or notification view, the editor should infer data from:

- Public properties and constructor-promoted public properties.
- `content()` returning `Illuminate\Mail\Mailables\Content`.
- Legacy `build()` methods.
- `with()` data.
- `markdown`, `html`, `text`, and `view` variants.
- Notification `toMail()` methods and `MailMessage` view/markdown APIs.

### Livewire

Livewire support should cover public properties, `mount()` parameters, computed properties, actions, `wire:model`, `wire:click`, `wire:submit`, `wire:target`, `wire:loading`, Livewire component tags, Volt files, and Blade views rendered by Livewire components.

### Filament

Filament support should cover Page, Resource, RelationManager, Widget, table, form, action, infolist, and Blade hook contexts. Completion should know common context variables such as `$record`, `$this`, form state paths, table records, action data, and view data provided by Filament classes.

### Inertia

Inertia support should connect PHP-side props to template/component authoring where possible. The first useful milestone is reliable PHP prop discovery and hover/link support for Inertia render calls. Cross-language propagation into Vue, React, or Svelte should be designed as an optional later adapter.

## Functional Requirements

### Extension Launching

- The extension must support a local custom server binary during development.
- The extension must clearly report which server binary is running.
- The extension must not silently run the upstream official server when the custom server is expected.
- Release packaging must have one source of truth for the server artifact.

### Project Index

The language server must maintain an incremental Laravel project index containing:

- Framework version and package versions.
- Routes, controllers, invokable controllers, route closures, and route model bindings.
- Views, vendor views, namespaces, anonymous components, class components, and component aliases.
- View composers, view creators, `View::share`, and framework globals.
- Mailables and notifications.
- Livewire, Volt, Filament, and Inertia metadata.
- Eloquent models, casts, attributes, accessors, relations, scopes, factories, and collection types.
- Custom Blade directives and helper functions.

### Blade Scope Resolution

Each Blade document must resolve a `ViewScope` composed from:

- Data explicitly passed to matching view names.
- Public properties from valid view-backed classes only, such as components, Mailables, notifications, and Livewire components.
- Runtime shared data gathered safely from bounded Laravel scripts.
- Local Blade declarations such as `@props`, `@aware`, `@inject`, `@php`, and PHPDoc `@var`.
- Control-flow scopes from `@foreach`, `@forelse`, `@for`, `@while`, `@if`, `@isset`, `@empty`, `@error`, `@auth`, and `@guest`.
- Include/partial data propagation from `@include`, `@each`, components, slots, sections, stacks, and layouts.

Multiple call sites for the same view must be represented as merged symbols with provenance and optionality, not as last-write-wins data.

### Virtual PHP Documents

Blade PHP regions must be transformed into a virtual PHP document with source maps back to the Blade file. The virtual document should include:

- Injected variables from `ViewScope`.
- Valid PHP wrappers for Blade echo, directive expression, and inline PHP regions.
- Context markers for completions inside HTML attributes, component attributes, directive arguments, and inline scripts.
- Stable source maps for completion replacement ranges, hover ranges, diagnostics, definition links, and rename edits.

The virtual PHP document should delegate ordinary PHP intelligence to a mature PHP analyzer through a `PhpIntelligenceAdapter`.

### Type System

The server must normalize types into a structured `TypeRef` model rather than passing string fragments between providers.

Required type forms:

- Named classes and interfaces.
- `self`, `static`, `parent`, imported aliases, fully qualified names, and relative namespaces.
- Nullable, union, and intersection types.
- Generic types such as `Collection<int, App\Models\User>`.
- Array shapes and object shapes.
- Literal strings, booleans, integers, enum cases, `view-string`, `class-string<T>`, and `key-of`.
- Laravel collection item types and paginator item types.
- Eloquent model dynamic attributes, relations, casts, accessors, scopes, and builders.
- PHPDoc `@template`, `@extends`, `@implements`, `@mixin`, `@method`, `@property`, and local `@var`.

### Helpers And Directives

Laravel helpers and Blade directives must provide:

- Completion.
- Hover documentation.
- Signature help.
- Parameter completion.
- Return type inference.
- Diagnostics for wrong arity or impossible argument types when confidence is high.

Priority helpers include `view`, `route`, `url`, `asset`, `config`, `env`, `auth`, `request`, `session`, `old`, `csrf_token`, `method_field`, `app`, `resolve`, `trans`, `__`, `now`, `today`, `collect`, and `fake`.

Priority directives include `@extends`, `@section`, `@yield`, `@include`, `@each`, `@component`, `@slot`, `@props`, `@aware`, `@class`, `@style`, `@checked`, `@selected`, `@disabled`, `@readonly`, `@required`, `@can`, `@cannot`, `@canany`, `@auth`, `@guest`, `@error`, `@csrf`, `@method`, `@vite`, `@livewire`, `@filamentScripts`, and `@filamentStyles`.

### Diagnostics

Diagnostics must start conservative. A diagnostic should be emitted only when the server can prove the issue from indexed data and source maps.

Useful diagnostics:

- Unknown view.
- Unknown component.
- Unknown component prop.
- Missing required component prop.
- Unknown Blade variable.
- Unknown property or method on known type.
- Invalid directive argument count or type.
- Invalid route, translation, config, asset, or env key.
- Duplicate slot name.
- Dead `@props` entry that is never consumed.

### Refactors

Refactors must be source-map based and confidence-gated.

Priority refactors:

- Rename Blade local variable within safe scope.
- Rename view file and update string references.
- Rename component tag and update references.
- Rename component prop and update constructor, `@props`, and consuming attributes.
- Extract selected Blade block to anonymous component.
- Add missing `@props` from component attribute usage.
- Add missing data key to controller/view call from an unknown Blade variable.

## Architecture

### Zed Extension

Responsibilities:

- Register Laravel LSP for PHP and Blade.
- Resolve local custom server binary from settings.
- Install or locate release server binary.
- Pass initialization options and feature flags.
- Report server version and binary source.

Non-responsibilities:

- Parsing Blade.
- Indexing Laravel projects.
- Inferring PHP types.
- Performing refactors.

### Language Server Modules

```text
server/app/Lsp/
├── Analysis/
│   ├── BladeDocumentCompiler.php
│   ├── BladeScopeAnalyzer.php
│   ├── PhpIntelligenceAdapter.php
│   └── TypeResolver.php
├── Data/
│   ├── ProjectIndex.php
│   ├── ViewScopes.php
│   ├── Components.php
│   ├── Mailables.php
│   ├── Notifications.php
│   ├── Livewire.php
│   ├── Filament.php
│   └── Inertia.php
├── Features/
│   ├── Blade/
│   ├── Components/
│   ├── Directives/
│   ├── Helpers/
│   └── Refactors/
└── Methods/
    ├── TextDocumentCompletion.php
    ├── TextDocumentHover.php
    ├── TextDocumentDefinition.php
    ├── TextDocumentSignatureHelp.php
    ├── TextDocumentRename.php
    └── TextDocumentCodeAction.php
```

### Core Data Models

```php
final class ViewScope
{
    /** @var array<string, VariableSymbol> */
    public array $variables;

    /** @var list<ScopeOrigin> */
    public array $origins;
}

final class VariableSymbol
{
    public string $name;
    public TypeRef $type;
    public bool $optional;
    public Confidence $confidence;
    public SourceRange $sourceRange;
    public ScopeOrigin $origin;
}

final class TypeRef
{
    public TypeKind $kind;
    public string $displayName;
    /** @var list<TypeRef> */
    public array $children;
    /** @var array<string, TypeRef> */
    public array $shape;
}
```

## Performance Requirements

- Initial indexing should be bounded and cancellable.
- Open-document completion should use cached indexes and virtual document deltas.
- File invalidation should be dependency-aware, not full-project by default.
- Cache keys should use path, size, high-resolution modified time when available, and content hash for unstable file systems.
- Runtime Laravel scripts must have timeouts and must run only during controlled indexing, never on every completion request.

## Security Requirements

- Do not execute user project code on completion, hover, or rename requests.
- Treat runtime introspection as opt-in or bounded to safe scripts.
- Never deserialize arbitrary project output into executable code.
- Never send project code or symbols to remote services.
- Keep generated refactor edits inside the workspace root.

## Verification Strategy

- Unit tests for parsers, type normalization, scope merging, component props, slot scopes, helper signatures, and refactor edit generation.
- Protocol tests for LSP initialize, open, change, completion, hover, definition, diagnostics, signature help, rename, and code actions.
- Fixture projects for Laravel 10, 11, and 12.
- Package fixtures for Livewire, Filament, and Inertia.
- Zed extension startup tests that confirm the intended server binary is launched.
- Performance fixtures for large projects with thousands of views/components/routes.

## Release Criteria For Local Playground V1

- Zed launches the custom server without manual path confusion.
- Normal view variables complete and hover correctly for common controller, route, and Mailable cases.
- Component tags, props, and bound prop expressions complete for class and anonymous components.
- Blade PHP expressions use a PHP intelligence adapter or a documented temporary fallback.
- Rename is disabled except for safe scopes that are covered by tests.
- Fixture tests pass for Laravel 10, 11, and 12 core workflows.
