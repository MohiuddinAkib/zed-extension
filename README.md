# Laravel Zed Extension

This is a local development Zed extension for Laravel.

## Installation

In Zed, run `zed: install dev extension` and select:

```sh
/path/to/zed-extension
```

## Configuration

Configure the server path in Zed settings:

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
- The configured Laravel LSP server path must be executable.

This first version is intentionally local-only. It does not download the server binary or bundle PHP/Blade grammars.
