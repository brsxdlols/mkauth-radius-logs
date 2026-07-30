#!/bin/sh
set -eu

VERSION=4.3.5
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/addons/radius"
MKAUTH_ROOT="${MKAUTH_ROOT:-/opt/mk-auth}"
ADMIN_DIR="${MKAUTH_ADMIN:-$MKAUTH_ROOT/admin}"
ADDONS_DIR="$ADMIN_DIR/addons"
TARGET_DIR="$ADDONS_DIR/radius"
CORE_ADDONS_CLASS="$MKAUTH_ROOT/include/addons.inc.hhvm"
WEB_USER="${MKAUTH_WEB_USER:-www-data}"
LOG_FILE="${RADIUS_LOG_FILE:-}"
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

find_radius_log() {
    if [ -n "$LOG_FILE" ] && [ -f "$LOG_FILE" ]; then
        printf '%s\n' "$LOG_FILE"
        return 0
    fi

    for file in \
        /var/log/freeradius/radius.log \
        /var/log/freeradius/freeradius.log \
        /var/log/freeradius/radiusd.log \
        /var/log/radius/radius.log \
        /var/log/radius/radiusd.log
    do
        if [ -f "$file" ]; then
            printf '%s\n' "$file"
            return 0
        fi
    done

    return 1
}

can_web_user_read() {
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$WEB_USER" -- test -r "$1"
    else
        su -s /bin/sh -c "test -r '$1'" "$WEB_USER"
    fi
}

grant_log_access() {
    file=$1
    log_dir=$(dirname "$file")

    if can_web_user_read "$file"; then
        echo "Log: $WEB_USER ja possui acesso de leitura a $file"
        return 0
    fi

    if command -v setfacl >/dev/null 2>&1; then
        acl_path=$log_dir
        while [ "$acl_path" != "/" ]; do
            setfacl -m "u:$WEB_USER:--x" "$acl_path"
            acl_path=$(dirname "$acl_path")
        done
        setfacl -m "u:$WEB_USER:r--" "$file"
        setfacl -m "d:u:$WEB_USER:r-X" "$log_dir"
    else
        log_group=$(stat -c %G "$file" 2>/dev/null || true)
        case "$log_group" in
            ""|root|adm|sudo|shadow) ;;
            *)
                usermod -a -G "$log_group" "$WEB_USER"
                chmod g+x "$log_dir"
                chmod g+r "$file"

                if command -v systemctl >/dev/null 2>&1; then
                    systemctl reload apache2 2>/dev/null || true
                    systemctl reload php*-fpm 2>/dev/null || true
                fi
                ;;
        esac
    fi

    if can_web_user_read "$file"; then
        echo "Log: acesso de leitura concedido a $WEB_USER em $file"
        return 0
    fi

    return 1
}

consolidate_radius_shortcut() {
    menu_file=$1
    menu_tmp=$(mktemp /tmp/mkauth-radius-addon-js.XXXXXX)

    awk '
        BEGIN {
            count = 0
            last = 0
        }
        {
            line = tolower($0)
            trimmed = line
            sub(/^[[:space:]]+/, "", trimmed)

            if (index(line, "radius logs inicio") > 0) {
                next
            }
            if (index(line, "radius logs fim") > 0) {
                next
            }
            if (trimmed !~ /^\/\// && index(line, "add_menu.") > 0 && index(line, "addons/radius") > 0) {
                next
            }

            lines[++count] = $0
            if ($0 !~ /^[[:space:]]*$/) {
                last = count
            }
        }
        END {
            for (i = 1; i <= last; i++) {
                print lines[i]
            }
        }
    ' "$menu_file" > "$menu_tmp"

    cat "$menu_tmp" > "$menu_file"
    rm -f "$menu_tmp"

    cat >> "$menu_file" <<'MENU_SNIPPET'

// RADIUS LOGS INICIO
add_menu.provedor('{"plink": "' + minha_url + 'addons/radius/", "ptext": "<b>Radius Logs</b>"}');
// RADIUS LOGS FIM
MENU_SNIPPET
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
LOG_FILE=$(find_radius_log || true)

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

chown -R "root:$WEB_USER" "$TARGET_DIR" 2>/dev/null || true
find "$TARGET_DIR" -type d -exec chmod 0755 {} \;
find "$TARGET_DIR" -type f -exec chmod 0644 {} \;

if [ -n "$ADDON_JS" ]; then
    consolidate_radius_shortcut "$ADDON_JS"
    echo "Menu: atalhos Radius consolidados em um unico item."
else
    warn "addon.js nao encontrado; registre manualmente o atalho para addons/radius/"
fi

php -l "$TARGET_DIR/index.php" >/dev/null
php -l "$TARGET_DIR/radius_lib.php" >/dev/null
php -l "$TARGET_DIR/logs_data.php" >/dev/null
php -l "$TARGET_DIR/run_script.hhvm" >/dev/null

if [ -z "$LOG_FILE" ]; then
    warn "nenhum arquivo de log conhecido do FreeRADIUS foi encontrado"
elif ! id "$WEB_USER" >/dev/null 2>&1; then
    warn "usuario do servidor web nao encontrado: $WEB_USER"
elif ! grant_log_access "$LOG_FILE"; then
    warn "$WEB_USER nao consegue ler $LOG_FILE; instale o pacote acl ou ajuste RADIUS_LOG_FILE"
fi

echo "Instalacao concluida."
echo "Versao: $VERSION"
echo "Addon: $TARGET_DIR"
echo "Backup: $BACKUP_DIR"
if [ -n "$ADDON_JS" ]; then
    echo "Menu: $ADDON_JS"
fi
