#!/usr/bin/env bash
#
# ShahPanel uninstaller — Ubuntu 22.04 / 24.04.
#
#   sudo bash uninstall.sh                remove the panel, after one confirmation
#   sudo bash uninstall.sh --yes          no questions asked
#
# Optional flags:
#   --yes               never prompt
#   --no-backup         skip the farewell backup (you are throwing the data away)
#   --keep-database     leave the database and its user alone, for a reinstall
#                       that should find its accounts still there
#   --purge-packages    also apt-purge the extras install.sh added
#
# Removing the directory by hand is what leaves people stuck: the database, the
# cron entry, the nginx site, the sudoers rule and the firewall chain all stay
# behind, and the next install.sh walks into its own leftovers. This script
# takes out everything the installer put down, in the order that is safe, and
# leaves the machine able to install again from scratch.
#
# nginx, MySQL and PHP themselves are never touched: other sites on this server
# may live on them. The closing page says how to remove those by hand.
#
# Everything is mirrored to $LOG.

# Deliberately no `-e`. An uninstaller that stops at the first thing already
# missing produces exactly the half-removed state we are here to clean up, so
# every step tolerates absence and whatever genuinely fails is collected and
# reported at the end instead.
set -Euo pipefail

APP_NAME="shahpanel"
APP_TITLE="ShahPanel"

CRON_FILE="/etc/cron.d/${APP_NAME}-scheduler"
SSL_DIR="/etc/ssl/${APP_NAME}"
LOG="/var/log/${APP_NAME}-uninstall.log"

ASSUME_YES=0
DO_BACKUP=1
KEEP_DB=0
PURGE_PACKAGES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y)         ASSUME_YES=1 ;;
        --no-backup)      DO_BACKUP=0 ;;
        --keep-database)  KEEP_DB=1 ;;
        --purge-packages) PURGE_PACKAGES=1 ;;
        -h|--help)        sed -n '3,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown option: $1  (try --help)" >&2; exit 2 ;;
    esac
    shift
done

RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; BLU=$'\e[36m'; DIM=$'\e[2m'; BLD=$'\e[1m'; RST=$'\e[0m'

STEP_NO=0
step()  { STEP_NO=$((STEP_NO+1)); echo -e "\n${BLU}${BLD}[${STEP_NO}/10] $*${RST}"; }
ok()    { echo -e "  ${GRN}✓${RST} $*"; }
info()  { echo -e "  ${DIM}· $*${RST}"; }
warn()  { echo -e "  ${YLW}!${RST} $*"; }
die()   { echo -e "\n${RED}✗ $*${RST}\n" >&2; exit 1; }

# Whatever could not be removed, so the closing report is honest instead of a
# green tick over a machine that is still half-installed.
LEFTOVERS=()
note_leftover() { LEFTOVERS+=("$1"); warn "left behind: $1"; }

[[ $EUID -eq 0 ]] || die "run as root:  sudo bash uninstall.sh"

mkdir -p "$(dirname "$LOG")"
exec > >(tee -a "$LOG") 2>&1
echo "=== ${APP_TITLE} uninstall — $(date -Is) ==="

# ---------------------------------------------------------------------------
# find the installation
# ---------------------------------------------------------------------------

# The cron entry is the one file that records where the panel was put, so an
# install in a non-default directory is still found without being asked for.
if [[ -z "${APP_DIR:-}" && -f "$CRON_FILE" ]]; then
    APP_DIR="$(sed -n 's#.*cd \([^ ]*\) && php.*#\1#p' "$CRON_FILE" | head -1)"
fi
APP_DIR="${APP_DIR:-/var/www/${APP_NAME}}"

ENV_FILE="$APP_DIR/.env"

env_get() {
    [[ -f "$ENV_FILE" ]] || return 0
    sed -n "s/^$1=//p" "$ENV_FILE" | head -1 | sed 's/^"//; s/"$//; s/[[:space:]]*$//'
}

DB_NAME="$(env_get DB_DATABASE)"; DB_NAME="${DB_NAME:-$APP_NAME}"
DB_USER="$(env_get DB_USERNAME)"; DB_USER="${DB_USER:-$APP_NAME}"
APP_URL="$(env_get APP_URL)"
SERVER_NAME="$(printf '%s' "$APP_URL" | sed 's|^https\?://||; s|/.*||; s|:.*||')"

