#!/usr/bin/env bash
# Append a compact danger event (called from harvest / ClamAV). Not used via ModSec exec.
set -euo pipefail
DANGER_LOG="${MODSEC_DANGER_LOG:-/var/log/modsec/danger.log}"
mkdir -p "$(dirname "$DANGER_LOG")"
# args: type ip uri msg action
printf '{"ts":"%s","type":"%s","ip":"%s","uri":"%s","msg":"%s","action":"%s"}\n' \
  "$(date -Is)" \
  "${1:-unknown}" \
  "${2:--}" \
  "${3:--}" \
  "$(echo "${4:--}" | tr '\n' ' ' | cut -c1-400)" \
  "${5:-log}" >> "$DANGER_LOG"
