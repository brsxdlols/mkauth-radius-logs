#!/bin/sh
set -eu

VERSION=4.5.0
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

[ "$(id -u)" -eq 0 ] || fail "execute como root"
[ -d "$ADMIN_DIR" ] || fail "diretorio administrativo do MK-Auth nao encontrado: $ADMIN_DIR"
[ -f "$CORE_ADDONS_CLASS" ] || fail "integracao de addons do MK-Auth nao encontrada: $CORE_ADDONS_CLASS"
[ -f "$SOURCE_DIR/index.php" ] || fail "pacote incompleto: index.php ausente"
[ -f "$SOURCE_DIR/logs_data.php" ] || fail "pacote incompleto: logs_data.php ausente"
[ -f "$SOURCE_DIR/client_target.php" ] || fail "pacote incompleto: client_target.php ausente"
[ -f "$SOURCE_DIR/client_links.php" ] || fail "pacote incompleto: client_links.php ausente"
[ -f "$SOURCE_DIR/client_status.php" ] || fail "pacote incompleto: client_status.php ausente"
[ -f "$SOURCE_DIR/client_status.js" ] || fail "pacote incompleto: client_status.js ausente"
[ -f "$SOURCE_DIR/compact_notice.js" ] || fail "pacote incompleto: compact_notice.js ausente"
[ -f "$SOURCE_DIR/history.php" ] || fail "pacote incompleto: history.php ausente"
[ -f "$SOURCE_DIR/live.php" ] || fail "pacote incompleto: live.php ausente"

for file in access.php standalone_access.php current_state.php preferences.php connection_settings_api.php connection_settings_model.php radius_log_reader.php package_features.json assets/connection_settings.js assets/connection_settings.css assets/radius_preferences.js; do
    [ -f "$SOURCE_DIR/$file" ] || fail "pacote incompleto: $file ausente"
done

case "$TARGET_DIR" in
    */admin/addons/radius) ;;
    *) fail "destino inesperado; instalacao interrompida: $TARGET_DIR" ;;
esac

LOG_FILE=$(find_radius_log || true)

command -v python3 >/dev/null 2>&1 || fail "python3 e necessario para instalar o menu e validar os arquivos"
mkdir -p "$BACKUP_ROOT"
BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/mkauth-radius-logs-$STAMP-v$VERSION-XXXXXX")
chmod 0700 "$BACKUP_DIR"
python3 "$SCRIPT_DIR/deploy.py" "$SOURCE_DIR" "$ADMIN_DIR" "$CORE_ADDONS_CLASS" "$BACKUP_DIR" "$WEB_USER" "$LOG_FILE"

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
echo "Menu: Provedor → LOG RADIUS (carregadores administrativos independentes)."
