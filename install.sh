#!/usr/bin/env bash
#
# ShahPanel installer — Ubuntu 22.04 / 24.04.
#
#   sudo bash install.sh                      interactive: asks for a domain
#   sudo bash install.sh panel.example.com    install on a domain, with Let's Encrypt
#   sudo bash install.sh --ip                 install on this server's IP, self-signed TLS
#
# Optional flags:
#   --ip                  no domain: serve on the server IP with a self-signed certificate
#   --with-phpmyadmin     install phpMyAdmin behind a random URL
#   --with-security       install the CrowdSec/fail2ban security shield
#   --no-ssl              plain HTTP only (not recommended)
#   --yes                 never prompt; take the defaults
#
# There is no web installer. Everything happens here.
#
# Everything printed is mirrored to $LOG except the generated passwords, which
# go straight to the console on fd 3 so they never land in a file.

set -Eeuo pipefail

# ---------------------------------------------------------------------------
# configuration
# ---------------------------------------------------------------------------

APP_NAME="shahpanel"
APP_TITLE="ShahPanel"
APP_REPO_URL="https://github.com/shahinst/shahpanel"
APP_YOUTUBE_URL="https://www.youtube.com/@shaahinst"

APP_DIR="${APP_DIR:-/var/www/${APP_NAME}}"
REPO="${REPO:-${APP_REPO_URL}.git}"
BRANCH="${BRANCH:-master}"

DB_NAME="${DB_NAME:-${APP_NAME}}"
DB_USER="${DB_USER:-${APP_NAME}}"

ADMIN_USER="${ADMIN_USER:-admin}"
EMAIL="${EMAIL:-}"

PHP_VER="8.3"
LOG="/var/log/${APP_NAME}-install.log"
CRED_FILE="${APP_DIR}/storage/app/INSTALL_CREDENTIALS.txt"
SSL_DIR="/etc/ssl/${APP_NAME}"

WITH_PMA=0
WITH_SECURITY=0
WITH_SSL=1
ASSUME_YES=0
MODE=""          # "domain" or "ip"
DOMAIN=""

# ---------------------------------------------------------------------------
# argument parsing
# ---------------------------------------------------------------------------

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ip)              MODE="ip" ;;
        --with-phpmyadmin) WITH_PMA=1 ;;
        --with-security)   WITH_SECURITY=1 ;;
        --no-ssl)          WITH_SSL=0 ;;
        -y|--yes)          ASSUME_YES=1 ;;
        -h|--help)
            sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        -*) echo "unknown flag: $1" >&2; exit 1 ;;
        *)  DOMAIN="$1"; MODE="domain" ;;
    esac
    shift
done

