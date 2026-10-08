# OpenConnect (ocserv) Panel API

Base URL example: `https://ocserv1.example.com:9443`

## Install on an ocserv server (one command)

A ready implementation of this API ships in [`scripts/ocserv-api`](../scripts/ocserv-api)
(Python standard library only, no packages to install). On a server where
ocserv already runs with `plain` authentication:

```bash
curl -fsSL https://raw.githubusercontent.com/shahinst/shahpanel/master/scripts/ocserv-api/install.sh \
  | sudo bash -s -- --panel-ip <PANEL_IP>
```

It installs the `ocserv-api` systemd service, enables per-user session limits
(`config-per-user`), opens the API port to the panel IP only (ufw or iptables)
and prints the port, username and token to enter in the panel. Options:
`--port` (default 9443), `--cert` / `--key` (default: ocserv's own certificate),
`--panel-ip` may be repeated. Running it again keeps the token.

`GET /api/health` also returns `pool_size` (client addresses in ocserv's
`ipv4-netmask`) and `sessions` (connected users); the watchdog module warns
when the pool is almost full.

## Access

- Reachable only from the panel server IP (nftables + API `403` for others).
- Auth: HTTP Basic (`username` / API token as password) or Bearer token.
- TLS: valid Let's Encrypt — keep certificate verification on.
- Changes apply and persist immediately (no `write memory`).

## Panel provider

Server type: `ocserv` — **OpenConnect (ocserv)**  
Separate from Cisco ASA (`cisco_anyconnect`). Customers still use Cisco Secure Client / AnyConnect or OpenConnect.

| Setting | Notes |
|---|---|
| host / port | API host, default port `9443` |
| username / password | Panel Basic auth (password = API token, encrypted) |
| ocserv_vpn_address | Shown to customers (else host); VPN is port 443 |
| ocserv_default_max_sessions | 0–1000, default 1 |
| ocserv_group | Optional ocserv group name |
| ocserv_verify_ssl | Default true |

## Operations

| Panel action | API |
|---|---|
| Test connection | `GET /api/health` |
| Create | `POST /api/users` |
| Password | `PUT /api/users/{u}/password` |
| Limits | `PUT /api/users/{u}/limits` |
| Suspend / expire | `POST /api/users/{u}/lock` |
| Resume / renew | `POST /api/users/{u}/unlock` |
| Delete | `DELETE /api/users/{u}` (404 = success) |
| Sessions / disconnect | `GET /api/sessions`, `POST /api/sessions/{u}/disconnect` |
| Traffic / tunnels | `GET /api/traffic`, `GET /api/tunnels` |

### Usage

`GET /api/traffic` returns `{"cumulative": true, "users": [{"username", "rx", "tx"}]}`:
lifetime bytes per user, as the server sees them (`rx` is the user's upload,
`tx` their download). The panel only reads usage from an agent that says
`cumulative`; older agents reported live sessions only and are ignored.

The totals are every closed session plus the live ones. occtl forgets a
session when it ends, so the installer registers
`/usr/local/sbin/ocserv-api-disconnect` as ocserv's `disconnect-script`: it
adds each session's final `STATS_BYTES_IN` / `STATS_BYTES_OUT` to
`/var/lib/ocserv-api/traffic.json` (root only, written atomically under a
lock, never failing the teardown). `stats-report-time = 60` keeps the live
counters fresh. Deleting a user clears their totals, so a new user with the
same name starts from zero. If ocserv already runs another disconnect-script,
the installer leaves it in place and warns that closed sessions are not counted.

