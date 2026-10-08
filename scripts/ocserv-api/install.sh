#!/usr/bin/env bash
#
# Connects an existing ocserv (OpenConnect / AnyConnect) server to ShahPanel.
#
# Installs the small HTTP API the panel talks to (scripts/ocserv-api/ocserv-api.py,
# Python standard library only), opens its port to the panel's IP alone and
# prints the values to enter in the panel under Servers > Add (type ocserv).
#
#   curl -fsSL https://raw.githubusercontent.com/shahinst/shahpanel/master/scripts/ocserv-api/install.sh \
#     | sudo bash -s -- --panel-ip <PANEL_IP>
#
# Options:
#   --panel-ip IP   the panel server's public IPv4 (required; repeat for more)
#   --port N        API port (default 9443)
#   --cert FILE     TLS certificate (default: ocserv's own server-cert)
#   --key FILE      TLS key (default: ocserv's own server-key)
#
# Running it again keeps the existing token and only updates the rest.

set -euo pipefail

SOURCE_URL="https://raw.githubusercontent.com/shahinst/shahpanel/master/scripts/ocserv-api/ocserv-api.py"
OCSERV_CONF=/etc/ocserv/ocserv.conf
CONFIG_DIR=/etc/ocserv-api
AGENT=/usr/local/sbin/ocserv-api
PORT=9443
CERT=""
KEY=""
PANEL_IPS=()

die() { echo "error: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --panel-ip) PANEL_IPS+=("${2:-}"); shift 2 ;;
        --port) PORT="${2:-}"; shift 2 ;;
        --cert) CERT="${2:-}"; shift 2 ;;
        --key) KEY="${2:-}"; shift 2 ;;
        *) die "unknown option: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run as root"
[ ${#PANEL_IPS[@]} -gt 0 ] || die "--panel-ip is required"
for ip in "${PANEL_IPS[@]}"; do
    [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || die "not an IPv4 address: $ip"
done
[[ "$PORT" =~ ^[0-9]+$ ]] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || die "bad port: $PORT"
[ -f "$OCSERV_CONF" ] || die "$OCSERV_CONF not found: install and configure ocserv first"
command -v ocpasswd >/dev/null && command -v occtl >/dev/null || die "ocpasswd/occtl not found"
command -v python3 >/dev/null || die "python3 not found"

# The panel manages users in ocserv's plain password file.
PASSWD_FILE=$(sed -nE 's/^\s*auth\s*=\s*"plain\[(passwd=)?([^],]+).*/\2/p' "$OCSERV_CONF" | head -1)
[ -n "$PASSWD_FILE" ] || die "ocserv must use plain authentication (auth = \"plain[passwd=/etc/ocserv/ocpasswd]\")"
touch "$PASSWD_FILE"

if [ -z "$CERT" ]; then CERT=$(sed -nE 's/^\s*server-cert\s*=\s*(\S+)/\1/p' "$OCSERV_CONF" | head -1); fi
if [ -z "$KEY" ]; then KEY=$(sed -nE 's/^\s*server-key\s*=\s*(\S+)/\1/p' "$OCSERV_CONF" | head -1); fi
[ -f "$CERT" ] && [ -f "$KEY" ] || die "TLS certificate or key not found; pass --cert and --key"

# Agent: next to this script when run from a checkout, otherwise from GitHub.
HERE=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)
if [ -n "$HERE" ] && [ -f "$HERE/ocserv-api.py" ]; then
    install -m 0750 "$HERE/ocserv-api.py" "$AGENT"
else
    curl -fsSL "$SOURCE_URL" -o "$AGENT.tmp" && install -m 0750 "$AGENT.tmp" "$AGENT" && rm -f "$AGENT.tmp"
fi

# Per-user session limits live in config-per-user files.
PER_USER_DIR=/etc/ocserv/config-per-user
mkdir -p "$PER_USER_DIR"
RESTART_OCSERV=0
if ! grep -qE '^\s*config-per-user\s*=' "$OCSERV_CONF"; then
    cp -a "$OCSERV_CONF" "$OCSERV_CONF.before-shahpanel"
    echo "config-per-user = $PER_USER_DIR/" >> "$OCSERV_CONF"
    RESTART_OCSERV=1
fi

mkdir -p "$CONFIG_DIR" && chmod 0700 "$CONFIG_DIR"
TOKEN=""
if [ -f "$CONFIG_DIR/config.json" ]; then
    TOKEN=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("token",""))' "$CONFIG_DIR/config.json")
fi
[ -n "$TOKEN" ] || TOKEN=$(python3 -c 'import secrets; print(secrets.token_hex(24))')

python3 - "$CONFIG_DIR/config.json" "$PORT" "$TOKEN" "$CERT" "$KEY" "$PASSWD_FILE" "$PER_USER_DIR" "$OCSERV_CONF" "${PANEL_IPS[@]}" <<'PY'
import json, os, sys
path, port, token, cert, key, passwd, per_user, conf, *allow = sys.argv[1:]
config = {"port": int(port), "username": "shahpanel", "token": token, "allow": allow,
          "cert": cert, "key": key, "passwd_file": passwd, "per_user_dir": per_user, "ocserv_conf": conf}
with open(path, "w") as handle:
    json.dump(config, handle, indent=2)
os.chmod(path, 0o600)
PY

cat > /etc/systemd/system/ocserv-api.service <<UNIT
[Unit]
Description=ShahPanel ocserv API
After=network-online.target ocserv.service

[Service]
ExecStart=/usr/bin/python3 $AGENT
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
[ "$RESTART_OCSERV" -eq 1 ] && systemctl restart ocserv
systemctl enable --now ocserv-api >/dev/null 2>&1
systemctl restart ocserv-api

# Only the panel may reach the API; the agent also refuses everyone else itself.
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
    for ip in "${PANEL_IPS[@]}"; do ufw allow from "$ip" to any port "$PORT" proto tcp >/dev/null; done
elif command -v iptables >/dev/null; then
    for ip in "${PANEL_IPS[@]}"; do
        iptables -C INPUT -s "$ip/32" -p tcp --dport "$PORT" -j ACCEPT 2>/dev/null \
            || iptables -I INPUT 1 -s "$ip/32" -p tcp --dport "$PORT" -j ACCEPT
    done
    if command -v netfilter-persistent >/dev/null; then netfilter-persistent save >/dev/null 2>&1 || true
    elif [ -d /etc/iptables ]; then iptables-save > /etc/iptables/rules.v4; fi
fi

sleep 1
systemctl is-active --quiet ocserv-api || die "ocserv-api did not start: journalctl -u ocserv-api"

cat <<DONE

ocserv-api is running. In the panel add a server of type "OpenConnect (ocserv)":
  Address:  $(hostname -f 2>/dev/null || hostname)  (or this server's IP)
  Port:     $PORT
  Username: shahpanel
  Password: $TOKEN

Keep "verify SSL" on only if the certificate is a public one (e.g. Let's Encrypt).
DONE
