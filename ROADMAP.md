# Laravel Akib Zed Extension Roadmap

This extension is a local playground built from the official Laravel Zed extension.
The Zed layer should stay thin: it installs, launches, and configures Laravel LSP.
Deep Blade and Laravel intelligence should live in Laravel LSP or a compatible language server.

## Product Direction

Support Laravel 10, 11, and 12 projects with editor-grade IntelliSense in PHP and Blade.
Blade files should feel as close as possible to ordinary PHP files: completions, hovers,
definitions, diagnostics, refactors, and type-aware suggestions should use the same inferred
project model.

## Priority Order

1. Normal Blade views returned from controllers, route closures, invokable controllers, and view factories.
2. Blade components, including class components, anonymous components, attributes, slots, `@props`, and `@aware`.
3. Mailables and notifications, including public properties, constructor-promoted properties, `with()` data, and envelope/content view resolution.
4. Livewire components.
5. Filament resources, pages, widgets, tables, forms, actions, and Blade hooks.
6. Inertia components and templates.

## Type Intelligence Goals

- Respect native PHP types, PHPDoc, generics, literal strings, `view-string`, `class-string`, collection item types, and Eloquent model magic.
- Infer variables available inside Blade, including Laravel-provided variables such as `$__env`, `$app`, `$errors`, `$attributes`, `$slot`, and named slots.
- Reuse PHP analysis wherever possible instead of duplicating a partial PHP parser in the Zed extension.
- Support helper/directive completions with typed parameters, return types, hover docs, and signatures.
- Provide code actions and refactors only when the server can prove the target safely.

## Architecture

### Zed Extension

- Register Laravel LSP for PHP and Blade.
- Install or locate a Laravel LSP-compatible server binary.
- Pass initialization options from `lsp.laravel.initialization_options`.
- Add local development affordances for custom binaries and feature flags through Zed settings.
- Avoid embedding project analysis in the extension WebAssembly module.

### Language Server

- Build a Laravel project index for routes, views, Blade components, mailables, notifications, Livewire, Filament, Inertia, config, translations, helpers, and directives.
- Convert Blade documents into virtual PHP documents that preserve source maps back to the Blade file.
- Inject inferred Blade scope into the virtual PHP document before delegating completion, hover, definition, diagnostics, rename, and code-action requests.
- Cache indexes incrementally and invalidate on file changes, Composer changes, route/cache changes, config changes, and framework version changes.
- Keep integration tests fixture-driven across Laravel 10, 11, and 12.

## First Implementation Slice

1. Keep this Zed wrapper close to upstream so it remains easy to rebase.
2. Add local settings examples for using a custom LSP binary during development.
3. Build or patch the server side to infer variables for:
   - `return view('users.show', ['user' => $user])`
   - `view('users.show')->with('user', $user)`
   - `View::make('users.show', compact('user'))`
   - class Blade components with public properties and constructor-promoted public parameters
   - anonymous Blade components using `@props`
4. Add fixture tests that assert completions and hovers in Blade map to the expected PHP types.

## Open Technical Questions

- Whether to extend `laravel/lsp` directly, wrap another PHP language server, or coordinate multiple servers.
- Whether typed Blade support should use PHPStan, Psalm, PHPactor, Laravel LSP internals, or a hybrid.
- How much framework code should be indexed dynamically versus generated into helper stubs.
- Which refactors are safe enough for v1: rename view, rename variable, rename component prop, extract component, or move view.
