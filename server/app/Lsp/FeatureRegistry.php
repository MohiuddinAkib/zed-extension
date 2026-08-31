<?php

declare(strict_types=1);

namespace App\Lsp;

use App\Lsp\Contracts\CodeActionProvider;
use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Contracts\DiagnosticProvider;
use App\Lsp\Contracts\FileWatcher;
use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Features\AppBindings\AppBindingCompletionProvider;
use App\Lsp\Features\AppBindings\AppBindingDiagnosticProvider;
use App\Lsp\Features\AppBindings\AppBindingHoverProvider;
use App\Lsp\Features\AppBindings\AppBindingLinkProvider;
use App\Lsp\Features\Assets\AssetCompletionProvider;
use App\Lsp\Features\Assets\AssetDiagnosticProvider;
use App\Lsp\Features\Assets\AssetLinkProvider;
use App\Lsp\Features\Auth\AuthCompletionProvider;
use App\Lsp\Features\Auth\AuthDiagnosticProvider;
use App\Lsp\Features\Auth\AuthHoverProvider;
use App\Lsp\Features\Auth\AuthLinkProvider;
use App\Lsp\Features\BladeComponents\BladeComponentCompletionProvider;
use App\Lsp\Features\BladeComponents\BladeComponentHoverProvider;
use App\Lsp\Features\BladeComponents\BladeComponentLinkProvider;
use App\Lsp\Features\BladeDirectives\BladeDirectiveCompletionProvider;
use App\Lsp\Features\BladePhp\BladePhpHoverProvider;
use App\Lsp\Features\BladePhp\BladePhpLinkProvider;
use App\Lsp\Features\BladeVariables\BladeMemberCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeMemberHoverProvider;
use App\Lsp\Features\BladeVariables\BladeMemberLinkProvider;
use App\Lsp\Features\BladeVariables\BladeVariableCompletionProvider;
use App\Lsp\Features\BladeVariables\BladeVariableHoverProvider;
use App\Lsp\Features\BladeVariables\BladeVariableLinkProvider;
use App\Lsp\Features\Configs\ConfigCompletionProvider;
use App\Lsp\Features\Configs\ConfigDiagnosticProvider;
use App\Lsp\Features\Configs\ConfigHoverProvider;
use App\Lsp\Features\Configs\ConfigLinkProvider;
use App\Lsp\Features\ControllerActions\ControllerActionCompletionProvider;
use App\Lsp\Features\ControllerActions\ControllerActionDiagnosticProvider;
use App\Lsp\Features\ControllerActions\ControllerActionLinkProvider;
use App\Lsp\Features\Eloquent\EloquentCompletionProvider;
use App\Lsp\Features\Env\EnvCodeActionProvider;
use App\Lsp\Features\Env\EnvCompletionProvider;
use App\Lsp\Features\Env\EnvDiagnosticProvider;
use App\Lsp\Features\Env\EnvHoverProvider;
use App\Lsp\Features\Env\EnvLinkProvider;
use App\Lsp\Features\Inertia\InertiaCodeActionProvider;
use App\Lsp\Features\Inertia\InertiaCompletionProvider;
use App\Lsp\Features\Inertia\InertiaDiagnosticProvider;
use App\Lsp\Features\Inertia\InertiaHoverProvider;
use App\Lsp\Features\Inertia\InertiaLinkProvider;
use App\Lsp\Features\Inertia\InertiaPropertyCompletionProvider;
use App\Lsp\Features\LivewireComponents\LivewireComponentCompletionProvider;
use App\Lsp\Features\LivewireComponents\LivewireComponentHoverProvider;
use App\Lsp\Features\LivewireComponents\LivewireComponentLinkProvider;
use App\Lsp\Features\Middleware\MiddlewareCompletionProvider;
use App\Lsp\Features\Middleware\MiddlewareDiagnosticProvider;
use App\Lsp\Features\Middleware\MiddlewareHoverProvider;
use App\Lsp\Features\Middleware\MiddlewareLinkProvider;
use App\Lsp\Features\Mix\MixCompletionProvider;
use App\Lsp\Features\Mix\MixDiagnosticProvider;
use App\Lsp\Features\Mix\MixHoverProvider;
use App\Lsp\Features\Mix\MixLinkProvider;
use App\Lsp\Features\Paths\PathLinkProvider;
use App\Lsp\Features\Routes\RouteCompletionProvider;
use App\Lsp\Features\Routes\RouteDiagnosticProvider;
use App\Lsp\Features\Routes\RouteHoverProvider;
use App\Lsp\Features\Routes\RouteLinkProvider;
use App\Lsp\Features\Routes\RouteParameterCompletionProvider;
use App\Lsp\Features\Storage\StorageCompletionProvider;
use App\Lsp\Features\Storage\StorageDiagnosticProvider;
use App\Lsp\Features\Storage\StorageLinkProvider;
use App\Lsp\Features\Translations\TranslationCompletionProvider;
use App\Lsp\Features\Translations\TranslationDiagnosticProvider;
use App\Lsp\Features\Translations\TranslationHoverProvider;
use App\Lsp\Features\Translations\TranslationLinkProvider;
use App\Lsp\Features\Translations\TranslationLocaleCompletionProvider;
use App\Lsp\Features\Translations\TranslationParameterCompletionProvider;
use App\Lsp\Features\Validation\ValidationCompletionProvider;
use App\Lsp\Features\Views\ViewCodeActionProvider;
use App\Lsp\Features\Views\ViewCompletionProvider;
use App\Lsp\Features\Views\ViewContentCompletionProvider;
use App\Lsp\Features\Views\ViewDiagnosticProvider;
use App\Lsp\Features\Views\ViewHoverProvider;
use App\Lsp\Features\Views\ViewLinkProvider;
use App\Lsp\Watchers\DataProviderWatcher;
use App\Lsp\Watchers\PestHelperWatcher;
use Illuminate\Container\Container;

