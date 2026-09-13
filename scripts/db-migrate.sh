#!/usr/bin/env bash
#
# ============================================================================
#  انتقال دیتابیس بین دو سرور — بک‌آپ / ارسال / ریستور
# ============================================================================

set -Eeuo pipefail

readonly C_GREEN='\033[0;32m'
readonly C_RED='\033[0;31m'
readonly C_BLUE='\033[0;34m'
readonly C_YELLOW='\033[1;33m'
readonly C_RESET='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKUP_DIR="${APP_ROOT}/storage/app/backups/database"
ENV_FILE="${APP_ROOT}/.env"
SEND_TARGET=""
RESTORE_FILE=""
USE_LATEST=false
FORCE_PHP=false

log()  { echo -e "${C_BLUE}[*]${C_RESET} $*"; }
ok()   { echo -e "${C_GREEN}[✓]${C_RESET} $*"; }
warn() { echo -e "${C_YELLOW}[!]${C_RESET} $*"; }
err()  { echo -e "${C_RED}[✗]${C_RESET} $*" >&2; }
die()  { err "$*"; exit 1; }

usage() {
    cat <<'HELP'

استفاده:
  db-migrate.sh backup [--send user@host:/remote/dir/] [--php]
  db-migrate.sh send   user@host:/remote/dir/ [--file backup.sql]
  db-migrate.sh restore [--file name] [--latest]

  --php   فقط بک‌آپ Laravel (کند — پیش‌فرض: mysqldump سریع)

HELP
}

parse_args() {
    local cmd="${1:-}"
    shift || true

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --send)
                SEND_TARGET="${2:-}"
                [[ -n "$SEND_TARGET" ]] || die "بعد از --send مقصد scp را بنویسید."
                shift 2
                ;;
            --file)
                RESTORE_FILE="${2:-}"
                [[ -n "$RESTORE_FILE" ]] || die "بعد از --file مسیر فایل را بنویسید."
                shift 2
                ;;
            --latest) USE_LATEST=true; shift ;;
            --php)    FORCE_PHP=true; shift ;;
            -h|--help) usage; exit 0 ;;
            *)
                if [[ "$cmd" == "send" && -z "$SEND_TARGET" ]]; then
                    SEND_TARGET="$1"; shift; continue
                fi
                if [[ "$cmd" == "restore" && -z "$RESTORE_FILE" && "$USE_LATEST" != "true" ]]; then
                    RESTORE_FILE="$1"; shift; continue
                fi
                die "آرگومان ناشناخته: $1"
                ;;
        esac
    done
}

require_project() {
    [[ -f "${APP_ROOT}/artisan" ]] || die "artisan یافت نشد: ${APP_ROOT}"
    [[ -f "$ENV_FILE" ]] || die ".env یافت نشد: ${ENV_FILE}"
    mkdir -p "$BACKUP_DIR"
    chmod 775 "$BACKUP_DIR" 2>/dev/null || true
}

