#!/usr/bin/env bash
# Install ModSecurity (nginx) + OWASP CRS + ClamAV upload inspection for your-domain.example
# Designed so account create/delete POSTs are NOT blocked by SQLi/XSS CRS noise.
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
NGX_SITE=""
MODSEC_DIR="/etc/nginx/modsec"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi

for cand in /etc/nginx/sites-enabled/your-domain.example.conf /etc/nginx/sites-enabled/your-domain.example /etc/nginx/sites-enabled/default; do
  if [[ -f "$cand" ]] && grep -q 'your-domain.example\|root /var/www/your-domain.example' "$cand" 2>/dev/null; then
    NGX_SITE="$cand"
    break
  fi
done
# fallback: first site that points at the app
if [[ -z "$NGX_SITE" ]]; then
  for cand in /etc/nginx/sites-enabled/*; do
    if grep -q '/var/www/your-domain.example' "$cand" 2>/dev/null; then
      NGX_SITE="$cand"
      break
    fi
  done
fi
[[ -n "$NGX_SITE" ]] || { echo "nginx site for your-domain.example not found" >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
  libnginx-mod-http-modsecurity \
  modsecurity-crs \
  clamav clamav-daemon clamav-freshclam \
  python3 >/dev/null

mkdir -p "$MODSEC_DIR" /var/log/modsec/audit /var/cache/modsecurity /var/lib/panel-security-shield/modsec
chmod 750 /var/log/modsec /var/cache/modsecurity

install -m 0755 "$SRC_DIR/bin/modsec-clamav-inspect.sh" /usr/local/bin/modsec-clamav-inspect.sh
install -m 0755 "$SRC_DIR/bin/modsec-danger-log.sh" /usr/local/bin/modsec-danger-log.sh
install -m 0755 "$SRC_DIR/bin/modsec-danger-harvest.sh" /usr/local/bin/modsec-danger-harvest.sh

install -m 0644 "$SRC_DIR/files/modsecurity.conf" "$MODSEC_DIR/modsecurity.conf"
install -m 0644 "$SRC_DIR/files/crs-setup-panel.conf" "$MODSEC_DIR/crs-setup-panel.conf"
install -m 0644 "$SRC_DIR/files/panel-exclusions.conf" "$MODSEC_DIR/panel-exclusions.conf"
install -m 0644 "$SRC_DIR/files/panel-clamav.conf" "$MODSEC_DIR/panel-clamav.conf"
install -m 0644 "$SRC_DIR/files/panel-danger-log.conf" "$MODSEC_DIR/panel-danger-log.conf"

# Prefer Debian/Ubuntu package CRS setup (sets tx.crs_setup_version)
if [[ -f /etc/modsecurity/crs/crs-setup.conf ]]; then
  CRS_SETUP="/etc/modsecurity/crs/crs-setup.conf"
elif [[ -f /usr/share/modsecurity-crs/crs-setup.conf ]]; then
  CRS_SETUP="/usr/share/modsecurity-crs/crs-setup.conf"
else
  echo "CRS setup file not found" >&2
  exit 1
fi

RULES=""
for cand in \
  /usr/share/modsecurity-crs/rules \
  /usr/share/modsecurity-crs/coreruleset/rules \
  /etc/modsecurity/crs/rules
do
  if [[ -d "$cand" ]] && ls "$cand"/*.conf >/dev/null 2>&1; then
    RULES="$cand"
    break
  fi
done
[[ -n "$RULES" ]] || { echo "CRS rules directory not found" >&2; exit 1; }

cat >"$MODSEC_DIR/main.conf" <<EOF
Include ${MODSEC_DIR}/modsecurity.conf
Include ${CRS_SETUP}
Include ${MODSEC_DIR}/crs-setup-panel.conf
Include ${MODSEC_DIR}/panel-exclusions.conf
Include ${MODSEC_DIR}/panel-clamav.conf
Include ${RULES}/*.conf
Include ${MODSEC_DIR}/panel-danger-log.conf
EOF
echo "CRS setup=$CRS_SETUP rules=$RULES"

# Point ClamAV inspect path inside panel-clamav.conf (already absolute)

systemctl enable clamav-freshclam >/dev/null 2>&1 || true
systemctl start clamav-freshclam >/dev/null 2>&1 || true
systemctl enable clamav-daemon >/dev/null 2>&1 || true
systemctl restart clamav-daemon >/dev/null 2>&1 || true

# Move any previous bak copies OUT of sites-enabled (nginx loads every file there)
mkdir -p /root/nginx-site-backups
mv -f /etc/nginx/sites-enabled/*.bak* /root/nginx-site-backups/ 2>/dev/null || true

# Wire into nginx site (idempotent)
if ! grep -q 'modsecurity on;' "$NGX_SITE"; then
  cp -a "$NGX_SITE" "/root/nginx-site-backups/$(basename "$NGX_SITE").pre-modsec.$(date +%Y%m%d%H%M%S)"
  if grep -q 'root /var/www/your-domain.example/public;' "$NGX_SITE"; then
    sed -i '/root \/var\/www\/your-domain.example\/public;/a\    modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsec/main.conf;' "$NGX_SITE"
  else
    sed -i '/server_name /a\    modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsec/main.conf;' "$NGX_SITE"
  fi
fi

# danger.log must be writable by nginx/www-data (inspectFile runs as worker)
touch /var/log/modsec/danger.log /var/log/modsec/audit.log
chown root:www-data /var/log/modsec /var/log/modsec/danger.log /var/log/modsec/audit.log
chmod 775 /var/log/modsec
chmod 664 /var/log/modsec/danger.log /var/log/modsec/audit.log

cat >/etc/logrotate.d/modsec-panel <<'EOF'
/var/log/modsec/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    create 640 root adm
    sharedscripts
    postrotate
        [ -f /var/run/nginx.pid ] && kill -USR1 "$(cat /var/run/nginx.pid)" || true
    endscript
}
EOF

cat >/etc/cron.d/modsec-danger-harvest <<'EOF'
*/2 * * * * root /usr/local/bin/modsec-danger-harvest.sh >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/modsec-danger-harvest

if [[ ! -e /etc/nginx/modules-enabled/50-mod-http-modsecurity.conf ]]; then
  if [[ -f /usr/share/nginx/modules-available/mod-http-modsecurity.conf ]]; then
    ln -sf /usr/share/nginx/modules-available/mod-http-modsecurity.conf \
      /etc/nginx/modules-enabled/50-mod-http-modsecurity.conf
  fi
fi

echo "==> nginx -t (site=$NGX_SITE)"
if ! nginx -t; then
  echo "nginx config FAILED — rolling back modsecurity site lines" >&2
  sed -i '/modsecurity on;/d;/modsecurity_rules_file/d' "$NGX_SITE" || true
  nginx -t || true
  exit 1
fi

systemctl reload nginx
/usr/local/bin/modsec-danger-harvest.sh || true

echo "==> Smoke"
curl -fsS -o /dev/null -w "HOME %{http_code}\n" https://your-domain.example/ || true
curl -fsS -o /dev/null -w "LOGIN %{http_code}\n" https://your-domain.example/login || true
iptables -L OUTPUT -n | head -1 || true
echo "OK: ModSecurity installed."
