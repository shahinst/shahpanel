# OpenConnect (ocserv) Panel API

Base URL example: `https://ocserv1.example.com:9443`

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