load_db_env() {
    eval "$(php -r "
        \$file = '${ENV_FILE}';
        \$vars = ['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD'];
        \$values = [];
        foreach (file(\$file, FILE_IGNORE_NEW_LINES) as \$line) {
            \$line = trim(\$line);
            if (\$line === '' || str_starts_with(\$line, '#') || ! str_contains(\$line, '=')) continue;
            [\$k, \$v] = explode('=', \$line, 2);
            \$k = trim(\$k); \$v = trim(\$v);
            if ((str_starts_with(\$v, '\"') && str_ends_with(\$v, '\"')) || (str_starts_with(\$v, \"'\") && str_ends_with(\$v, \"'\"))) {
                \$v = substr(\$v, 1, -1);
            }
            \$values[\$k] = \$v;
        }
        foreach (\$vars as \$key) {
            echo 'export '. \$key . '=' . escapeshellarg(\$values[\$key] ?? '') . PHP_EOL;
        }
    ")"
    DB_CONNECTION="${DB_CONNECTION:-mysql}"
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
}

find_bin() {
    local name="$1"
    if command -v "$name" >/dev/null 2>&1; then
        command -v "$name"
        return 0
    fi
    for p in "/usr/bin/$name" "/usr/local/bin/$name" "/usr/local/mysql/bin/$name"; do
        if [[ -x "$p" ]]; then
            echo "$p"
            return 0
        fi
    done
    return 1
}

test_db_connection() {
    load_db_env
    local mysql_bin
    mysql_bin="$(find_bin mysql || true)"

    log "بررسی اتصال به دیتابیس..."
    log "  host=${DB_HOST}  db=${DB_DATABASE}  user=${DB_USERNAME}"

    if [[ "$DB_CONNECTION" == "sqlite" ]]; then
        [[ -f "$DB_DATABASE" ]] || die "فایل SQLite یافت نشد: ${DB_DATABASE}"
        ok "فایل SQLite موجود است."
        return 0
    fi

    [[ -n "$mysql_bin" ]] || die "mysql client یافت نشد."

    if ! MYSQL_PWD="$DB_PASSWORD" "$mysql_bin" \
        --connect-timeout=10 \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        -e "SELECT 1" "$DB_DATABASE" >/dev/null 2>&1; then
        die "اتصال به MySQL ناموفق. .env را بررسی کنید."
    fi
    ok "اتصال MySQL برقرار است."
}

start_progress_watch() {
    local target_file="$1"
    (
        while [[ ! -f "$target_file" ]]; do sleep 2; done
        while true; do
            if [[ -f "$target_file" ]]; then
                local bytes
                bytes="$(stat -c%s "$target_file" 2>/dev/null || stat -f%z "$target_file" 2>/dev/null || echo 0)"
                local mb
                mb="$(awk "BEGIN {printf \"%.1f\", ${bytes}/1024/1024}")"
                echo -e "${C_BLUE}[~]${C_RESET} در حال نوشتن... ${mb} MB"
            fi
            sleep 5
        done
    ) &
    echo $!
}

run_mysqldump_backup() {
    local mysqldump_bin outfile watcher_pid
    mysqldump_bin="$(find_bin mysqldump || die "mysqldump یافت نشد. از --php استفاده کنید یا mysql-client نصب کنید.")"

    outfile="${BACKUP_DIR}/db-backup-$(date +%Y-%m-%d-%H%M%S).sql"
    log "بک‌آپ سریع با mysqldump..."
    warn "برای دیتابیس بزرگ چند دقیقه طول می‌کشد — هر ۵ ثانیه حجم فایل نمایش داده می‌شود."

    watcher_pid="$(start_progress_watch "$outfile")"

    set +e
    MYSQL_PWD="$DB_PASSWORD" "$mysqldump_bin" \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --single-transaction \
        --routines \
        --triggers \
        --set-gtid-purged=OFF \
        "$DB_DATABASE" >"$outfile"
    local rc=$?
    set -e

    kill "$watcher_pid" 2>/dev/null || true
    wait "$watcher_pid" 2>/dev/null || true

    [[ $rc -eq 0 && -s "$outfile" ]] || { rm -f "$outfile"; die "mysqldump ناموفق (کد ${rc})."; }
    echo "$outfile"
}

run_sqlite_backup() {
    local outfile="${BACKUP_DIR}/db-backup-$(date +%Y-%m-%d-%H%M%S).sqlite"
    cp "$DB_DATABASE" "$outfile"
    echo "$outfile"
}

run_artisan_backup() {
    log "بک‌آپ با Laravel (PHP — برای DB بزرگ کند است و تا پایان خروجی ندارد)..."
    warn "اگر mysqldump دارید، بدون --php اجرا کنید."
    cd "$APP_ROOT"
    php -d max_execution_time=0 -d memory_limit=512M artisan backup:database -v
}

latest_backup_file() {
    local f=""
    f="$(find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-backup-*.sql' -o -name 'db-backup-*.sqlite' \) -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)"
    [[ -n "$f" && -f "$f" ]] || return 1
    echo "$f"
}

resolve_backup_file() {
    local input="${1:-}"
    if [[ -z "$input" ]]; then
        latest_backup_file || die "بک‌آپی در ${BACKUP_DIR} نیست."
        return
    fi
    if [[ -f "$input" ]]; then echo "$input"; return; fi
    if [[ -f "${BACKUP_DIR}/$(basename "$input")" ]]; then
        echo "${BACKUP_DIR}/$(basename "$input")"
        return
    fi
    die "فایل یافت نشد: ${input}"
}

print_backup_info() {
    local file="$1" size_mb
    size_mb="$(awk "BEGIN {printf \"%.2f\", $(stat -c%s "$file" 2>/dev/null || stat -f%z "$file")/1024/1024}")"
    ok "بک‌آپ آماده:"
    echo "    ${file}"
    echo "    ${size_mb} MB"
}

cmd_backup() {
    require_project
    test_db_connection

    local backup_file=""

    if [[ "$FORCE_PHP" == "true" ]]; then
        run_artisan_backup
        backup_file="$(latest_backup_file || die "artisan فایلی نساخت.")"
    elif [[ "$DB_CONNECTION" == "sqlite" ]]; then
        backup_file="$(run_sqlite_backup)"
    elif find_bin mysqldump >/dev/null 2>&1; then
        backup_file="$(run_mysqldump_backup)"
    else
        warn "mysqldump نیست — fallback به Laravel (کند)..."
        run_artisan_backup
        backup_file="$(latest_backup_file || die "بک‌آپ ناموفق.")"
    fi

    print_backup_info "$backup_file"

    if [[ -n "$SEND_TARGET" ]]; then
        cmd_send "$backup_file"
    else
        echo
        log "انتقال:"
        echo "  sudo bash scripts/db-migrate.sh send root@IP_جدید:${BACKUP_DIR}/"
        echo "  scp \"${backup_file}\" root@IP_جدید:${BACKUP_DIR}/"
    fi
}

cmd_send() {
    local file="${1:-}"
    require_project
    [[ -n "$SEND_TARGET" ]] || die "مقصد send مشخص نیست."

    if [[ -z "$file" ]]; then
        file="$(resolve_backup_file "${RESTORE_FILE:-}")"
    fi
    [[ -f "$file" ]] || die "فایل نیست: $file"

    log "ارسال $(basename "$file") ..."
    scp "$file" "${SEND_TARGET}/"
    ok "ارسال شد."
    echo "  cd /var/www/دامنه && sudo bash scripts/db-migrate.sh restore --file $(basename "$file")"
}

run_mysql_import() {
    local file="$1" mysql_bin
    mysql_bin="$(find_bin mysql || die "mysql client یافت نشد.")"
    log "ریستور با mysql..."
    MYSQL_PWD="$DB_PASSWORD" "$mysql_bin" \
        --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" \
        "$DB_DATABASE" <"$file"
}

cmd_restore() {
    require_project
    test_db_connection

    local file=""
    if [[ "$USE_LATEST" == "true" || -z "$RESTORE_FILE" ]]; then
        file="$(latest_backup_file || die "بک‌آپی در ${BACKUP_DIR} نیست.")"
    else
        file="$(resolve_backup_file "$RESTORE_FILE")"
    fi

    print_backup_info "$file"
    chown www-data:www-data "$file" 2>/dev/null || true

    if [[ "$DB_CONNECTION" == "sqlite" ]]; then
        cp "$file" "$DB_DATABASE"
    elif find_bin mysql >/dev/null 2>&1; then
        run_mysql_import "$file"
    else
        cd "$APP_ROOT"
        php -d max_execution_time=0 artisan backup:restore "$file" --force --no-interaction
    fi

    cd "$APP_ROOT"
    php artisan config:clear >/dev/null 2>&1 || true
    php artisan cache:clear >/dev/null 2>&1 || true
    ok "ریستور تمام شد."
}

main() {
    [[ "$(id -u)" -eq 0 ]] && [[ -n "${SUDO_USER:-}" || -n "${SUDO_UID:-}" ]] && \
        warn "sudo hostname warning بی‌ضرر است — می‌توانید نادیده بگیرید."

    local cmd="${1:-}"
    [[ -n "$cmd" ]] || { usage; exit 1; }
    shift || true
    parse_args "$cmd" "$@"
    case "$cmd" in
        backup)  cmd_backup ;;
        send)    cmd_send "" ;;
        restore) cmd_restore ;;
        *) usage; die "دستور نامعتبر: ${cmd}" ;;
    esac
}

main "$@"
