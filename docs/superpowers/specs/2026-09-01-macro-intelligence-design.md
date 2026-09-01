# Universal Macro Intelligence Design Specification

## 1. Overview
Provide complete, universal IDE intelligence for all Laravel macros and macroable targets across PHP and Blade files in the Laravel Zed extension.

## 2. Supported Macro Registration Patterns
The engine supports discovering macros from:
1. **Direct Closures:**
   ```php
   Http::macro('smsq', function (string $to, string $msg): PendingRequest { ... });
   PendingRequest::macro('withCaching', function (int $ttl = 3_600): PendingRequest { ... });
   Collection::macro('toCsv', function (): string { ... });
   ```
2. **Arrow Functions:**
   ```php
   Str::macro('prefix', fn (string $str, string $prefix): string => $prefix . $str);
   ```
3. **Class Mixins (`::mixin()`):**
   ```php
   Str::mixin(new StringMixin());
   Str::mixin(StringMixin::class);
   ```
   Extracts all public methods from the mixin class and maps them onto the target class/facade.
4. **Array Callables & Invokable Classes:**
   ```php
   Response::macro('caps', [CustomResponse::class, 'format']);
   Response::macro('caps', new CustomResponse());
   ```
5. **Conditional & Nested Provider Methods:**
   - Detects macros declared inside helper methods (e.g. `configureHttpMacros()` in `AppServiceProvider`), `if` blocks, and custom service providers.
6. **Facade ↔ Underlying Instance Bridging:**
   - `Http::macro()` ↔ `Http` facade (static `::`) and `PendingRequest` (instance `->`).
   - `Collection::macro()` ↔ `Illuminate\Support\Collection`, `LazyCollection`, and `Eloquent\Collection`.
   - `Str::macro()` ↔ `Str` (static `::`) and `Stringable` (instance `->`).
   - `Arr::macro()`, `File::macro()`, `Response::macro()`, `Route::macro()`, `Blueprint::macro()`, `Builder::macro()`.
7. **Vendor & Dynamic Package Macros:**
   - Runtime template inspection on `Macroable::$macros` to index third-party package macros (Spatie, Filament, Livewire).

## 3. Architecture & Components

```
┌─────────────────────────────────────────────────────────────┐
│                      MacroRegistry                          │
│  - Maps Target Class / Facade -> array<MacroSymbol>         │
│  - Resolves static vs instance access                       │
│  - Caches parameter types, defaults, and return TypeRef     │
└──────────────────────▲───────────────────────▲──────────────┘
                       │                       │
      ┌────────────────┴────────┐    ┌─────────┴───────────────┐
      │   PhpMacroAstAnalyzer   │    │   Runtime Macro Index   │
      │ (Workspace AST Scanner) │    │       (macros.php)      │
      └─────────────────────────┘    └─────────────────────────┘
```

### Components:
1. **`MacroSymbol`**:
   - `name`: string (e.g., `'withCaching'`)
   - `targetClass`: string (e.g., `'Illuminate\Http\Client\PendingRequest'`)
   - `facadeClass`: ?string (e.g., `'Illuminate\Support\Facades\Http'`)
   - `parameters`: array of `ParameterSymbol` (name, type, required, default)
   - `returnType`: `TypeRef`
   - `sourcePath`: ?string (file path where macro was defined)
   - `sourceLine`: ?int (line number of macro registration)
   - `isStatic`: bool

2. **`PhpMacroAstAnalyzer`**:
   - Scans PHP statements for `::macro()` and `::mixin()` calls.
   - Extracts parameters (names, typehints, default values) and return types from closures, arrow functions, or mixin methods.
   - Records definition location (`filePath`, `line`).

3. **`MacroRegistry`**:
   - Indexes all macro symbols by target class and facade aliases.
   - Bridges facade calls (`Http::smsq()`) to concrete classes (`PendingRequest->smsq()`) and vice-versa.
   - Provides lookup methods: `getMacrosForClass(string $class)`, `getMacro(string $class, string $method)`.

4. **Integration with Member Completion (`BladeMemberCompletionProvider` & `PhpIntelligence`)**:
   - Injects macro methods into autocomplete for both `->` and `::`.
   - Provides snippet formatting with tabstops for required parameters.
   - Returns detail: `(macro) function(string $to, string $msg): PendingRequest`.

5. **Definition & Hover Integration**:
   - `textDocument/definition`: Resolves macro calls to the exact file and line where `*::macro()` or mixin was declared.
   - `textDocument/hover`: Displays macro signature, docblock, return type, and definition link.

6. **Type Inference & Chaining**:
   - Connects macro return types to `FunctionTypeResolver` and `DataPathResolver`, enabling continuous fluent autocompletion on chained calls (e.g., `Http::smsq()->...`).

## 4. Test Plan
1. **AST Analysis**:
   - Test extraction from `AppServiceProvider` with closures and arrow functions.
   - Test mixin extraction from class instances and class strings.
   - Test array callables and invokables.
2. **Completion**:
   - Test `Http::smsq()` and `Http::withCaching()` static completion.
   - Test `$request->withCaching()` and `$collection->myMacro()` instance completion.
3. **Definition**:
   - Test Go to Definition on `Http::smsq` jumps to `AppServiceProvider.php` definition line.
4. **Chaining**:
   - Test `Http::smsq()->` chaining suggests `PendingRequest` methods.
