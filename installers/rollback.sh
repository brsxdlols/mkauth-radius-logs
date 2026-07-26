#!/bin/sh
set -eu

MKAUTH_ROOT="${MKAUTH_ROOT:-/opt/mk-auth}"
ADMIN_DIR="${MKAUTH_ADMIN:-$MKAUTH_ROOT/admin}"
TARGET_DIR="$ADMIN_DIR/addons/radius"
BACKUP_DIR="${1:-}"

fail() {
    echo "ERRO: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -n "$BACKUP_DIR" ] || fail "informe o diretorio de backup"
[ -d "$BACKUP_DIR/radius" ] || fail "backup do addon nao encontrado em $BACKUP_DIR/radius"

case "$TARGET_DIR" in
    */admin/addons/radius) ;;
    *) fail "destino inesperado; rollback interrompido: $TARGET_DIR" ;;
esac

rm -rf "$TARGET_DIR"
cp -a "$BACKUP_DIR/radius" "$TARGET_DIR"

if [ -f "$BACKUP_DIR/addon.js" ]; then
    addon_js_target="$ADMIN_DIR/addons/addon.js"
    if [ -f "$BACKUP_DIR/addon_js.path" ]; then
        addon_js_target=$(sed -n '1p' "$BACKUP_DIR/addon_js.path")
    fi
    case "$addon_js_target" in
        "$ADMIN_DIR"/*/addon.js|"$ADMIN_DIR"/addon.js) ;;
        *) fail "caminho de addon.js invalido no backup: $addon_js_target" ;;
    esac
    cp -a "$BACKUP_DIR/addon.js" "$addon_js_target"
fi

php -l "$TARGET_DIR/index.php" >/dev/null
echo "Rollback concluido: $TARGET_DIR"
