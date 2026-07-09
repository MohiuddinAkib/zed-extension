# Laravel Zed Extension

- [Introduction](#introduction)
- [Installation](#installation)
    - [Requirements](#requirements)
- [Configuration](#configuration)
    - [Automatic LSP Downloads](#automatic-lsp-downloads)
    - [Custom Server Binary](#custom-server-binary)
    - [Initialization Options](#initialization-options)
- [Force-Updating the Laravel LSP](#force-updating-the-laravel-lsp)

## Introduction

The Laravel Zed extension integrates the [Laravel LSP](https://github.com/laravel/lsp) server with Zed, providing completions, hover information, diagnostics, links, and code actions for your PHP and Blade files.

## Installation

To install the extension, run `zed: install dev extension` from the Zed command palette and select the extension's directory:

```sh
/path/to/zed-extension
```

### Requirements

- Zed PHP language support for PHP files.
- The Blade extension for Blade files.
- PHP and Composer available to your Laravel project.

## Configuration

### Automatic LSP Downloads

No configuration is required by default. The extension automatically downloads the latest standalone Laravel LSP server binary and starts it for PHP and Blade files. The following platforms are supported:

- macOS arm64 and x64
- Linux arm64 and x64
- Windows x64

### Custom Server Binary

To use a local or custom Laravel LSP server, you may configure the server path in your Zed settings:

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

### Initialization Options

To pass initialization options directly to the Laravel LSP server, you may configure `lsp.laravel.initialization_options`:

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

## Force-Updating the Laravel LSP

The extension checks for Laravel LSP updates at most once every two hours. To force an update check without waiting, delete the update-check timestamp file from the extension's work directory:

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

Then, run `editor: restart language server` from the command palette. The extension will immediately check for the latest `laravel/lsp` release and download it if a newer version is available.