class FeatureRegistry
{
    /**
     * Link providers.
     *
     * @var array<int, class-string<LinkProvider>>
     */
    public array $links = [
        AppBindingLinkProvider::class,
        AssetLinkProvider::class,
        AuthLinkProvider::class,
        BladeComponentLinkProvider::class,
        ConfigLinkProvider::class,
        ControllerActionLinkProvider::class,
        EnvLinkProvider::class,
        InertiaLinkProvider::class,
        LivewireComponentLinkProvider::class,
        MiddlewareLinkProvider::class,
        MixLinkProvider::class,
        PathLinkProvider::class,
        RouteLinkProvider::class,
        StorageLinkProvider::class,
        TranslationLinkProvider::class,
        ViewLinkProvider::class,
        BladeVariableLinkProvider::class,
        BladeMemberLinkProvider::class,
        BladePhpLinkProvider::class,
    ];

    /**
     * Hover providers.
     *
     * @var array<int, class-string<HoverProvider>>
     */
    public array $hovers = [
        AppBindingHoverProvider::class,
        AuthHoverProvider::class,
        BladeComponentHoverProvider::class,
        ConfigHoverProvider::class,
        EnvHoverProvider::class,
        InertiaHoverProvider::class,
        LivewireComponentHoverProvider::class,
        MiddlewareHoverProvider::class,
        MixHoverProvider::class,
        RouteHoverProvider::class,
        TranslationHoverProvider::class,
        ViewHoverProvider::class,
        BladeVariableHoverProvider::class,
        BladeMemberHoverProvider::class,
        BladePhpHoverProvider::class,
    ];

    /**
     * Completion providers.
     *
     * @var array<int, class-string<CompletionProvider>>
     */
    public array $completions = [
        BladeMemberCompletionProvider::class,
        BladeComponentCompletionProvider::class,
        BladeDirectiveCompletionProvider::class,
        BladeVariableCompletionProvider::class,
        LivewireComponentCompletionProvider::class,
        EloquentCompletionProvider::class,
        RouteParameterCompletionProvider::class,
        RouteCompletionProvider::class,
        ValidationCompletionProvider::class,
        ControllerActionCompletionProvider::class,
        InertiaPropertyCompletionProvider::class,
        InertiaCompletionProvider::class,
        ViewContentCompletionProvider::class,
        ViewCompletionProvider::class,
        TranslationParameterCompletionProvider::class,
        TranslationLocaleCompletionProvider::class,
        TranslationCompletionProvider::class,
        AppBindingCompletionProvider::class,
        AssetCompletionProvider::class,
        AuthCompletionProvider::class,
        ConfigCompletionProvider::class,
        EnvCompletionProvider::class,
        MiddlewareCompletionProvider::class,
        MixCompletionProvider::class,
        StorageCompletionProvider::class,
    ];

