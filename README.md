# Laravel Zed Extension

This is a local development Zed extension for Laravel.

## Installation

In Zed, run `zed: install dev extension` and select:

```sh
/path/to/zed-extension
```

## Configuration

No configuration is required by default. The extension automatically checks the
latest `laravel/lsp` release, downloads the matching standalone Laravel LSP
server binary, and starts it for PHP and Blade files.

The extension currently supports the standalone binaries published by
`laravel/lsp` for:

- macOS arm64 and x64
- Linux arm64 and x64
- Windows x64

To use a local or custom Laravel LSP server instead, configure the server path in
Zed settings:

```json
{
  "lsp": {
    "laravel": {
      "binary": {
        "path": "/path/to/lsp/server",
        "arguments": []
      }
    }
  }
}
```

## Requirements

- Zed PHP language support for PHP files.
- The Blade extension for Blade files.
- PHP and Composer available to the Laravel project as required by Laravel LSP.
- If you configure a custom Laravel LSP server path, it must be executable.

Downloaded Laravel LSP binaries are managed by this extension. Old managed
binaries are removed automatically when a newer release is installed.
