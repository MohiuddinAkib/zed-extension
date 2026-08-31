## Introduction

Laravel Akib is a local playground fork of the official Laravel Zed extension.
It integrates the [Laravel LSP](https://github.com/laravel/lsp) server with Zed,
providing completions, hover information, diagnostics, links, and code actions
for PHP and Blade files.

The immediate goal is to keep the Zed extension wrapper small while moving deeper
Laravel and Blade intelligence into Laravel LSP or a compatible language server.
See [ROADMAP.md](ROADMAP.md) for the planned Blade, component, mailable,
notification, Livewire, Filament, and Inertia work.

## LSP Documentation

Documentation for Laravel LSP can be found in the [Laravel LSP repository](https://github.com/laravel/lsp).

## Installation

For local development, install this repository as a Zed dev extension.
The extension name is **Laravel Akib**.

## Configuration

No configuration is required by default.

To pass initialization options directly to the Laravel LSP server, configure `lsp.laravel.initialization_options` in your Zed settings:

```json
{
  "lsp": {
    "laravel": {
      "binary": {
        "path": "php",
        "arguments": ["/path/to/laravel-zed-extension/server/server", "lsp"]
      },
      "initialization_options": {
        "phpEnvironment": "sail"
      }
    }
  }
}
```

For the full list of available configuration options, see the [Laravel LSP repository](https://github.com/laravel/lsp).

## Updates

The extension checks for Laravel LSP updates at most once every two hours. On macOS, you can force an update check without waiting by removing the `.last-update-check` file:

```bash
rm "$HOME/Library/Application Support/Zed/extensions/work/laravel-akib/laravel-lsp/.last-update-check"
```

Then run `editor: restart language server` from the command palette. This forces an update check, not necessarily a download. The extension downloads the latest release only when its binary is not already installed. This update mechanism is bypassed when a custom LSP binary is configured or when bundled with the extension.

## Contributing

Thank you for considering contributing to the Laravel Zed extension! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/zed-extension/security/policy) on how to report security vulnerabilities.

## License

The Laravel Zed extension is open-sourced software licensed under the [MIT license](https://opensource.org/license/mit).
