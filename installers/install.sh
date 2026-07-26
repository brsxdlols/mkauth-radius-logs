#!/bin/sh
set -eu

VERSION=4.3.1
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/addons/radius"
MKAUTH_ROOT="${MKAUTH_ROOT:-/opt/mk-auth}"
ADMIN_DIR="${MKAUTH_ADMIN:-$MKAUTH_ROOT/admin}"
ADDONS_DIR="$ADMIN_DIR/addons"
TARGET_DIR="$ADDONS_DIR/radius"
CORE_ADDONS_CLASS="$MKAUTH_ROOT/include/addons.inc.hhvm"
LOG_FILE="${RADIUS_LOG_FILE:-/var/log/freeradius/radius.log}"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_ROOT="${MKAUTH_BACKUP_ROOT:-/root/backups}"
BACKUP_DIR="$BACKUP_ROOT/mkauth-radius-logs-$STAMP-v$VERSION"

fail() {
    echo "ERRO: $*" >&2
    exit 1
}

warn() {
    echo "AVISO: $*" >&2
}

find_addon_js() {
    for file in \
        "$ADDONS_DIR/addon.js" \
        "$ADMIN_DIR/scripts/addon.js" \
        "$ADMIN_DIR/addon.js" \
        "$ADMIN_DIR/assets/js/addon.js"
    do
        if [ -f "$file" ]; then
            printf '%s\n' "$file"
            return 0
        fi
    done

    found=$(find "$ADMIN_DIR" -type f -name addon.js 2>/dev/null | head -1)
    if [ -n "$found" ]; then
        printf '%s\n' "$found"
        return 0
    fi

    return 1
}

has_radius_shortcut() {
    awk '
        {
            line = tolower($0)
            trimmed = line
            sub(/^[[:space:]]+/, "", trimmed)

            if (trimmed !~ /^\/\// && trimmed !~ /^\/\*/ && trimmed !~ /^\*/ && index(line, "addons/radius/") > 0) {
                found = 1
                exit
            }
        }
        END { exit(found ? 0 : 1) }
    ' "$1"
}

[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -d "$ADMIN_DIR" ] || fail "diretorio administrativo do MK-Auth nao encontrado: $ADMIN_DIR"
[ -f "$CORE_ADDONS_CLASS" ] || fail "integracao de addons do MK-Auth nao encontrada: $CORE_ADDONS_CLASS"
[ -f "$SOURCE_DIR/index.php" ] || fail "pacote incompleto: index.php ausente"
[ -f "$SOURCE_DIR/logs_data.php" ] || fail "pacote incompleto: logs_data.php ausente"

case "$TARGET_DIR" in
    */admin/addons/radius) ;;
    *) fail "destino inesperado; instalacao interrompida: $TARGET_DIR" ;;
esac

ADDON_JS=$(find_addon_js || true)

mkdir -p "$BACKUP_DIR" "$ADDONS_DIR"
if [ -d "$TARGET_DIR" ]; then
    cp -a "$TARGET_DIR" "$BACKUP_DIR/radius"
fi
if [ -n "$ADDON_JS" ]; then
    cp -a "$ADDON_JS" "$BACKUP_DIR/addon.js"
    printf '%s\n' "$ADDON_JS" > "$BACKUP_DIR/addon_js.path"
fi

rm -rf "$TARGET_DIR"
mkdir -p "$TARGET_DIR"
cp -a "$SOURCE_DIR"/. "$TARGET_DIR"/
ln -s "$CORE_ADDONS_CLASS" "$TARGET_DIR/addons.class.php"

chown -R root:www-data "$TARGET_DIR" 2>/dev/null || true
find "$TARGET_DIR" -type d -exec chmod 0755 {} \;
find "$TARGET_DIR" -type f -exec chmod 0644 {} \;

if [ -n "$ADDON_JS" ] && has_radius_shortcut "$ADDON_JS"; then
    echo "Menu: atalho existente mantido; nenhuma duplicacao criada."
elif [ -n "$ADDON_JS" ]; then
    cat >> "$ADDON_JS" <<'MENU_SNIPPET'

// RADIUS LOGS INICIO
add_menu.provedor('{"plink": "' + minha_url + 'addons/radius/", "ptext": "<b>Radius Logs</b>"}');
// RADIUS LOGS FIM
MENU_SNIPPET
    echo "Menu: atalho Radius Logs criado."
else
    warn "addon.js nao encontrado; registre manualmente o atalho para addons/radius/"
fi

php -l "$TARGET_DIR/index.php" >/dev/null
php -l "$TARGET_DIR/radius_lib.php" >/dev/null
php -l "$TARGET_DIR/logs_data.php" >/dev/null
php -l "$TARGET_DIR/run_script.hhvm" >/dev/null

if command -v runuser >/dev/null 2>&1; then
    if ! runuser -u www-data -- test -r "$LOG_FILE"; then
        warn "www-data nao consegue ler $LOG_FILE; ajuste grupo/permissoes do FreeRADIUS"
    fi
elif [ ! -r "$LOG_FILE" ]; then
    warn "nao foi possivel confirmar a leitura de $LOG_FILE"
fi

echo "Instalacao concluida."
echo "Versao: $VERSION"
echo "Addon: $TARGET_DIR"
echo "Backup: $BACKUP_DIR"
if [ -n "$ADDON_JS" ]; then
    echo "Menu: $ADDON_JS"
fi
