#!/usr/bin/env bash
# Safe installer for CrowdSec + fail2ban + ClamAV + panel Web Shield helper.
# Does NOT install ModSecurity / body WAF. INPUT-only bans; OUTPUT untouched.
set -euo pipefail

APP="${APP:-/var/www/your-domain.example}"
HELPER_SRC="${1:-}"
HELPER_DST="/usr/local/sbin/panel-security-shield"
SUDOERS="/etc/sudoers.d/panel-security-shield"
STATE="/var/lib/panel-security-shield"

die() { echo "ERROR: $*" >&2; exit 1; }
need_root() { [[ "$(id -u)" -eq 0 ]] || die "run as root"; }

need_root

if [[ -z "$HELPER_SRC" || ! -f "$HELPER_SRC" ]]; then
  HELPER_SRC="$(cd "$(dirname "$0")" && pwd)/panel-security-shield"
fi
[[ -f "$HELPER_SRC" ]] || die "helper source not found: $HELPER_SRC"

export DEBIAN_FRONTEND=noninteractive

echo "==> Installing packages (CrowdSec, fail2ban, ClamAV)…"
apt-get update -qq

# CrowdSec official repo (idempotent)
if ! command -v cscli >/dev/null 2>&1; then
  apt-get install -y -qq curl gnupg apt-transport-https ca-certificates jq python3 >/dev/null
  curl -fsSL https://install.crowdsec.net | bash
fi

apt-get install -y -qq \
  crowdsec \
  crowdsec-firewall-bouncer-iptables \
  fail2ban \
  clamav \
  clamav-daemon \
  clamav-freshclam \
  jq \
  python3 \
  >/dev/null

echo "==> Configuring CrowdSec collections (nginx/ssh/linux)…"
# Hub collections — best effort; do not fail install if hub laggy
cscli hub update >/dev/null 2>&1 || true
cscli collections install crowdsecurity/linux >/dev/null 2>&1 || true
cscli collections install crowdsecurity/nginx >/dev/null 2>&1 || true
cscli collections install crowdsecurity/sshd >/dev/null 2>&1 || true

# Ensure nginx acquis if log exists
ACQUIS="/etc/crowdsec/acquis.yaml"
if [[ -f /var/log/nginx/access.log ]]; then
  if ! grep -q '/var/log/nginx/access.log' "$ACQUIS" 2>/dev/null; then
    cat >> "$ACQUIS" <<'YAML'

filenames:
  - /var/log/nginx/access.log
  - /var/log/nginx/error.log
labels:
  type: nginx
YAML
  fi
fi

# Whitelist localhost by default via helper init later
mkdir -p /etc/crowdsec/parsers/s02-enrich

echo "==> Configuring fail2ban (SSH only — never HTTP body)…"
mkdir -p /etc/fail2ban/jail.d
cat > /etc/fail2ban/jail.d/panel-sshd.local <<'EOF'
[sshd]
enabled = true
port    = ssh
logpath = %(sshd_log)s
backend = %(sshd_backend)s
maxretry = 5
findtime = 10m
bantime = 1h
ignoreip = 127.0.0.1/8 ::1
EOF

# Explicitly keep nginx/http jails off — panel auth / provisioning must not be fail2ban'd
cat > /etc/fail2ban/jail.d/panel-disable-http.local <<'EOF'
[nginx-http-auth]
enabled = false
[nginx-botsearch]
enabled = false
[nginx-badbots]
enabled = false
[apache-auth]
enabled = false
EOF

echo "==> Preparing ClamAV (freshclam + nightly scan cron)…"
systemctl enable clamav-freshclam >/dev/null 2>&1 || true
systemctl start clamav-freshclam >/dev/null 2>&1 || true
# clamav-daemon optional — clamscan works without daemon
systemctl enable clamav-daemon >/dev/null 2>&1 || true
systemctl start clamav-daemon >/dev/null 2>&1 || true