[[ ${EUID:-$(id -u)} -eq 0 ]] || {
    echo "Run this as root:  sudo bash install.sh" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# logging
# ---------------------------------------------------------------------------

touch "$LOG"
chmod 600 "$LOG"

# fd 3 stays attached to the real console, so secret() bypasses the tee below.
exec 3>&1
exec > >(tee -a "$LOG") 2>&1

CLEANUP_FILES=()

cleanup() {
    local file
    for file in "${CLEANUP_FILES[@]:-}"; do
        [[ -n "$file" && -e "$file" ]] && rm -f "$file"
    done
    return 0
}
trap cleanup EXIT

on_error() {
    local line="$1"
    echo
    echo "════════════════════════════════════════════════════════════"
    echo "  Install failed at line ${line}."
    echo "  Full log: ${LOG}"
    echo
    echo "  Nothing was removed. Fix the problem and run the installer"
    echo "  again — it refuses to touch an install that already finished."
    echo "════════════════════════════════════════════════════════════"
}
trap 'on_error $LINENO' ERR

# Pads a line to the fixed inner width of the welcome box, so the border stays
# square whatever the repo URL happens to be.
box_line() {
    local text="     $1" pad
    pad=$(( 58 - ${#text} ))
    (( pad < 0 )) && pad=0
    secret "$(printf '  │%s%*s│' "$text" "$pad" '')"
}

# Prints how long the step that just ended took, so a slow apt or composer run
# reads as finished work rather than as a hang.
STEP_NAME=""
STEP_T0=0
step() {
    if [[ -n "$STEP_NAME" ]]; then
        echo "    done in $(( $(date +%s) - STEP_T0 ))s"
    fi
    STEP_NAME="$*"
    STEP_T0="$(date +%s)"
    echo
    echo "==> $*"
}
info()   { echo "    $*"; }
warn()   { echo "    [!] $*"; }
secret() { printf '%s\n' "$*" >&3; }
die()    { echo "    [x] $*" >&2; exit 1; }

run() { info "\$ $*"; "$@"; }

# Prompts go to the real console (fd 3) and read from it, so the tee pipeline
# never swallows them.
ask() {
    local prompt="$1" default="${2:-}" reply=""
    if [[ $ASSUME_YES -eq 1 || ! -t 0 ]]; then
        printf '%s\n' "$default"
        return 0
    fi
    printf '%s' "$prompt" >&3
    read -r reply </dev/tty || reply=""
    printf '%s\n' "${reply:-$default}"
}

# Masks a token inside a URL before it reaches the log.
mask() { sed -E 's#(https://)[^@/]+@#\1***TOKEN***@#g'; }

# ---------------------------------------------------------------------------
# welcome
# ---------------------------------------------------------------------------

secret ""
secret "  ┌──────────────────────────────────────────────────────────┐"
secret "  │                                                          │"
secret "  │     ███████ ██   ██  █████  ██   ██                      │"
secret "  │     ██      ██   ██ ██   ██ ██   ██                      │"
secret "  │     ███████ ███████ ███████ ███████    P A N E L         │"
secret "  │          ██ ██   ██ ██   ██ ██   ██                      │"
secret "  │     ███████ ██   ██ ██   ██ ██   ██                      │"
secret "  │                                                          │"
box_line "VPN reseller and management panel"
box_line "${APP_REPO_URL}"
secret "  │                                                          │"
secret "  └──────────────────────────────────────────────────────────┘"
secret ""
secret "  This installer sets up everything on a clean Ubuntu server:"
secret "    PHP ${PHP_VER} · MySQL · Nginx · the panel · TLS · the scheduler"
secret ""
secret "  It takes 10-45 minutes (database migrations are slow on small"
secret "  servers) and asks at most one question."
secret ""

# ---------------------------------------------------------------------------
# step 1 — how should the panel be reached?
# ---------------------------------------------------------------------------

step "1/13  Checking the server"

if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    info "OS: ${PRETTY_NAME:-unknown}"
    [[ "${ID:-}" == "ubuntu" || "${ID_LIKE:-}" == *debian* ]] \
        || warn "This installer is tested on Ubuntu/Debian only — continuing anyway."
fi

[[ -e "$APP_DIR/.installed.lock" ]] && die "$APP_DIR is already installed. Use update.sh instead."
[[ -d "$APP_DIR/.git" ]] && die "$APP_DIR already holds a checkout. Remove it first, or set APP_DIR=…"

SERVER_IP="$(curl -fsS --max-time 10 https://api.ipify.org 2>/dev/null || echo '')"
[[ -n "$SERVER_IP" ]] || SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || echo '')"
[[ -n "$SERVER_IP" ]] || die "Could not determine this server's IP address."

info "Server IP: $SERVER_IP"

# Ask only when the mode was not already decided by an argument.
if [[ -z "$MODE" ]]; then
    secret ""
    secret "  Do you have a domain name pointed at this server ($SERVER_IP)?"
    secret ""
    secret "    • With a domain you get a real, trusted certificate"
    secret "      from Let's Encrypt — no browser warning."
    secret "    • Without one the panel is served on the IP with a"
    secret "      self-signed certificate. It works, but every browser"
    secret "      shows a warning the first time. You can add a domain later."
    secret ""

    answer="$(ask '  Domain (leave empty to use the IP): ' '')"
    answer="$(printf '%s' "$answer" | tr -d '[:space:]')"

    if [[ -n "$answer" ]]; then
        DOMAIN="$answer"
        MODE="domain"
    else
        MODE="ip"
    fi
fi

if [[ "$MODE" == "domain" ]]; then
    # Strip a pasted scheme or trailing slash — people paste URLs.
    DOMAIN="${DOMAIN#http://}"
    DOMAIN="${DOMAIN#https://}"
    DOMAIN="${DOMAIN%%/*}"

    # Someone without a domain will often paste the server's IP at the prompt.
    # Let's Encrypt cannot issue for a bare IP, so switch modes here rather
    # than letting certbot fail eleven steps later.
    if [[ "$DOMAIN" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
        warn "'$DOMAIN' is an IP address, not a domain name — using IP mode."
        MODE="ip"
        SERVER_IP="$DOMAIN"
    else
        # Every label must start and end alphanumeric and the TLD must be
        # alphabetic, so 'bad-.com' and 'panel example.com' are both rejected.
        [[ "$DOMAIN" =~ ^([A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}$ ]] \
            || die "'$DOMAIN' does not look like a domain name."
    fi
fi

# Resolve a domain the way the rest of the internet sees it.
#
# getent alone is wrong here: it reads /etc/hosts first, and a server whose
# own domain is mapped to 127.0.0.1 there — a very common setup — would look
# like it points somewhere else and lose its certificate for no reason.
# Certbot validates over public DNS, so ask public DNS. Fall back to the
# system resolver (ignoring loopback answers) when DNS-over-HTTPS is blocked.
resolve_a_record() {
    local host="$1" ip=''

    for doh in 'https://1.1.1.1/dns-query' 'https://dns.google/resolve'; do
        ip="$(curl -fsS --max-time 10 -H 'accept: application/dns-json' \
              "${doh}?name=${host}&type=A" 2>/dev/null \
              | grep -oE '"data":"[0-9]{1,3}(\.[0-9]{1,3}){3}"' \
              | head -1 | grep -oE '[0-9]{1,3}(\.[0-9]{1,3}){3}')"
        [[ -n "$ip" ]] && { printf '%s' "$ip"; return 0; }
    done

    getent ahostsv4 "$host" 2>/dev/null | awk '$1 !~ /^127\./ {print $1; exit}'
}

if [[ "$MODE" == "domain" ]]; then
    SERVER_NAME="$DOMAIN"
    DOMAIN_IP="$(resolve_a_record "$DOMAIN")"

    info "Domain:    $DOMAIN"
    info "Domain IP: ${DOMAIN_IP:-not resolving}"

    if [[ -z "$DOMAIN_IP" ]]; then
        warn "$DOMAIN does not resolve yet."
        warn "A certificate cannot be issued until the DNS A record exists."
        reply="$(ask '  Continue without SSL for now? [Y/n]: ' 'y')"
        [[ "$reply" =~ ^[Nn] ]] && die "Point the A record at $SERVER_IP, then run the installer again."
        WITH_SSL=0
    elif [[ "$DOMAIN_IP" != "$SERVER_IP" ]]; then
        warn "$DOMAIN points at $DOMAIN_IP, not at this server ($SERVER_IP)."
        warn "Certbot will fail until the A record is corrected."
        reply="$(ask '  Continue anyway? [y/N]: ' 'n')"
        [[ "$reply" =~ ^[Yy] ]] || die "Fix the DNS A record, then run the installer again."
        WITH_SSL=0
    fi
else
    SERVER_NAME="$SERVER_IP"
    info "No domain — the panel will be served on https://${SERVER_IP} with a self-signed certificate."
fi

# ---------------------------------------------------------------------------
# step 2 — packages
# ---------------------------------------------------------------------------

step "2/13  Installing system packages"

export DEBIAN_FRONTEND=noninteractive

run apt-get update
run apt-get install -y software-properties-common ca-certificates curl gnupg lsb-release openssl

if ! grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
    run add-apt-repository -y ppa:ondrej/php
    run apt-get update
fi

APT_PACKAGES=(
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" "php${PHP_VER}-mbstring"
    "php${PHP_VER}-xml" "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath"
    "php${PHP_VER}-gd" "php${PHP_VER}-intl"
    mysql-server nginx git unzip tar ipset iptables cron python3
)
[[ "$MODE" == "domain" ]] && APT_PACKAGES+=(certbot python3-certbot-nginx)

run apt-get install -y "${APT_PACKAGES[@]}"
run systemctl enable --now "php${PHP_VER}-fpm" nginx mysql cron

# ---------------------------------------------------------------------------
# step 3 — composer
# ---------------------------------------------------------------------------

step "3/13  Installing Composer"

if command -v composer >/dev/null 2>&1; then
    info "Composer already present: $(composer --version 2>/dev/null | head -1)"
elif apt-get install -y -qq composer 2>/dev/null && command -v composer >/dev/null 2>&1; then
    info "Installed Composer from apt."
else
    info "Falling back to getcomposer.org."
    run curl -fsS -o /tmp/composer-setup.php https://getcomposer.org/installer
    CLEANUP_FILES+=(/tmp/composer-setup.php)
    run "php${PHP_VER}" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
fi

export COMPOSER_ALLOW_SUPERUSER=1

# ---------------------------------------------------------------------------
# step 4 — source code
# ---------------------------------------------------------------------------

step "4/13  Fetching the application"

CLONE_URL="$REPO"
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
    CLONE_URL="$(printf '%s' "$REPO" | sed -E "s#^https://#https://${GITHUB_TOKEN}@#")"
    info "Using the supplied GITHUB_TOKEN for a private repository."
fi

mkdir -p "$(dirname "$APP_DIR")"

if ! git clone --progress --depth 1 --branch "$BRANCH" "$CLONE_URL" "$APP_DIR" 2>&1 | mask; then
    die "git clone failed — check the repository URL and GITHUB_TOKEN."
fi

# Strip the token back out so it is never stored in .git/config.
run git -C "$APP_DIR" remote set-url origin "$REPO"
run git -C "$APP_DIR" config core.fileMode false

step "5/13  Installing PHP dependencies"
run composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"

# ---------------------------------------------------------------------------
# step 6 — database
# ---------------------------------------------------------------------------

step "6/13  Creating the database"

DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

info "Database '${DB_NAME}' and user '${DB_USER}'@127.0.0.1 ready."

# ---------------------------------------------------------------------------
# step 7 — environment file
# ---------------------------------------------------------------------------

step "7/13  Writing .env"

ENV_FILE="$APP_DIR/.env"
cp "$APP_DIR/.env.example" "$ENV_FILE"

# Re-applied after every edit: the web user may read .env but never write it.
lock_env() {
    chown root:www-data "$ENV_FILE"
    chmod 640 "$ENV_FILE"
}

set_env() {
    local key="$1" value="$2"
    # Written through python rather than sed so that / and & inside a generated
    # password cannot be interpreted as replacement syntax.
    if grep -qE "^${key}=" "$ENV_FILE"; then
        python3 - "$ENV_FILE" "$key" "$value" <<'PY'
import sys
path, key, value = sys.argv[1], sys.argv[2], sys.argv[3]
out = []
for line in open(path, encoding='utf-8'):
    if line.split('=', 1)[0] == key:
        out.append(f'{key}={value}\n')
    else:
        out.append(line)
open(path, 'w', encoding='utf-8').writelines(out)
PY
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
    lock_env
}

# A random admin URL keeps the login form off every scanner's wordlist.
# PortalPaths::sanitizeSlug requires [a-z0-9-_], at least 3 characters, and
# rejects a handful of reserved words — 10 hex characters satisfies all of it.
ADMIN_PATH="p$(openssl rand -hex 5)"

if [[ $WITH_SSL -eq 1 ]]; then
    APP_URL="https://${SERVER_NAME}"
else
    APP_URL="http://${SERVER_NAME}"
fi

set_env APP_NAME         "$APP_TITLE"
set_env APP_ENV          "production"
set_env APP_DEBUG        "false"
set_env APP_URL          "$APP_URL"

set_env LOG_CHANNEL      "daily"
set_env LOG_LEVEL        "warning"
set_env LOG_DAILY_DAYS   "14"

set_env DB_CONNECTION    "mysql"
set_env DB_HOST          "127.0.0.1"
set_env DB_PORT          "3306"
set_env DB_DATABASE      "$DB_NAME"
set_env DB_USERNAME      "$DB_USER"
set_env DB_PASSWORD      "$DB_PASS"

set_env SESSION_DRIVER   "database"
set_env SESSION_ENCRYPT  "true"
set_env QUEUE_CONNECTION "database"
set_env CACHE_STORE      "file"

set_env VPN_ADMIN_PATH    "$ADMIN_PATH"
set_env SECURITY_FIREWALL "true"

# The application key is 32 random bytes, base64-encoded — byte for byte what
# `artisan key:generate` writes. It is generated here rather than through
# artisan so that every writer of .env runs as root: the file is root-owned and
# mode 640, so php running as www-data cannot rewrite it, and storage/ is not
# chowned until the next step, so such a run could not even log its own failure.
# set_env re-locks the file afterwards.
set_env APP_KEY "base64:$(openssl rand -base64 32)"

# ---------------------------------------------------------------------------
# step 8 — permissions and migrations
# ---------------------------------------------------------------------------

step "8/13  Setting permissions and running migrations"

chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
lock_env

run sudo -u www-data "php${PHP_VER}" "$APP_DIR/artisan" migrate --force

# ---------------------------------------------------------------------------
# step 9 — firewall helper
# ---------------------------------------------------------------------------

step "9/13  Installing the firewall helper"

FW_SRC="$APP_DIR/scripts/panel-firewall"
FW_DST="/usr/local/sbin/panel-firewall"

if [[ -f "$FW_SRC" ]]; then
    run install -o root -g root -m 0750 "$FW_SRC" "$FW_DST"

    # www-data may ask for changes, but cannot edit the lists it is filtered by.
    printf 'www-data ALL=(root) NOPASSWD: %s\n' "$FW_DST" > /etc/sudoers.d/panel-firewall
    chmod 0440 /etc/sudoers.d/panel-firewall

    if ! visudo -cf /etc/sudoers.d/panel-firewall >/dev/null; then
        rm -f /etc/sudoers.d/panel-firewall
        die "the generated sudoers file was rejected"
    fi

    run "$FW_DST" init

    info "Downloading country ranges (this may take a minute)…"
    if sudo -u www-data "php${PHP_VER}" "$APP_DIR/artisan" firewall:sync-country-data --skip-geo; then
        info "Country ranges loaded."
    else
        warn "Country data could not be downloaded; the firewall page will show an empty list."
        warn "Re-run later: php artisan firewall:sync-country-data"
    fi
else
    warn "scripts/panel-firewall is missing — the panel's firewall page will stay inert."
fi

# ---------------------------------------------------------------------------
# step 10 — nginx
# ---------------------------------------------------------------------------

step "10/13  Configuring nginx"

# The shared application block lives in a snippet so the HTTP and HTTPS server
# blocks cannot drift apart.
SNIPPET_APP="/etc/nginx/snippets/${APP_NAME}-app.conf"
mkdir -p /etc/nginx/snippets

cat > "$SNIPPET_APP" <<NGINX
root ${APP_DIR}/public;
index index.php;

charset utf-8;
client_max_body_size 32m;

add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Robots-Tag "noindex, nofollow" always;

# Declared before the generic PHP handler so nothing uploaded into storage can
# ever be executed.
location ~* ^/storage/.*\.(php|phar|phtml|php[0-9])\$ { deny all; }

# There is no web installer. maintain.php is deliberately left reachable
# because it is the recovery page for a panel that will not boot, but it
# answers 404 to everyone unless MAINTAIN_TOKEN is set in .env.
location = /install.php { deny all; }
location ^~ /install/   { deny all; }

location ~ /\.(?!well-known) { deny all; }

location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
}

location ~ \.php\$ {
    try_files \$uri =404;
    fastcgi_split_path_info ^(.+\.php)(/.+)\$;
    fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT \$realpath_root;
    fastcgi_hide_header X-Powered-By;
}

location ~* \.(css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|ico)\$ {
    expires 30d;
    access_log off;
    try_files \$uri =404;
}

error_page 404 /index.php;
NGINX

# The .conf suffix is what scripts/setup-phpmyadmin.sh looks for.
SITE_FILE="/etc/nginx/sites-available/${SERVER_NAME}.conf"

if [[ "$MODE" == "ip" && $WITH_SSL -eq 1 ]]; then
    step "10b   Generating a self-signed certificate"

    mkdir -p "$SSL_DIR"
    if [[ ! -f "$SSL_DIR/panel.crt" ]]; then
        run openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
            -keyout "$SSL_DIR/panel.key" -out "$SSL_DIR/panel.crt" \
            -subj "/CN=${SERVER_IP}" -addext "subjectAltName=IP:${SERVER_IP}"
    fi
    chmod 600 "$SSL_DIR/panel.key"
    chmod 644 "$SSL_DIR/panel.crt"

    cat > "$SITE_FILE" <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;

    ssl_certificate     ${SSL_DIR}/panel.crt;
    ssl_certificate_key ${SSL_DIR}/panel.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;

    include ${SNIPPET_APP};

    access_log /var/log/nginx/${APP_NAME}.access.log;
    error_log  /var/log/nginx/${APP_NAME}.error.log;
}
NGINX
else
    cat > "$SITE_FILE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};

    include ${SNIPPET_APP};

    access_log /var/log/nginx/${APP_NAME}.access.log;
    error_log  /var/log/nginx/${APP_NAME}.error.log;
}
NGINX
fi

ln -sfn "$SITE_FILE" "/etc/nginx/sites-enabled/${SERVER_NAME}.conf"
rm -f /etc/nginx/sites-enabled/default

run nginx -t
run systemctl reload nginx

# ---------------------------------------------------------------------------
# step 11 — TLS
# ---------------------------------------------------------------------------

step "11/13  Setting up TLS"

if [[ "$MODE" == "domain" && $WITH_SSL -eq 1 ]]; then
    CERTBOT_EMAIL_ARGS=(--register-unsafely-without-email)
    [[ -n "$EMAIL" ]] && CERTBOT_EMAIL_ARGS=(--email "$EMAIL")

    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
        "${CERTBOT_EMAIL_ARGS[@]}" --redirect; then
        info "Certificate issued and HTTP→HTTPS redirect enabled."
        APP_URL="https://${DOMAIN}"
        set_env APP_URL "$APP_URL"
        set_env SESSION_SECURE_COOKIE "true"
    else
        warn "Certbot failed. The panel is reachable over HTTP only."
        warn "Fix the DNS record, then run: certbot --nginx -d ${DOMAIN} --redirect"
        APP_URL="http://${DOMAIN}"
        set_env APP_URL "$APP_URL"
        WITH_SSL=0
    fi
elif [[ "$MODE" == "ip" && $WITH_SSL -eq 1 ]]; then
    # Let's Encrypt does issue certificates for bare IP addresses, but only under
    # the "shortlived" profile (~6 days) and only to clients that can request a
    # profile. Certbot still rejects IP identifiers outright, so acme.sh is used
    # here; it installs its own renewal cron, which the short lifetime requires.
    info "Requesting a Let's Encrypt certificate for ${SERVER_IP} (short-lived profile)."
    info "This replaces the self-signed certificate so browsers stop warning."

    ACME_HOME="/root/.acme.sh"

    if [[ ! -x "$ACME_HOME/acme.sh" ]]; then
        info "Installing acme.sh."
        if curl -fsS --max-time 60 -o /tmp/acme-install.sh https://get.acme.sh; then
            CLEANUP_FILES+=("/tmp/acme-install.sh")
            HOME=/root sh /tmp/acme-install.sh || warn "acme.sh installation failed."
        else
            warn "Could not download acme.sh."
        fi
    fi

    IP_CERT_OK=0

    if [[ -x "$ACME_HOME/acme.sh" ]]; then
        HOME=/root "$ACME_HOME/acme.sh" --set-default-ca --server letsencrypt || true

        if HOME=/root "$ACME_HOME/acme.sh" --issue -d "$SERVER_IP"                 --webroot "$APP_DIR/public"                 --server letsencrypt                 --keylength ec-256                 --cert-profile shortlived; then
            if HOME=/root "$ACME_HOME/acme.sh" --install-cert -d "$SERVER_IP" --ecc                     --key-file "${SSL_DIR}/panel.key"                     --fullchain-file "${SSL_DIR}/panel.crt"                     --reloadcmd "systemctl reload nginx"; then
                IP_CERT_OK=1
            fi
        fi
    fi

    if [[ $IP_CERT_OK -eq 1 ]]; then
        info "Trusted certificate installed for ${SERVER_IP}. Browsers will not warn."
        info "It lasts about six days; acme.sh renews it automatically four times a day."
    else
        warn "Could not obtain a Let's Encrypt certificate for this IP."
        warn "Keeping the self-signed certificate in ${SSL_DIR} — browsers will warn."
        warn "Retry later with:"
        warn "  ${ACME_HOME}/acme.sh --issue -d ${SERVER_IP} --webroot ${APP_DIR}/public \\"
        warn "      --server letsencrypt --keylength ec-256 --cert-profile shortlived"
    fi

    set_env SESSION_SECURE_COOKIE "true"
else
    info "Skipped — the panel is reachable over plain HTTP."
    warn "Session cookies are not protected. Add a domain and run certbot soon."
fi

# ---------------------------------------------------------------------------
# step 12 — scheduler and optional extras
# ---------------------------------------------------------------------------

step "12/13  Installing the scheduler"

cat > "/etc/cron.d/${APP_NAME}-scheduler" <<CRON
# ${APP_TITLE} scheduler — drives queued jobs, syncs, expiry and backups.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data cd ${APP_DIR} && php${PHP_VER} artisan schedule:run >> /dev/null 2>&1
CRON
chmod 0644 "/etc/cron.d/${APP_NAME}-scheduler"
info "Scheduler installed at /etc/cron.d/${APP_NAME}-scheduler"

if [[ $WITH_SECURITY -eq 1 ]]; then
    step "12b   Installing the security shield"
    SHIELD="$APP_DIR/ops/security-shield/install-security-shield.sh"
    if [[ -f "$SHIELD" ]]; then
        APP="$APP_DIR" bash "$SHIELD" "$APP_DIR/ops/security-shield/panel-security-shield" \
            || warn "The security shield installer reported an error; see $LOG."
    else
        warn "ops/security-shield/install-security-shield.sh not found — skipped."
    fi
fi

PMA_PATH=""
if [[ $WITH_PMA -eq 1 ]]; then
    step "12c   Installing phpMyAdmin"
    PMA_SCRIPT="$APP_DIR/scripts/setup-phpmyadmin.sh"
    if [[ -f "$PMA_SCRIPT" ]]; then
        if DOMAIN="$SERVER_NAME" bash "$PMA_SCRIPT" "$SERVER_NAME"; then
            # The shipped script serves it at the predictable /phpmyadmin/;
            # move it behind a random prefix so it is not trivially findable.
            PMA_PATH="db$(openssl rand -hex 5)"
            SNIPPET="/etc/nginx/snippets/shahpanel-phpmyadmin.conf"
            if [[ -f "$SNIPPET" ]]; then
                sed -i "s#/phpmyadmin/#/${PMA_PATH}/#g" "$SNIPPET"
                nginx -t && systemctl reload nginx
                info "phpMyAdmin moved to /${PMA_PATH}/"
            fi
        else
            warn "phpMyAdmin setup failed; see $LOG."
        fi
    else
        warn "scripts/setup-phpmyadmin.sh not found — skipped."
    fi
fi

# ---------------------------------------------------------------------------
# step 13 — finalise
# ---------------------------------------------------------------------------

step "13/13  Creating the administrator account"

if [[ -n "$EMAIL" ]]; then
    ADMIN_EMAIL="$EMAIL"
elif [[ "$MODE" == "domain" ]]; then
    ADMIN_EMAIL="admin@${DOMAIN}"
else
    # install:finalize validates this with FILTER_VALIDATE_EMAIL, which rejects
    # any domain without a dot -- so "admin@localhost" failed every IP-mode
    # install at the very last step. A bare IP is rejected too (it has to be
    # bracketed to pass), so use a dotted placeholder the admin can change later.
    ADMIN_EMAIL="admin@shahpanel.local"
fi

ADMIN_PASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-16)"

# The password travels in the environment, not in argv, so it never appears in
# `ps` output for any other user on the box.
export VPN_ADMIN_USERNAME="$ADMIN_USER"
export VPN_ADMIN_EMAIL="$ADMIN_EMAIL"
export VPN_ADMIN_PASSWORD="$ADMIN_PASS"
export VPN_ADMIN_NAME="Administrator"

sudo -u www-data \
    --preserve-env=VPN_ADMIN_USERNAME,VPN_ADMIN_EMAIL,VPN_ADMIN_PASSWORD,VPN_ADMIN_NAME \
    "php${PHP_VER}" "$APP_DIR/artisan" install:finalize \
        --admin-path="$ADMIN_PATH" \
        --site-name="$APP_TITLE" \
        --site-url="$APP_URL"

unset VPN_ADMIN_PASSWORD

# The lock file is what EnsureInstalled checks. config/shahpanel.php defines it
# as base_path('.installed.lock'); tinker is a dev dependency and is absent from
# a --no-dev install, so the path is resolved here instead of through artisan.
LOCK_FILE="$APP_DIR/.installed.lock"
sudo -u www-data touch "$LOCK_FILE"
info "Install lock written to $LOCK_FILE"

run sudo -u www-data "php${PHP_VER}" "$APP_DIR/artisan" optimize:clear

# ---------------------------------------------------------------------------
# credentials + smoke test
# ---------------------------------------------------------------------------

PANEL_URL="${APP_URL}/${ADMIN_PATH}"

umask 077
cat > "$CRED_FILE" <<CREDS
${APP_TITLE} — installed $(date -u '+%Y-%m-%d %H:%M:%S UTC')

Panel URL : ${PANEL_URL}
Username  : ${ADMIN_USER}
Password  : ${ADMIN_PASS}

Database  : ${DB_NAME}
DB user   : ${DB_USER}@127.0.0.1
DB pass   : ${DB_PASS}
CREDS
[[ -n "$PMA_PATH" ]] && echo "phpMyAdmin: ${APP_URL}/${PMA_PATH}/" >> "$CRED_FILE"
chown root:root "$CRED_FILE"
chmod 000 "$CRED_FILE"

step "Verifying the installation"

# -k because an IP install is deliberately serving a self-signed certificate.
# No -f: with it curl exits non-zero on a 4xx, the `||` branch fires *as well as*
# the -w output, and the two get concatenated into nonsense like "404000".
HTTP_CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 20 -L "$PANEL_URL" 2>/dev/null)"
[[ -z "$HTTP_CODE" ]] && HTTP_CODE="000"

case "$HTTP_CODE" in
    200|302) info "Panel responded with HTTP ${HTTP_CODE} — looks healthy." ;;
    000)     warn "Could not reach ${PANEL_URL} from the server itself." ;;
    *)       warn "Panel responded with HTTP ${HTTP_CODE}; check ${LOG} and the nginx error log." ;;
