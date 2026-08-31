# Laravel Blade Intelligence Implementation Plan

> For agentic workers: use this as the execution checklist for the Superpowers workflow. Implement one task at a time, keep changes small, write the failing test first where practical, and update checkboxes as work lands.

## Goal

Deliver IDE-grade Laravel Blade authoring in the Zed extension by wiring the custom server, replacing shallow Blade/PHP heuristics with a source-mapped semantic pipeline, and then layering normal views, components, Mailables/notifications, Livewire, Filament, Inertia, diagnostics, and safe refactors.

## Ground Rules

- Keep the Zed Rust extension thin.
- Put Laravel and PHP intelligence in the language server.
- Prefer structured parsers and typed models over regex semantics.
- Use a pluggable PHP intelligence adapter instead of hand-rolling full PHP IntelliSense.
- Gate diagnostics and refactors by confidence.
- Add fixture coverage before expanding feature scope.
- Do not advertise a capability to Zed until the server handles it safely.

## Phase 0: Repository, Build, And Launch Foundation

**Outcome:** Zed reliably launches the intended custom server, and the repo has one obvious source of truth.

- [ ] Decide whether `server/` is a tracked subtree, submodule, or separate sibling repository.
- [ ] Make the selected server source part of the reproducible local build.
- [ ] Update `src/lib.rs` so local playground builds can prefer a configured custom binary or local packaged binary.
- [ ] Add a server version/health request exposed through LSP initialization logs.
- [ ] Update `build.sh` to build/copy both the WASM extension and the local server artifact, or document the explicit two-step local build.
- [ ] Fix README cache paths and local Zed settings examples for `laravel-akib`.
- [ ] Disable globally advertised rename support until rename providers are source-map safe.
- [ ] Add a smoke test or manual verification checklist proving Zed starts the custom server.

Acceptance criteria:

- Opening a Laravel project in Zed starts the custom server, not the upstream official binary.
- The server reports its custom version in logs.
- A fresh checkout has documented steps to build and run the local playground.

## Phase 1: Test Harness And Fixture Projects

**Outcome:** Feature work is measured through real Laravel projects and protocol-level tests.

- [ ] Add Laravel 10, 11, and 12 fixture projects or generated fixture snapshots.
- [ ] Add LSP protocol tests for initialize, didOpen, didChange, completion, hover, definition, diagnostics, signatureHelp, prepareRename, rename, and codeAction.
- [ ] Add Blade cursor fixture syntax for expected completions and hovers.
- [ ] Add package fixtures for anonymous components, class components, Mailables, notifications, Livewire, Filament, and Inertia.
- [ ] Make temp/cache directories configurable so Pest can run in sandboxed environments.
- [ ] Add focused CI commands for fast unit tests and slower fixture tests.

Acceptance criteria:

- Tests can prove a completion item appears at a specific Blade cursor.
- Tests can prove a hover/definition maps back to the original Blade source range.
- The suite covers at least one Laravel 10, 11, and 12 project path.

## Phase 2: Semantic Models

**Outcome:** Providers share a single typed representation instead of passing fragile strings.

- [x] Add `TypeRef` for named, nullable, union, intersection, generic, array shape, object shape, literal, enum, `view-string`, and `class-string` types.
- [x] Add `VariableSymbol` with name, `TypeRef`, origin, source range, optionality, confidence, and documentation.
- [x] Add `ViewScope` with merged variables and provenance from every call site.
- [ ] Add `ComponentSymbol`, `ComponentPropSymbol`, `SlotSymbol`, and `DirectiveSignature`.
- [x] Add a `TypeDisplay` service for rendering user-facing labels without corrupting stored type data.
- [x] Replace last-write-wins view variable merging with provenance-aware merging.

Acceptance criteria:

- Two call sites for one view produce one merged `ViewScope`.
- Optional or conditional variables are represented explicitly.
- Stored type data never includes UI-only text such as default values.

## Phase 3: PHP Intelligence Adapter And Virtual Blade Documents

**Outcome:** Blade PHP expressions can reuse real PHP intelligence.

- [x] Define `PhpIntelligenceAdapter` with completion, hover, definition, signature help, diagnostics, rename, and workspace symbol methods.
- [x] Spike adapter options: PHPactor, PHPStan/Psalm bridge, existing Laravel LSP parser, or a hybrid.
- [x] Implement `BladeDocumentCompiler` that converts Blade expressions/directive args/inline PHP into a valid virtual PHP document.
- [x] Implement source maps from Blade ranges to virtual PHP ranges and back.
- [x] Inject `ViewScope` variables into the virtual document with precise PHPDoc/native stubs.
- [x] Route Blade PHP completions, hovers, definitions, signatures, and diagnostics through the adapter.
- [ ] Add cancellation and debounce around adapter calls.

Acceptance criteria:

- `$user->` in Blade returns the same class members expected in PHP.
- Hover and definition results map to Blade ranges.
- Diagnostics from virtual PHP do not point at generated wrapper code.

## Phase 4: Normal View Scope Discovery

**Outcome:** Ordinary views receive accurate variables from controllers, routes, and factories.

- [x] Support `view()`, `View::make()`, `View::first()`, `response()->view()`, `Route::view()`, route closures, invokable controllers, and custom helpers marked with `@param view-string`.
- [x] Support array data, `compact()`, chained `with()`, `withErrors()`, `withInput()`, conditionals, and data merging.
- [x] Resolve imported aliases, namespaces, `self`, `static`, `parent`, native types, PHPDoc types, generics, and array/object shapes.
- [x] Infer collection item types for `@foreach` and paginator item types.
- [x] Add framework globals: `$__env`, `$app`, `$errors`, `$request`, `$loop`, and context-specific `$attributes`/`$slot`.
- [x] Add safe runtime indexing for `View::share` and view composers with timeout and JSON output.
- [ ] Add diagnostics for unknown variables only when the view scope is high-confidence.

Acceptance criteria:

- Common controller and route patterns provide correct variables in Blade.
- Multiple data sources merge without losing variables.
- Controller public properties are not exposed unless the class context makes them view data.

## Phase 5: Blade Syntax Scopes

**Outcome:** Local Blade constructs update scope precisely.

- [x] Parse `@props` with a structured Blade/PHP parser instead of greedy regex.
- [x] Support `@aware`, `@inject`, PHPDoc `@var`, `@php`, `@endphp`, `@isset`, `@empty`, `@error`, `@auth`, and `@guest`.
- [x] Support `@foreach`, `@forelse`, key/value variables, `$loop`, nested loop parent, and item type unwrapping.
- [ ] Support `@include`, `@includeWhen`, `@includeUnless`, `@includeFirst`, `@each`, layouts, sections, stacks, and pushed data.
- [x] Preserve completions inside inline `<script>` Blade expressions, `@js`, and `@json`.

Acceptance criteria:

- `$loop` and iteration variables complete only inside their valid scope.
- `@props` supports nested arrays, closures, quoted commas, expressions, and default metadata.
- Included views receive inherited and explicitly passed data.

## Phase 6: Component Props, Attributes, And Slots

**Outcome:** Component consumption and authoring are first-class.

- [ ] Build a `ComponentRegistry` from class components, anonymous components, aliases, namespaces, package views, and vendor paths.
- [ ] Extract class component constructor params, promoted public properties, public properties, render views, inheritance, PHPDoc, defaults, and requiredness.
- [ ] Extract anonymous component `@props`, `@aware`, named slots, default slot, and `$attributes` usage.
- [ ] Complete component attributes, including kebab/camel prop mapping, boolean props, enum/literal values, class-string values, and `:bound` expressions.
- [ ] Complete `<x-slot:name>`, `<x-slot name="">`, and slot props/data in slot bodies.
- [ ] Add definitions from tags to component files/classes and from attributes to props.
- [ ] Add diagnostics for unknown components, unknown props, missing required props, invalid prop value type, and duplicate slots.

Acceptance criteria:

- `<x-user-card :user="$user" />` offers the correct props and validates required ones.
- Bound prop values use normal Blade/PHP variable completion.
- Slot body variables are scoped to that slot.

## Phase 7: Mailables And Notifications

**Outcome:** Mail and notification templates get correct public property and view data completion.

- [ ] Index `app/Mail`, `app/Notifications`, and custom configured directories.
- [ ] Support `content()` with `Content(view:, markdown:, html:, text:, with:)`.
- [ ] Support legacy `build()` APIs and chained `view()`, `markdown()`, `text()`, `html()`, and `with()`.
- [ ] Support notification `toMail()` and `MailMessage` view/markdown methods.
- [ ] Expose public and promoted properties only for valid Mailable/notification template contexts.
- [ ] Add fixture coverage for Mailables and notifications in Laravel 10, 11, and 12.

Acceptance criteria:

- Mailable and notification Blade views complete constructor/public properties and explicit `with()` data.
- Public properties from unrelated classes are not exposed.

## Phase 8: Livewire

**Outcome:** Livewire component templates and consumers have useful typed completion.

- [ ] Detect Livewire v2, v3, v4, and Volt when installed.
- [ ] Map component classes to Blade views and component tags.
- [ ] Complete public properties, computed properties, form objects, modelable properties, and nested property paths.
- [ ] Complete `wire:model`, `wire:click`, `wire:submit`, `wire:target`, `wire:loading`, `wire:poll`, `wire:navigate`, and modifiers.
- [ ] Complete action method arguments where types are known.
- [ ] Add diagnostics for unknown actions and unknown model paths when confidence is high.

Acceptance criteria:

- `wire:model="user.email"` completes from the Livewire component state.
- `wire:click="save"` completes known public actions.
- Livewire Blade views expose `$this` and public properties with correct types.

## Phase 9: Filament

**Outcome:** Filament Blade authoring understands common resource/page/widget contexts.

- [ ] Detect Filament version and installed panels.
- [ ] Index Resources, Pages, RelationManagers, Widgets, Actions, Forms, Tables, Infolists, and custom views.
- [ ] Expose `$record`, `$this`, `$get`, `$set`, `$state`, `$data`, action data, table records, and form state where context proves them.
- [ ] Complete Filament component/view namespaces and Blade hooks.
- [ ] Add diagnostics for common state-path and action-name mistakes when confidence is high.

Acceptance criteria:

- Custom Filament views receive context-aware completions for records, state, and actions.
- Filament-specific globals are not leaked into ordinary Blade views.

## Phase 10: Inertia

**Outcome:** Inertia PHP render calls become discoverable and typed.

- [ ] Improve PHP-side `Inertia::render()` prop extraction with `TypeRef`.
- [ ] Link render strings to Vue/React/Svelte components where project structure is known.
- [ ] Provide hover and diagnostics for unknown Inertia component names.
- [ ] Design optional cross-language prop propagation for Vue, React, and Svelte adapters.

Acceptance criteria:

- PHP Inertia render props are indexed with types and provenance.
- Unknown component names are diagnosed only when paths are high-confidence.

## Phase 11: Helpers, Directives, And Signatures

**Outcome:** Laravel APIs inside Blade provide typed help beyond snippets.

- [ ] Add `textDocument/signatureHelp` capability and method.
- [ ] Build directive signatures for first-party Blade directives.
- [ ] Build helper signatures and return types for Laravel helpers.
- [ ] Complete route names, config keys, translation keys, env keys, asset paths, auth guards, gate abilities, policy abilities, view names, and component names in argument positions.
- [ ] Add hover docs for helpers and directives.
- [ ] Add diagnostics for invalid directive/helper arguments where confidence is high.

Acceptance criteria:

- `@can(`, `@include(`, `route(`, `config(`, and `__()` show useful completions and signatures.
- Directive snippets remain, but semantic completions take precedence in argument positions.

## Phase 12: Refactors And Code Actions

**Outcome:** Editing assistance is safe enough to trust.

- [ ] Re-enable rename only for supported symbol kinds.
- [ ] Implement source-map safe local Blade variable rename.
- [ ] Implement view rename with PHP string references and Blade include/extends/component references.
- [ ] Implement component prop rename across constructor/`@props`/attributes.
- [ ] Implement extract-to-component from a selected Blade range.
- [ ] Implement code actions for missing `@props`, missing view data key, unknown view creation, and invalid component prop fixes.
- [ ] Add rollback-free edit generation tests for each refactor.

Acceptance criteria:

- Rename never touches comments, strings, JavaScript, CSS, or unrelated scopes unless the symbol kind explicitly requires it.
- Every refactor has fixture coverage and source range assertions.

## Phase 13: Performance, Settings, And Release Hardening

**Outcome:** The extension is usable on real projects.

- [ ] Add settings for feature flags, runtime introspection, analyzer backend, cache location, max indexing time, and debug logging.
- [ ] Add cancellation to long indexing and analysis work.
- [ ] Add cache invalidation for Composer files, framework config, routes, views, components, PHP files, package manifests, and generated Laravel caches.
- [ ] Add memory and latency benchmarks for large Laravel apps.
- [ ] Add a minimal troubleshooting command or log section for server path, version, project root, PHP path, Composer path, and enabled adapters.
- [ ] Package a local release artifact and document installation in Zed.

Acceptance criteria:

- Completion stays responsive on large fixture projects.
- Indexing work is visible and cancellable.
- Debug output explains missing completions without requiring code inspection.

## First Vertical Slice

Build this before expanding breadth:

- [ ] Wire Zed to the custom server.
- [ ] Add protocol fixture tests.
- [x] Add `TypeRef`, `VariableSymbol`, and `ViewScope`.
- [x] Fix view variable merging.
- [ ] Support `view()`, `View::make()`, chained `with()`, `compact()`, Mailable `Content`, and notification `MailMessage`.
- [ ] Add component prop completion for class and anonymous components.
- [ ] Disable unsafe rename until source maps land.
- [ ] Prove `$user->` completion in one normal Blade view and one component Blade view.

## Open Decisions

- Choose the first PHP intelligence backend: PHPactor, PHPStan/Psalm bridge, existing Laravel LSP parser, or hybrid.
- Choose server repository structure: tracked subtree, submodule, or separate sibling repository.
- Choose release strategy: local-only custom binary first, then GitHub release assets later.
- Choose diagnostic strictness defaults for ambiguous view data.
- Choose supported Livewire, Filament, and Inertia versions for the first public milestone.
