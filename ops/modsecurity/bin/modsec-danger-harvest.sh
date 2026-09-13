#!/usr/bin/env bash
# Parse ModSecurity audit log for CRITICAL / malware / RCE / LFI only.
# Writes compact danger.jsonl and optionally bans repeat offenders via panel-security-shield.
# Never bans loopback / private / this server's own public IP.
set -euo pipefail

AUDIT="${MODSEC_AUDIT_LOG:-/var/log/modsec/audit.log}"
DANGER="${MODSEC_DANGER_LOG:-/var/log/modsec/danger.log}"
STATE_DIR="/var/lib/panel-security-shield/modsec"
OFFSET_FILE="$STATE_DIR/audit.offset"
SHIELD="/usr/local/sbin/panel-security-shield"
mkdir -p "$STATE_DIR" "$(dirname "$DANGER")"
touch "$DANGER"
[[ -f "$AUDIT" ]] || exit 0

OFFSET=0
[[ -f "$OFFSET_FILE" ]] && OFFSET=$(cat "$OFFSET_FILE" 2>/dev/null || echo 0)
SIZE=$(stat -c%s "$AUDIT" 2>/dev/null || echo 0)
if [[ "$SIZE" -lt "$OFFSET" ]]; then OFFSET=0; fi

SELF_IPS="$(hostname -I 2>/dev/null || true)"

python3 - "$AUDIT" "$OFFSET" "$DANGER" "$OFFSET_FILE" "$SELF_IPS" <<'PY'
import json, os, re, sys, time, socket
audit, offset, danger, offset_file, self_ips = sys.argv[1:6]
offset = int(offset or 0)
safe = {"127.0.0.1", "::1"}
for part in (self_ips or "").split():
    safe.add(part.strip())
for host in ("your-domain.example", "localhost"):
    try:
        safe.add(socket.gethostbyname(host))
    except Exception:
        pass

def is_safe(ip: str) -> bool:
    if not ip or ip == "-":
        return True
    if ip in safe:
        return True
    if ip.startswith(("10.", "192.168.", "172.16.", "172.17.", "172.18.", "172.19.", "172.2", "172.30.", "172.31.")):
        return True
    return False

with open(audit, "rb") as f:
    f.seek(offset)
    chunk = f.read().decode("utf-8", "replace")
    new_offset = f.tell()

parts = re.split(r"(?=--[0-9a-fA-F]+-A--)", chunk)
interesting = re.compile(
    r"(malware|clamav|webshell|rce|lfi|trojan|critical|anomaly score exceeded|Remote Command|Local File Inclusion|PHP Injection|Dangerous upload|Malware upload)",
    re.I,
)
ip_re = re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}\b")
uri_re = re.compile(r"\"([A-Z]+) ([^ ]+) HTTP/")
msg_re = re.compile(r"\[msg \"([^\"]+)\"\]")
id_re = re.compile(r"\[id \"(\d+)\"\]")
sev_re = re.compile(r"\[severity \"([^\"]+)\"\]")

out_lines = []
ban_ips = {}
for section in parts:
    if not section.strip() or not interesting.search(section):
        continue
    if re.search(r"\[id \"92(0|1)\d{3}\"\]", section) and not re.search(r"malware|clamav|webshell|rce|lfi|Dangerous upload|Malware upload", section, re.I):
        sev = sev_re.search(section)
        if not sev or sev.group(1).upper() not in ("CRITICAL", "ERROR"):
            continue
    ip = "-"
    m = re.search(r"\[client ([0-9.]+)\]", section) or ip_re.search(section)
    if m:
        ip = m.group(1) if m.lastindex else m.group(0)
    uri = "-"
    um = uri_re.search(section)
    if um:
        uri = um.group(2)[:200]
    msgs = [x.group(1) for x in msg_re.finditer(section)]
    msg = ",".join(msgs)[:300] or "dangerous L7 hit"
    rid = ",".join(x.group(1) for x in list(id_re.finditer(section))[:5])
    sev = (sev_re.search(section).group(1) if sev_re.search(section) else "CRITICAL")
    action = "deny" if re.search(r"Access denied|deny,", section, re.I) else "detect"
    rec = {
        "ts": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "type": "modsec",
        "ip": ip,
        "uri": uri,
        "msg": msg,
        "rule_ids": rid,
        "severity": sev,
        "action": action,
    }
    out_lines.append(json.dumps(rec, ensure_ascii=False))
    if action == "deny" and not is_safe(ip) and re.search(r"malware|clamav|webshell|rce|Remote Command|Dangerous upload|Malware upload", section, re.I):
        ban_ips[ip] = ban_ips.get(ip, 0) + 1

if out_lines:
    with open(danger, "a", encoding="utf-8") as df:
        df.write("\n".join(out_lines) + "\n")

with open(offset_file, "w") as of:
    of.write(str(new_offset))

for ip, n in ban_ips.items():
    if n >= 1 and not is_safe(ip):
        print(ip)
PY

if [[ -x "$SHIELD" ]]; then
  while read -r ip; do
    [[ -z "$ip" ]] && continue
    "$SHIELD" ban "$ip" 86400 >/dev/null 2>&1 || true
  done
fi