mkdir -p /etc/cron.d
cat > /etc/cron.d/panel-security-shield <<EOF
# Nightly malware scan of panel tree (nice'd, excludes vendor/node_modules via helper)
30 3 * * * root /usr/local/sbin/panel-security-shield scan-start ${APP} >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/panel-security-shield

echo "==> Installing helper + sudoers…"
install -m 0750 -o root -g root "$HELPER_SRC" "$HELPER_DST"
cat > "$SUDOERS" <<EOF
# Managed by install-security-shield.sh — www-data may only invoke the shield helper
www-data ALL=(root) NOPASSWD: ${HELPER_DST}
EOF
chmod 440 "$SUDOERS"
visudo -cf "$SUDOERS" >/dev/null || die "sudoers validation failed"

mkdir -p "$STATE/quarantine"
chmod 750 "$STATE"

# Auto-whitelist common safe sources if IpWhitelist can be read later from panel
"$HELPER_DST" init

echo "==> Registering CrowdSec firewall bouncer API key…"
BOUNCE_CFG=""
for cand in \
  /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml \
  /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml \
  /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml
do
  [[ -f "$cand" ]] && BOUNCE_CFG="$cand" && break
done
# Debian package path variants
for cand in /etc/crowdsec/bouncers/*.yaml /etc/crowdsec/bouncers/*.yaml; do
  [[ -f "${cand}" ]] || continue
  if grep -q 'api_key' "$cand" 2>/dev/null; then BOUNCE_CFG="$cand"; break; fi
done
if [[ -n "$BOUNCE_CFG" ]] && command -v cscli >/dev/null 2>&1; then
  systemctl stop crowdsec-firewall-bouncer >/dev/null 2>&1 || true
  # Ensure bouncer only touches INPUT (never OUTPUT / FORWARD)
  python3 - <<PY
from pathlib import Path
import re
p = Path("$BOUNCE_CFG")
text = p.read_text()
# force iptables mode when key exists
text = re.sub(r"(?m)^mode:\s*.*$", "mode: iptables", text)
# Keep OUTPUT out of chains if a list is present
text = re.sub(
    r"(?ms)^iptables_chains:.*?(?=^\S|\Z)",
    "iptables_chains:\n  - INPUT\n",
    text,
    count=1,
)
p.write_text(text)
PY
  NEWKEY="$(cscli bouncers add "panel-firewall-bouncer-$(date +%s)" -o raw 2>/dev/null || true)"
  if [[ -n "${NEWKEY:-}" ]]; then
    python3 - <<PY
from pathlib import Path
import re
p = Path("$BOUNCE_CFG")
text = p.read_text()
text2 = re.sub(r"(?m)^(\s*api_key:\s*).*$", r"\1${NEWKEY}", text)
if text2 == text:
    text2 = text.rstrip() + "\napi_key: ${NEWKEY}\n"
p.write_text(text2)
print("bouncer api_key written")
PY
  fi
fi

echo "==> Enabling CrowdSec + bouncer + fail2ban…"
systemctl enable crowdsec crowdsec-firewall-bouncer fail2ban >/dev/null 2>&1 || true
systemctl restart crowdsec >/dev/null 2>&1 || true
systemctl restart crowdsec-firewall-bouncer >/dev/null 2>&1 || true
systemctl restart fail2ban >/dev/null 2>&1 || true

# Verify nginx still OK
nginx -t >/dev/null
systemctl reload nginx

# Sanity: OUTPUT policy must still be ACCEPT (or not DROP)
OUT_POLICY=$(iptables -L OUTPUT -n 2>/dev/null | head -1 || true)
echo "OUTPUT chain: $OUT_POLICY"
if echo "$OUT_POLICY" | grep -qi 'policy DROP'; then
  echo "WARNING: OUTPUT policy is DROP — investigate; panel API egress may break."
fi

echo "==> Smoke status:"
"$HELPER_DST" status
echo "OK: security shield installed."