echo
echo "  directory : ${APP_DIR}$([[ -d $APP_DIR ]] || echo '  (already gone)')"
echo "  database  : ${DB_NAME}$([[ $KEEP_DB -eq 1 ]] && echo '  (will be kept)')"
echo "  address   : ${SERVER_NAME:-unknown}"

# ---------------------------------------------------------------------------
# confirmation
# ---------------------------------------------------------------------------

if [[ $ASSUME_YES -eq 0 ]]; then
    echo
    echo -e "${YLW}${BLD}This removes the panel, its database and every account in it.${RST}"
    [[ $DO_BACKUP -eq 1 ]] && echo -e "${DIM}A backup is taken first; its path is printed at the end.${RST}"
    echo
    read -rp "Type REMOVE to go ahead: " answer
    [[ "$answer" == "REMOVE" ]] || die "nothing was touched."
fi

# ---------------------------------------------------------------------------
# step 1 — stop the moving parts
# ---------------------------------------------------------------------------

step "Stopping the scheduler"

# Before the backup, not after: the scheduler runs every minute and would
# otherwise be syncing panels and writing rows into the database being dumped.
if [[ -f "$CRON_FILE" ]]; then
    rm -f "$CRON_FILE" && ok "removed $CRON_FILE" || note_leftover "$CRON_FILE"
else
    info "no scheduler entry found"
fi
rm -f /etc/cron.d/panel-security-shield

if [[ -d "$APP_DIR" ]]; then
    pkill -f "$APP_DIR/artisan" >/dev/null 2>&1 && info "stopped running artisan commands" || true
fi

# ---------------------------------------------------------------------------
# step 2 — farewell backup
# ---------------------------------------------------------------------------

BACKUP_FILE=""

if [[ $DO_BACKUP -eq 1 ]]; then
    step "Taking the farewell backup"

    TS="$(date +%Y%m%d-%H%M%S)"
    STAGE="$(mktemp -d /tmp/shahpanel-farewell-XXXXXX)"
    BACKUP_FILE="/root/${APP_NAME}-farewell-${TS}.tar.gz"

    if command -v mysqldump >/dev/null 2>&1 && mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        if mysqldump --protocol=socket -uroot --single-transaction --routines --events \
             "$DB_NAME" > "$STAGE/database.sql" 2>/dev/null; then
            ok "database dumped ($(du -h "$STAGE/database.sql" | cut -f1))"
        else
            rm -f "$STAGE/database.sql"
            warn "the database could not be dumped — it may already be gone"
        fi
    else
        warn "mysqldump is unavailable; the backup will hold files only"
    fi

    [[ -f "$ENV_FILE" ]] && cp -a "$ENV_FILE" "$STAGE/env"
    [[ -d "$APP_DIR/storage/app" ]] && cp -a "$APP_DIR/storage/app" "$STAGE/storage-app"
    [[ -n "$SERVER_NAME" && -f "/etc/nginx/sites-available/${SERVER_NAME}.conf" ]] \
        && cp -a "/etc/nginx/sites-available/${SERVER_NAME}.conf" "$STAGE/nginx-site.conf"

    if tar -czf "$BACKUP_FILE" -C "$STAGE" . 2>/dev/null; then
        chmod 600 "$BACKUP_FILE"
        ok "saved $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
    else
        BACKUP_FILE=""
        warn "the backup archive could not be written"
    fi

    rm -rf "$STAGE"
else
    step "Skipping the backup (--no-backup)"
fi

# ---------------------------------------------------------------------------
# step 3 — nginx
# ---------------------------------------------------------------------------

step "Removing the nginx site"

# Match on the snippet every generated site includes rather than on a domain
# name: the panel may have been installed on an IP, moved to a domain later, or
# set up by an older installer under a different file name.
SITES=()
while IFS= read -r site; do
    [[ -n "$site" ]] && SITES+=("$site")
done < <(grep -rls "${APP_NAME}-app.conf" /etc/nginx/sites-available 2>/dev/null)

for site in "${SITES[@]}"; do
    rm -f "$site" "/etc/nginx/sites-enabled/$(basename "$site")"
    ok "removed $(basename "$site")"
