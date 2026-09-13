# CRM Tunneling Module (GRE)

Automated GRE tunnels between MikroTik **hub** (Iran) and **exit** (foreign) servers.

## Router prerequisites

On each RouterOS v7 router:

1. Enable API (prefer restricted source):
   ```
   /ip service set api disabled=no port=8728
   /ip service set api address=<CRM_SERVER_IP>/32
   ```
2. Create a dedicated API user (write + read):
   ```
   /user group add name=crm-tunnel policy=read,write,test,policy
   /user add name=crm-tunnel group=crm-tunnel password=...
   ```
3. Set `public_ip` on the server record in CRM (used as GRE local/remote endpoint).
4. Mark hub servers with `is_hub=true` and set `wan_interface` (default `ether1`).

## Database

```bash
php artisan migrate
```

Tables: `crm_tunnels`, `crm_tunnel_logs` (+ `servers.is_hub`, `wan_interface`, `api_ssl`).

## API (admin session)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/servers` | List MikroTik servers |
| PATCH | `/api/servers/{id}` | Update hub/wan/public_ip |
| POST | `/api/servers/{id}/test-connection` | Test API login |
| POST | `/api/tunnels` | Create GRE tunnel + queue provision |
| POST | `/api/tunnels/{id}/redeploy` | Reset + provision + test |
| POST | `/api/tunnels/{id}/test` | Test only |
| GET | `/api/tunnels/{id}/logs` | Step logs |
| DELETE | `/api/tunnels/{id}` | Teardown + delete |

Example:

```bash
curl -X POST /api/tunnels \
  -H "Cookie: ..." \
  -d '{"hub_server_id":1,"exit_server_id":2,"type":"gre"}'
```

## Architecture

- `TunnelDriver` interface + `GreTunnelDriver` (WireGuard/GRE6/EoIP later)
- `TunnelManager` — reset(exit) → reset(hub) → provision → test
- `SubnetAllocator` — auto /30 from `10.16.0.0/16`
- `RoutingPolicyManager` — skeleton for multi-tunnel LB (TODO)
- `ProvisionTunnelJob` — queue worker required: `php artisan queue:work`

All CRM objects use comment prefix **`CRM-TUN`** for idempotent cleanup.

## Config (`.env`)

```
CRM_TUN_SUBNET_POOL=10.16.0.0/16
CRM_TUN_COMMENT_PREFIX=CRM-TUN
CRM_TUN_QUEUE=default
```

## Relation to existing tunnel groups

This module is **separate** from `admin/tunneling` (desired-state tunnel groups). Use CRM tunnels for simple hub↔exit GRE; use tunnel groups for multi-agent LB and WireGuard clients.
