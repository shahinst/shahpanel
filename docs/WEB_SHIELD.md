# Web Shield (Imunify-like)

Layered host protection for the live VPN panel.

## Stack

| Component | Role | Safe for panel? |
|-----------|------|-----------------|
| CrowdSec + firewall bouncer | IP decisions from nginx/ssh logs | Yes — INPUT bans only |
| fail2ban | SSH brute-force only | Yes — HTTP jails disabled |
| ClamAV | Scheduled / on-demand malware scan | Yes — filesystem |
| **ModSecurity + OWASP CRS** | L7 upload malware + dangerous-event logging | **Yes — panel POST exclusions** |
| `panel-security-shield` | Root helper + Imunify-like admin UI | Yes |
| Existing `panel-firewall` | Login IP blocks (PANEL-FW) | Unchanged |

### ModSecurity policy (critical)

- **Engine On**, paranoia level **1**, raised anomaly threshold
- **ClamAV `@inspectFile`** on multipart uploads → block webshell/malware immediately
- Dangerous extension / double-extension uploads blocked
- For `/admin|/agent|/seller|/api|/login` routes: **CRS SQLi/XSS/injection tags removed** so WireGuard keys, passwords, and account create/delete bodies are not false-blocked
- Audit is **RelevantOnly**; compact **danger.log** is harvested every 2 minutes into Web Shield (and malware uploaders can be banned)

**Not enabled:** CrowdSec AppSec full body WAF, ModSecurity blocking of normal panel form fields.

## Install

```bash
# Base shield (CrowdSec/fail2ban/ClamAV/helper):
bash /var/www/your-domain.example/ops/security-shield/install-security-shield.sh \
  /var/www/your-domain.example/ops/security-shield/panel-security-shield

# ModSecurity + ClamAV upload guard:
bash /var/www/your-domain.example/ops/modsecurity/install-modsecurity.sh
```

## Admin UI

Settings → **فایروال سرور (Web Shield)**

Tabs: overview, decisions/bans, whitelist, malware scan, logs (includes ModSecurity danger), services.

## Safety rules

- Never ban `127.0.0.1` / whitelisted IPs
- Never stop nginx from the panel
- Never touch OUTPUT / FORWARD
- Scans only under `/var/www/your-domain.example` (and `/tmp`)
- Login firewall + Web Shield coexist; whitelist can sync from DB `ip_whitelist`
