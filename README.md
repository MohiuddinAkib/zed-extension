# Laravel Zed Extension

This is a local development Zed extension for Laravel.

## Installation

In Zed, run `zed: install dev extension` and select:

```sh
/path/to/zed-extension
```

## Configuration

No configuration is required by default. The extension automatically checks the
latest `laravel/lsp` release at most once every two hours, downloads the
matching standalone Laravel LSP server binary, and starts it for PHP and Blade
files. If no managed binary has been installed yet, the extension checks for a
release immediately. If an update check fails and a managed binary is already
installed, the extension continues using the installed binary.

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

To pass initialization options directly to the Laravel LSP server, configure
`lsp.laravel.initialization_options`:

```json
{
  "lsp": {
    "laravel": {
      "initialization_options": {
        "routeCompletion": false,
        "phpEnvironment": "sail",
        "phpCommand": ["./vendor/bin/sail", "php"]
      }
    }
  }
}
```

## Force-updating the Laravel LSP

The extension checks for Laravel LSP updates at most once every two hours. To
force an update check without waiting, delete the update-check timestamp file
from the extension's work directory:

- macOS:

  ```sh
  rm -f "$HOME/Library/Application Support/Zed/extensions/work/laravel/laravel-lsp/.last-update-check"
  ```

- Linux:

  ```sh
  rm -f "$HOME/.local/share/zed/extensions/work/laravel/laravel-lsp/.last-update-check"
  ```

- Windows (PowerShell):

  ```powershell
  Remove-Item "$env:LOCALAPPDATA\Zed\extensions\work\laravel\laravel-lsp\.last-update-check" -ErrorAction Ignore
  ```

Then run `editor: restart language server` from the command palette (or restart
Zed). The extension will check the latest `laravel/lsp` release immediately and
download it if a newer version is available.

To force a full re-download instead, delete the entire `laravel-lsp` directory
at the same location before restarting the language server.

## Requirements

- Zed PHP language support for PHP files.
- The Blade extension for Blade files.
- PHP and Composer available to the Laravel project as required by Laravel LSP.
- If you configure a custom Laravel LSP server path, it must be executable.

Downloaded Laravel LSP binaries are managed by this extension. Old managed
binaries are removed automatically when a newer release is installed.