esac

# ---------------------------------------------------------------------------
# summary — printed on fd 3 only
# ---------------------------------------------------------------------------

secret ""
secret "════════════════════════════════════════════════════════════"
secret "  ${APP_TITLE} is installed."
secret "════════════════════════════════════════════════════════════"
secret ""
secret "  Panel URL : ${PANEL_URL}"
secret "  Username  : ${ADMIN_USER}"
secret "  Password  : ${ADMIN_PASS}"
secret ""
secret "  Database  : ${DB_NAME}"
secret "  DB user   : ${DB_USER}@127.0.0.1"
secret "  DB pass   : ${DB_PASS}"
[[ -n "$PMA_PATH" ]] && secret "  phpMyAdmin: ${APP_URL}/${PMA_PATH}/"
secret ""

# Which of these is true depends on whether step 11 got a certificate for the
# IP, so the summary has to branch -- it used to claim self-signed either way.
if [[ "$MODE" == "ip" && $WITH_SSL -eq 1 && ${IP_CERT_OK:-0} -eq 1 ]]; then
secret "  The certificate is a real Let's Encrypt one issued for this IP,"
secret "  so browsers will not warn. It is short-lived by design (about six"
secret "  days) and acme.sh renews it automatically four times a day."
secret ""
elif [[ "$MODE" == "ip" && $WITH_SSL -eq 1 ]]; then
secret "  The certificate is self-signed, so the browser will warn you"
secret "  the first time. Choose \"advanced\" and continue. To replace it"
secret "  with a trusted one later, point a domain at ${SERVER_IP} and run:"
secret "      certbot --nginx -d your-domain.com --redirect"
secret ""
fi

secret "  Write these down now. They are also in:"
secret "    ${CRED_FILE}   (root-only, mode 000)"
secret ""
secret "  The admin URL is random on purpose — bookmark it."
secret "  First thing to do: log in and change the password."
secret ""
secret "  Install log (no passwords): ${LOG}"
secret "  GitHub  : ${APP_REPO_URL}"
secret "  YouTube : ${APP_YOUTUBE_URL}"
secret "════════════════════════════════════════════════════════════"
secret ""

echo
echo "Install finished. Credentials were printed to the console only."
