#!/usr/bin/env bash
#
# phpMyAdmin روی مسیر /phpmyadmin/ (نه subdomain)
#   sudo bash scripts/setup-phpmyadmin.sh your-domain.example
#   sudo bash scripts/setup-phpmyadmin.sh   # دامنه از APP_URL در .env

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${APP_ROOT}/.env"
DOMAIN="${1:-}"
PHP_VER="${PHP_VER:-8.3}"
PHP_FPM_SOCKET="/run/php/php${PHP_VER}-fpm.sock"
SCHEME="https"

log() { echo "[*] $*"; }
ok()  { echo "[✓] $*"; }
die() { echo "[✗] $*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "با root اجرا کنید: sudo bash scripts/setup-phpmyadmin.sh"

if [[ -z "$DOMAIN" && -f "$ENV_FILE" ]]; then
    DOMAIN="$(grep -E '^APP_URL=' "$ENV_FILE" | head -1 | sed 's/^APP_URL=//; s/^"//; s/"$//; s|^https\?://||; s|/.*||')"
fi
[[ -n "$DOMAIN" ]] || die "دامنه را بدهید: bash scripts/setup-phpmyadmin.sh your-domain.example"

grep -q '^APP_URL=http://' "$ENV_FILE" 2>/dev/null && SCHEME="http"

log "دامنه: ${SCHEME}://${DOMAIN}/phpmyadmin/"

export DEBIAN_FRONTEND=noninteractive
if ! dpkg -l phpmyadmin >/dev/null 2>&1; then
    log "نصب phpMyAdmin..."
    apt-get update -qq
    apt-get install -y phpmyadmin php-mbstring php-zip php-gd php-json php-curl
fi

log "تنظیم آدرس phpMyAdmin..."
cat >/etc/phpmyadmin/conf.d/vpnpanel-uri.php <<PHP
<?php
\$cfg['PmaAbsoluteUri'] = '${SCHEME}://${DOMAIN}/phpmyadmin/';
PHP

log "ساخت snippet Nginx..."
cat >/etc/nginx/snippets/vpnpanel-phpmyadmin.conf <<NGINX
# phpMyAdmin — ${DOMAIN}/phpmyadmin/
location ^~ /phpmyadmin/ {
    root /usr/share/;
    index index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
NGINX

NGINX_SITE="/etc/nginx/sites-available/${DOMAIN}.conf"
[[ -f "$NGINX_SITE" ]] || NGINX_SITE="/etc/nginx/sites-enabled/${DOMAIN}.conf"
[[ -f "$NGINX_SITE" ]] || die "فایل Nginx یافت نشد: /etc/nginx/sites-available/${DOMAIN}.conf"

if ! grep -q 'vpnpanel-phpmyadmin.conf' "$NGINX_SITE"; then
    log "افزودن include به ${NGINX_SITE}..."
    sed -i '/client_max_body_size/i \    include snippets/vpnpanel-phpmyadmin.conf;' "$NGINX_SITE" || \
        sed -i '/root .*public;/a \    include snippets/vpnpanel-phpmyadmin.conf;' "$NGINX_SITE"
fi

# حذف subdomain قدیمی pma.*
rm -f /etc/nginx/sites-enabled/phpmyadmin.conf /etc/nginx/sites-available/phpmyadmin.conf 2>/dev/null || true

nginx -t || die "پیکربندی Nginx نامعتبر است."
systemctl reload nginx

ok "phpMyAdmin آماده: ${SCHEME}://${DOMAIN}/phpmyadmin/"
echo
echo "=== ورود phpMyAdmin (همان DB پنل) ==="
grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$ENV_FILE" | while IFS= read -r line; do
    key="${line%%=*}"
    val="${line#*=}"
    val="${val%\"}"; val="${val#\"}"
    echo "${key#DB_}: ${val}"
done
echo "Server در phpMyAdmin: 127.0.0.1"
echo "====================================="
