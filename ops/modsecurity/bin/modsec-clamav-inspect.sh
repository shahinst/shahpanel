#!/usr/bin/env bash
# ModSecurity @inspectFile helper — stdout ONLY when malware is found.
# Empty stdout = clean (operator does not match).
set -u

FILE="${1:-}"
DANGER_LOG="${MODSEC_DANGER_LOG:-/var/log/modsec/danger.log}"
mkdir -p "$(dirname "$DANGER_LOG")"

[[ -n "$FILE" && -f "$FILE" ]] || exit 0

scan() {
  if command -v clamdscan >/dev/null 2>&1 && [[ -S /var/run/clamav/clamd.ctl || -S /run/clamav/clamd.ctl ]]; then
    clamdscan --fdpass --no-summary "$FILE" 2>/dev/null
    return $?
  fi
  if command -v clamscan >/dev/null 2>&1; then
    clamscan --no-summary "$FILE" 2>/dev/null
    return $?
  fi
  return 0
}

OUT="$(scan)" || true
RC=$?

# clamdscan: 0 clean, 1 infected, 2 error
if [[ $RC -eq 1 ]] || echo "$OUT" | grep -Eqi 'FOUND|Infected files: [1-9]'; then
  TS="$(date -Is)"
  MSG="$(echo "$OUT" | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g' | cut -c1-500)"
  printf '{"ts":"%s","type":"clamav","file":"%s","msg":"%s","action":"deny"}\n' \
    "$TS" "$(basename "$FILE")" "$MSG" >> "$DANGER_LOG" 2>/dev/null || true
  # Non-empty stdout => ModSecurity @inspectFile MATCH => deny rule fires
  echo "ClamAV malware detected: $MSG"
fi

exit 0