done
[[ ${#SITES[@]} -eq 0 ]] && info "no generated site file found"

rm -f "/etc/nginx/snippets/${APP_NAME}-app.conf" \
      "/etc/nginx/snippets/${APP_NAME}-phpmyadmin.conf"
find /etc/nginx/sites-enabled -xtype l -delete 2>/dev/null || true

# nginx will not start with an empty sites-enabled on some images, so put
# Ubuntu's own default back: removing the panel must not take the web server
# down with it.
if [[ -z "$(ls -A /etc/nginx/sites-enabled 2>/dev/null)" && -f /etc/nginx/sites-available/default ]]; then
    ln -sfn /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
    info "restored the default nginx site"
fi

if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx >/dev/null 2>&1 && ok "nginx reloaded"
else
    warn "nginx -t is unhappy; it was left running on its old configuration"
    note_leftover "nginx configuration (run: nginx -t)"
fi

# ---------------------------------------------------------------------------
# step 4 — certificates
# ---------------------------------------------------------------------------

step "Removing certificates"

if [[ -n "$SERVER_NAME" ]] && command -v certbot >/dev/null 2>&1; then
    if certbot certificates 2>/dev/null | grep -q "Certificate Name: ${SERVER_NAME}$"; then
        certbot delete --cert-name "$SERVER_NAME" --non-interactive >/dev/null 2>&1 \
            && ok "Let's Encrypt certificate for ${SERVER_NAME} deleted" \
            || note_leftover "certbot certificate ${SERVER_NAME}"
    else
        info "no certbot certificate for ${SERVER_NAME}"
    fi
fi

# An IP install gets its certificate from acme.sh, which installs a renewal cron
# of its own; --remove takes that away with the certificate.
ACME="/root/.acme.sh/acme.sh"
if [[ -n "$SERVER_NAME" && -x "$ACME" ]]; then
    HOME=/root "$ACME" --remove -d "$SERVER_NAME" --ecc >/dev/null 2>&1 \
        && ok "acme.sh certificate for ${SERVER_NAME} removed" || true
fi

rm -rf "$SSL_DIR"
ok "removed ${SSL_DIR}"

# ---------------------------------------------------------------------------
# step 5 — firewall helper
# ---------------------------------------------------------------------------

step "Reverting the firewall helper"

# The chain is jumped to from INPUT. Unlink it before destroying anything, or
# ipset refuses to drop sets that iptables still references — which is how a
# hand-rolled cleanup ends with the panel still silently dropping traffic.
if command -v iptables >/dev/null 2>&1; then
    while iptables -C INPUT -j PANEL_FW >/dev/null 2>&1; do
        iptables -D INPUT -j PANEL_FW >/dev/null 2>&1 || break
    done
    iptables -F PANEL_FW >/dev/null 2>&1 || true
    iptables -X PANEL_FW >/dev/null 2>&1 || true
    ok "PANEL_FW chain unlinked and dropped"
fi

# Every panel_* set, not just the three steady ones: an interrupted country
# sync leaves a panel_cn_tmp behind, and a set nobody destroys is a set the
# next install cannot recreate.
if command -v ipset >/dev/null 2>&1; then
    while IFS= read -r fwset; do
        [[ -n "$fwset" ]] && ipset destroy "$fwset" >/dev/null 2>&1
    done < <(ipset list -n 2>/dev/null | grep '^panel_')
    ok "panel ipsets destroyed"
fi

rm -f /usr/local/sbin/panel-firewall /etc/sudoers.d/panel-firewall
rm -rf /var/lib/panel-firewall
ok "helper, sudoers rule and country lists removed"

# ---------------------------------------------------------------------------
# step 6 — security shield
# ---------------------------------------------------------------------------

step "Removing the security shield"

SHIELD_FILES=(
    /usr/local/sbin/panel-security-shield
    /etc/sudoers.d/panel-security-shield
    /etc/fail2ban/jail.d/panel-sshd.local
    /etc/fail2ban/jail.d/panel-disable-http.local
)

SHIELD_FOUND=0
for f in "${SHIELD_FILES[@]}"; do
    if [[ -e "$f" ]]; then
        rm -f "$f"
        SHIELD_FOUND=1
    fi
done

if [[ $SHIELD_FOUND -eq 1 ]]; then
    ok "shield rules and helper removed"
    systemctl reload fail2ban >/dev/null 2>&1 || systemctl restart fail2ban >/dev/null 2>&1 || true
    # CrowdSec and fail2ban stay: they are general-purpose and the operator may
    # well want them for the rest of the machine. --purge-packages removes them.
    info "CrowdSec and fail2ban are still installed and running"
else
    info "the shield was never installed"
fi

rm -f /etc/phpmyadmin/conf.d/shahpanel-uri.php

# ---------------------------------------------------------------------------
# step 7 — database
# ---------------------------------------------------------------------------

if [[ $KEEP_DB -eq 1 ]]; then
    step "Keeping the database (--keep-database)"
    info "'${DB_NAME}' and '${DB_USER}' were left in place"
else
    step "Dropping the database"

    if mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        # The installer grants to '127.0.0.1'; older ones used 'localhost'. Drop
        # both, or the next install.sh inherits a user whose password is gone.
        if mysql --protocol=socket -uroot >/dev/null 2>&1 <<SQL
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
DROP USER IF EXISTS '${DB_USER}'@'127.0.0.1';
DROP USER IF EXISTS '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
        then
            ok "database '${DB_NAME}' and user '${DB_USER}' dropped"
        else
            note_leftover "database '${DB_NAME}' (drop it by hand)"
        fi
    else
        warn "cannot reach MySQL as root over the socket"
        note_leftover "database '${DB_NAME}'"
    fi
fi

# ---------------------------------------------------------------------------
# step 8 — the application itself
# ---------------------------------------------------------------------------

step "Removing ${APP_DIR}"

if [[ -d "$APP_DIR" ]]; then
    # Never rm -rf a path that resolves somewhere unexpected: a stray APP_DIR in
    # the environment must not be able to take out the whole of /var/www.
    RESOLVED="$(readlink -f "$APP_DIR")"
    case "$RESOLVED" in
        /|/var|/var/www|/home|/root|/etc|/usr|/srv|/opt)
            die "refusing to delete ${RESOLVED} — APP_DIR looks wrong" ;;
    esac

    rm -rf "$RESOLVED" && ok "removed $RESOLVED" || note_leftover "$RESOLVED"
else
    info "already gone"
fi

# Every panel log except the one being written right now — the closing page
# points people at it, so removing it takes away the only account of what
# just happened.
find /var/log -maxdepth 1 -name "${APP_NAME}-*.log" ! -name "$(basename "$LOG")" -delete 2>/dev/null
rm -rf "/var/backups/${APP_NAME}"
ok "installer logs and update backups removed"

# ---------------------------------------------------------------------------
# step 9 — optional package purge
# ---------------------------------------------------------------------------

if [[ $PURGE_PACKAGES -eq 1 ]]; then
    step "Purging the extra packages"

    export DEBIAN_FRONTEND=noninteractive
    # Only what install.sh pulled in for the panel's own sake. nginx, MySQL and
    # PHP are deliberately not in this list.
    apt-get purge -y -qq phpmyadmin crowdsec crowdsec-firewall-bouncer-iptables \
        fail2ban clamav-daemon clamav-freshclam certbot python3-certbot-nginx \
        >/dev/null 2>&1 || true
    apt-get autoremove -y -qq >/dev/null 2>&1 || true
    ok "extras purged"
else
    step "Leaving the shared packages alone"
    info "nginx, MySQL, PHP, CrowdSec and phpMyAdmin are untouched"
    info "add --purge-packages to remove the panel's extras too"
fi

# ---------------------------------------------------------------------------
# step 10 — report
# ---------------------------------------------------------------------------

step "Done"

echo
if [[ ${#LEFTOVERS[@]} -eq 0 ]]; then
    echo -e "  ${GRN}${BLD}${APP_TITLE} is gone. This server can install it again from scratch.${RST}"
else
    echo -e "  ${YLW}${BLD}${APP_TITLE} was removed, except for:${RST}"
    for item in "${LEFTOVERS[@]}"; do echo "    · $item"; done
fi

if [[ -n "$BACKUP_FILE" ]]; then
    echo
    echo -e "  ${BLD}Your data is in:${RST} ${BACKUP_FILE}"
    echo -e "  ${DIM}Copy it off this server before you rebuild — nothing else has it now.${RST}"
fi

echo
echo -e "  ${BLD}Install again${RST}"
echo "    curl -fsSLO https://raw.githubusercontent.com/shahinst/${APP_NAME}/master/install.sh"
echo "    sudo bash install.sh"
echo
echo -e "  ${BLD}Removing nginx, MySQL and PHP as well${RST}"
echo "    Only if nothing else on this server uses them:"
echo "    apt-get purge -y nginx nginx-common mysql-server 'php8.3-*'"
echo
echo "  Log: ${LOG}"
echo
