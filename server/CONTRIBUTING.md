# Contributing

## Installation

```
git clone https://github.com/laravel/lsp.git
cd lsp
composer install
```

To see available commands:

`./server list`

## Xdebug debugging

The long-running LSP process can be debugged using the [control_socket](https://xdebug.org/docs/all_settings#control_socket) setting, available in Xdebug 3.3 and later. The **“Pause PHP process (Xdebug Control Socket)”** command allows you to temporarily pause the application on the next iteration and set breakpoints without restarting the entire process.