    /**
     * Route diagnostics.
     *
     * @var array<int, class-string<DiagnosticProvider>>
     */
    public $diagnostics = [
        AppBindingDiagnosticProvider::class,
        AssetDiagnosticProvider::class,
        AuthDiagnosticProvider::class,
        ConfigDiagnosticProvider::class,
        ControllerActionDiagnosticProvider::class,
        EnvDiagnosticProvider::class,
        InertiaDiagnosticProvider::class,
        MiddlewareDiagnosticProvider::class,
        MixDiagnosticProvider::class,
        RouteDiagnosticProvider::class,
        StorageDiagnosticProvider::class,
        TranslationDiagnosticProvider::class,
        ViewDiagnosticProvider::class,
        \App\Lsp\Features\BladePhp\BladePhpDiagnosticProvider::class,
        \App\Lsp\Features\BladePhp\BladeSemanticDiagnosticAnalyzer::class,
    ];

    /**
     * Code action providers.
     *
     * @var array<int, class-string<CodeActionProvider>>
     */
    public array $codeActions = [
        EnvCodeActionProvider::class,
        InertiaCodeActionProvider::class,
        ViewCodeActionProvider::class,
    ];

    /**
     * File watchers.
     *
     * @var array<int, class-string<FileWatcher>>
     */
    public array $watchers = [
        DataProviderWatcher::class,
        PestHelperWatcher::class,
    ];

    /**
     * Instantiate a new class instance.
     */
    public function __construct(protected Container $container)
    {
        if (!$this->container->bound(\App\Lsp\Analysis\ComponentRegistry::class)) {
            $this->container->singleton(\App\Lsp\Analysis\ComponentRegistry::class, function () {
                return new \App\Lsp\Analysis\ComponentRegistry($this->container->make(Project::class));
            });
        }
    }

    /**
     * Resolve link providers.
     *
     * @return array<int, LinkProvider>
     */
    public function links(): array
    {
        return $this->resolve($this->links);
    }

    /**
     * Resolve hover providers.
     *
     * @return array<int, HoverProvider>
     */
    public function hovers(): array
    {
        return $this->resolve($this->hovers);
    }

    /**
     * Resolve completion providers.
     *
     * @return array<int, CompletionProvider>
     */
    public function completions(): array
    {
        return $this->resolve($this->completions);
    }

    /**
     * Resolve diagnostic providers.
     *
     * @return array<int, DiagnosticProvider>
     */
    public function diagnostics(): array
    {
        return $this->resolve($this->diagnostics);
    }

    /**
     * Resolve file watcher providers.
     *
     * @return array<int, FileWatcher>
     */
    public function watchers(): array
    {
        return $this->resolve($this->watchers);
    }

    /**
     * Resolve code action providers.
     *
     * @return array<int, CodeActionProvider>
     */
    public function codeActions(): array
    {
        return $this->resolve($this->codeActions);
    }

    /**
     * Resolve the active PHP intelligence adapter.
     */
    public function phpIntelligence(): \App\Lsp\Contracts\PhpIntelligenceAdapter
    {
        if ($this->container->bound(\App\Lsp\Contracts\PhpIntelligenceAdapter::class)) {
            return $this->container->make(\App\Lsp\Contracts\PhpIntelligenceAdapter::class);
        }

        return $this->container->make(\App\Lsp\Analysis\DefaultPhpIntelligenceAdapter::class);
    }

    /**
     * Resolve the Blade document compiler.
     */
    public function bladeCompiler(): \App\Lsp\Analysis\BladeDocumentCompiler
    {
        return $this->container->make(\App\Lsp\Analysis\BladeDocumentCompiler::class);
    }

    /**
     * Resolve the Blade scope resolver.
     */
    public function scopeResolver(): \App\Lsp\Analysis\BladeScopeResolver
    {
        return $this->container->make(\App\Lsp\Analysis\BladeScopeResolver::class);
    }

    /**
     * Resolve given classes from the container.
     *
     * @param array<int, class-string> $classes
     * @return array<int, mixed>
     */
    protected function resolve(array $classes): array
    {
        return array_map(fn (string $class) => $this->container->make($class), $classes);
    }
}
