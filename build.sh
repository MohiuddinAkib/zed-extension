#!/bin/bash
set -e

echo "==> Building Laravel Zed Extension (WASM Component)..."
cargo build --release --target wasm32-wasip2

DEST="$HOME/Library/Application Support/Zed/extensions/installed/laravel-akib"
mkdir -p "$DEST"

cp target/wasm32-wasip2/release/zed_laravel.wasm "$DEST/extension.wasm"
cp extension.toml "$DEST/extension.toml"

echo "==> Packaging custom server into $DEST/server..."
rm -rf "$DEST/server"
mkdir -p "$DEST/server"
cp -R server/app "$DEST/server/"
cp -R server/bootstrap "$DEST/server/"
cp -R server/config "$DEST/server/"
cp -R server/vendor "$DEST/server/"
if [ -f "server/server" ]; then
    cp server/server "$DEST/server/"
fi
if [ -f "server/artisan" ]; then
    cp server/artisan "$DEST/server/"
fi
cp server/composer.json "$DEST/server/"

echo "==> Successfully installed extension and custom server to $DEST"
echo "==> Please reload Zed."
