#!/bin/sh
set -eu

REPOSITORY="${RADIUS_REPOSITORY:-brsxdlols/mkauth-radius-logs}"
BRANCH="${RADIUS_BRANCH:-main}"
TMP_DIR=$(mktemp -d)

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT INT TERM

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute como root." >&2
    exit 1
fi

if command -v curl >/dev/null 2>&1; then
    curl -fsSL "https://github.com/$REPOSITORY/archive/refs/heads/$BRANCH.tar.gz" \
        -o "$TMP_DIR/source.tar.gz"
elif command -v wget >/dev/null 2>&1; then
    wget -qO "$TMP_DIR/source.tar.gz" \
        "https://github.com/$REPOSITORY/archive/refs/heads/$BRANCH.tar.gz"
else
    echo "Instale curl ou wget para continuar." >&2
    exit 1
fi

tar -xzf "$TMP_DIR/source.tar.gz" -C "$TMP_DIR"
source_dir=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
[ -n "$source_dir" ] || {
    echo "Pacote do GitHub invalido." >&2
    exit 1
}

sh "$source_dir/installers/install.sh"
