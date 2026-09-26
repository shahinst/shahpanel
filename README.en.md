<div align="center">

<img src="public/images/shahpanel-logo.png" alt="shahpanel" width="380">

### VPN management and reseller panel

**Multi-tier · Persian · one-command install**

<br>

[![License](https://img.shields.io/badge/license-proprietary-red?style=flat-square)](#-license)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11-ff2d20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![MySQL](https://img.shields.io/badge/MySQL-8%2B-4479a1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)

[![Release](https://img.shields.io/github/v/release/shahinst/shahpanel?style=flat-square&logo=github&color=1668dc&label=version)](https://github.com/shahinst/shahpanel/releases/latest)
[![Stars](https://img.shields.io/github/stars/shahinst/shahpanel?style=flat-square&logo=github&color=1668dc)](https://github.com/shahinst/shahpanel/stargazers)
[![Forks](https://img.shields.io/github/forks/shahinst/shahpanel?style=flat-square&logo=github&color=1668dc)](https://github.com/shahinst/shahpanel/network/members)
[![Issues](https://img.shields.io/github/issues/shahinst/shahpanel?style=flat-square&logo=github)](https://github.com/shahinst/shahpanel/issues)
[![Last commit](https://img.shields.io/github/last-commit/shahinst/shahpanel?style=flat-square&logo=github)](https://github.com/shahinst/shahpanel/commits)
[![Repo size](https://img.shields.io/github/repo-size/shahinst/shahpanel?style=flat-square&logo=github)](https://github.com/shahinst/shahpanel)

<br>

[Quick install](#-installation) · [Features](#-features) · [Telegram bots](#-connecting-ready-made-bots-mirza-and-wizwiz) · [Updating](#-updating) · [Maintenance](#-maintenance) · [Troubleshooting](#-troubleshooting) · [FAQ](#-faq) · [Support](#-supporting-the-project)

<br>

⭐ If you find it useful, please star the project.

</div>

<div align="center">
  <a href="README.md" title="فارسی"><img src="docs/flags/ir.svg" width="26" alt="فارسی"></a>
  <a href="README.en.md" title="English"><img src="docs/flags/gb.svg" width="26" alt="English"></a>
  <a href="README.ru.md" title="Русский"><img src="docs/flags/ru.svg" width="26" alt="Русский"></a>
  <a href="README.zh.md" title="中文"><img src="docs/flags/cn.svg" width="26" alt="中文"></a>
</div>

---

## 📌 What is shahpanel?

A web panel for **selling and managing VPN services**, built around a multi-tier structure. Every tier has its own wallet, pricing, clients and reports:

```
Admin  ─┬─ Agent ─┬─ Seller ─┬─ Client
        │         │          └─ Client
        │         └─ Seller ──── Client
        └─ Agent ──── Seller ─── Client
```

The panel talks to your servers directly and **creates, renews, limits and closes** accounts right there:

| Server | Supported services |
|:--|:--|
| 🖧 **MikroTik RouterOS** | PPPoE / PPP · WireGuard · OpenVPN · L2TP |
| 🌐 **Sanaei (3x-ui)** | VMess · VLESS · Trojan |
| 🛡 **Pasarguard** | All inbounds |
| 🌊 **Remnawave** | Internal Squads — compatible with the v3.x API |
| 🔒 **Cisco AnyConnect (ASA)** | VPN users via the device REST API |
| 🔓 **OpenConnect / ocserv** | VPN users via the JSON management API |

---

## ✨ Features

<table>
<tr><td width="50%" valign="top">

**💰 Sales and reseller management**
- Admin / Agent / Seller / Client hierarchy
- Wallet with row locking and `bcmath` arithmetic
- Volume-based, time-based and hybrid packages
- Agent commission and site-wide discounts
- Per-agent pricing plan
- Automatic invoices with PDF export

</td><td width="50%" valign="top">

**👤 Accounts**
- Create, renew, upgrade and move between servers
- Usage synchronisation every 5 minutes
- Automatic expiry and disconnection on time
- Client portal with a dedicated link and QR code
- Gift accounts and trial accounts
- Impersonation login, with logging

</td></tr>
<tr><td valign="top">

**💳 Payments**
- ZarinPal
- Card-to-card transfer with admin approval
- NOWPayments (cryptocurrency)
- Webhook signature verification with HMAC
- Protection against replay and underpayment

</td><td valign="top">

**🔐 Security**
- Server credentials encrypted with `APP_KEY`
- Two-factor login (TOTP) with a locally generated QR code
- Layer 7 firewall and admin IP restriction
- Changeable login path for every panel
- Captcha, rate limiting and activity log
- TLS certificate verification for remote panels, configurable per server

</td></tr>
<tr><td valign="top">

**🛡 Login firewall**
- IP blocking after several wrong passwords
- Persistent attackers pushed to `ipset` at kernel level
- Full blocking of a country's IP ranges
- Blocklist with country flags and one-click unblock
- Whitelist, and full control from the command line

</td><td valign="top">

**🔌 Reseller API**
- Dedicated token for a Telegram bot (Mirza, Dibot and so on)
- Sell, renew, add data and fetch client configs
- Per-agent and per-seller pricing
- Token scope restrictions and IP locking
- Endpoint reference inside the panel itself

</td></tr>
<tr><td valign="top">

**🔀 Network infrastructure**
- Desired-state tunnelling between routers
- GRE tunnels with load balancing and failover
- Drift detection and automatic repair
- CPU/RAM/conntrack monitoring for every router
- Capacity alerts via Telegram

</td><td valign="top">

**📊 Management**
- Live dashboard with dynamic charts
- Revenue, usage and account status reports
- Automatic backups of routers and the database
- Sanaei → Remnawave migration tool
- SMS management and broadcast notifications

</td></tr>
</table>

---

## 🚀 Installation

> **Installation is done over SSH only.** There is no web installer — no URL on the panel itself performs the installation.

### Step 0 — What you need

| Item | Details |
|:--|:--|
| **Server** | Ubuntu 22.04 or 24.04 (fresh and empty, at least 1 GB RAM) |
| **Access** | A `root` or `sudo` user |
| **Domain** | **Optional.** A valid certificate is issued either way — from Let's Encrypt with a domain, and directly for the IP address without one. |

You do **not** need to install PHP, MySQL, Nginx or Composer by hand — the script installs everything itself.

<details>
<summary><b>How do I set up an A record?</b></summary>

<br>

Create a record in your domain's control panel:

| Type | Name | Content |
|:--|:--|:--|
| `A` | `panel` | Your server's IP |

If you use Cloudflare, set the orange cloud (Proxy) to **grey temporarily** so the certificate can be issued.

Use this command to confirm DNS has propagated:

```bash
dig +short panel.example.com
```

It should return exactly your server's IP. DNS propagation sometimes takes a few hours.

</details>

### Step 1 — Connect to the server

```bash
ssh root@YOUR_SERVER_IP
```

### Step 2 — Run the installer

```bash
curl -fsSLO https://raw.githubusercontent.com/shahinst/shahpanel/master/install.sh
sudo bash install.sh
```

The script greets you and **asks a single question: do you have a domain or not?**

- **I have a domain** → enter the domain. The installer checks that the `A` record really points at this server, and if so obtains a free **Let's Encrypt** certificate. The browser shows no warning.
- **I have no domain** → press Enter. The installer obtains a valid Let's Encrypt certificate for **the server's IP address itself**, and the browser shows no warning. These certificates are short-lived (about 6 days) and `acme.sh` renews them automatically four times a day. If issuance fails for any reason, the panel comes up with a self-signed certificate and the retry command is printed.

If you already know the domain, or the installation has to run non-interactively:

```bash
sudo bash install.sh panel.example.com     # straight to a domain
sudo bash install.sh --ip --yes            # straight to the IP, no questions at all
```

Optional flags:

| Flag | What it does |
|:--|:--|
| `--ip` | No domain; install on the server IP with a valid certificate for that IP |
| `--yes` | Ask nothing and take the defaults |
| `--with-phpmyadmin` | Install phpMyAdmin on a random path |
| `--with-security` | Install the security shield (CrowdSec + fail2ban + ClamAV) |
| `--no-ssl` | No TLS (HTTP only) — not recommended |

### Step 3 — Wait

Installation takes **10 to 45 minutes** depending on the server — on a two-core machine the database migration stage alone takes about 15 minutes. If it sits on one stage for a few minutes, it has not hung. The script runs 13 stages in order:

```
[1/13]  Check the server and determine the domain or IP
[2/13]  Install PHP 8.3 · MySQL · Nginx · ipset   (+ certbot in domain mode)
[3/13]  Install Composer
[4/13]  Fetch the panel code
[5/13]  Install PHP dependencies (composer install)
[6/13]  Create the database and its user
[7/13]  Configure .env and the encryption key
[8/13]  File permissions and database table creation
[9/13]  Install the firewall helper and download country ranges
[10/13] Configure Nginx        (+ temporary self-signed certificate in IP mode)
[11/13] Enable TLS
[12/13] Install the scheduler cron   (+ phpMyAdmin and the security shield, if selected)
[13/13] Create the admin user
```

### Step 4 — Write down the login details

At the end, the **admin login URL**, the admin password and the database password are printed **on screen only** — they are deliberately kept out of the log file. **Write them down there and then.**

A copy is also stored here (only `root` can read it):

```bash
sudo cat /var/www/shahpanel/storage/app/INSTALL_CREDENTIALS.txt
```

The output looks something like this:

```
Panel URL : https://panel.example.com/p7f3a9c2e51
Username  : admin
Password  : ••••••••••••

Database  : shahpanel
DB user   : shahpanel@127.0.0.1
DB pass   : ••••••••••••
```

The full installation log (without any passwords) is at `/var/log/shahpanel-install.log`.

### Step 5 — Log in

Log in at **the same random path** that was printed in the previous step — not `/admin`:

```
https://panel.example.com/p7f3a9c2e51     ← installed with a domain
https://203.0.113.45/p7f3a9c2e51          ← installed on an IP
```

In both cases (domain or IP) the certificate is valid and the browser does not warn.

> ⚠️ **The admin login path is random, not `/admin`.** The first thing every automated scanner tries is `/admin`, so it is deliberately not used. If you lose this URL, read it back from the file above.

> ⚠️ **The first thing to do: change the admin password.**
> After that, enabling two-factor login from the profile section is recommended.

<details>
<summary><b>Changing the panels' login paths</b></summary>

<br>

From inside the admin panel: **Settings → Security → Login paths**. All three paths can be changed there:

| Panel | Default | Details |
|:--|:--|:--|
| Admin | Random at install time | Only you see it |
| Agent | `agent` | The admin can change it |
| Seller | `seller` | The admin can change it |

Only the admin has access to this page; agents and sellers cannot change their own path.

A path must be at least 3 characters and contain only `a-z`, `0-9`, `-` and `_`. A few words are reserved (`api`, `client`, `install`, `login`, `portal`, `up` and so on) and are not accepted.

The "Block the default paths" option additionally makes the old URLs (`/admin`, `/agent`, `/seller`) stop responding.

</details>

<details>
<summary><b>phpMyAdmin</b></summary>

<br>

It is installed only with `--with-phpmyadmin` and sits on a random path (not `/phpmyadmin`). You log in with **the panel's own database user and password**; that user only has access to the panel's database, not to the whole of MySQL.

⚠️ phpMyAdmin is one of the most heavily attacked pieces of web software. The recommendation is not to install it unless you genuinely need it. To remove it later:

```bash
apt-get remove --purge phpmyadmin
rm -f /etc/nginx/snippets/shahpanel-phpmyadmin.conf
systemctl reload nginx
```

</details>

### Step 6 — Initial panel setup

After logging in, in order:

1. **Servers** → add your MikroTik / 3x-ui / Pasarguard / Remnawave / Cisco server and hit "Test connection".
2. **Packages** → define the packages you sell (data, duration, price).
3. **Agents** → create agents if you need them and set their credit limit.
4. **Settings → Payment gateways** → enable the gateway you want and enter its keys.

<details>
<summary><b>Custom installation settings</b></summary>

<br>

These can be changed with environment variables:

```bash
sudo APP_DIR=/var/www/panel DB_NAME=mypanel ADMIN_USER=root \
     EMAIL=me@example.com bash install.sh panel.example.com
```

| Variable | Default | Details |
|:--|:--|:--|
| `APP_DIR` | `/var/www/shahpanel` | Installation path |
| `REPO` | The official repository | Git repository URL |
| `BRANCH` | `master` | Branch |
| `DB_NAME` | `shahpanel` | Database name |
| `DB_USER` | `shahpanel` | Database user |
| `ADMIN_USER` | `admin` | Admin username |
| `EMAIL` | none | Let's Encrypt and admin email |

If the repository is private, also supply `GITHUB_TOKEN`. The token is stripped from `.git/config` after the clone and is masked in the log as well.

</details>

<details>
<summary><b>If the SSL certificate was not issued</b></summary>

<br>

This usually means DNS has not propagated yet. Once DNS is correct:

```bash
certbot --nginx -d panel.example.com --redirect
```

Then set this value to `true` in `.env` so the session cookie is only sent over HTTPS:

```env
SESSION_SECURE_COOKIE=true
```

And clear the cache:

```bash
cd /var/www/shahpanel && sudo -u www-data php artisan optimize:clear
```

</details>

<details>
<summary><b>Manual installation (for a server that is already prepared)</b></summary>

<br>

For when you manage PHP, MySQL and the web server yourself and do not want `install.sh`.

**Requirements:** PHP 8.2+ with the `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `mysql`, `gd`, `openssl` and `dom` extensions · MySQL 8 or MariaDB · Composer.
Node/npm is not needed — the built files (`public/build`) are in the repository.

```bash
git clone https://github.com/shahinst/shahpanel.git /var/www/shahpanel
cd /var/www/shahpanel

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

```env
APP_URL=https://your-domain.com
APP_ENV=production
APP_DEBUG=false

DB_DATABASE=your_db
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
SESSION_SECURE_COOKIE=true

# Admin login path — use a random string, not admin
VPN_ADMIN_PATH=p7f3a9c2e51
```

```bash
php artisan migrate --force
php artisan storage:link

# Permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# .env contains APP_KEY and must not be readable by the server's other users
chown root:www-data .env && chmod 640 .env

# Create the admin user and lift the installation lock
php artisan install:finalize \
  --admin-username=admin \
  --admin-email=admin@example.com \
  --admin-password='<password>' \
  --admin-name=Administrator

echo "installed $(date -Is)" > .installed.lock
chown www-data:www-data .installed.lock
```

> 🔒 Instead of `--admin-password` you can set the `VPN_ADMIN_PASSWORD` environment variable so the password is not visible in `ps` output. `install.sh` does exactly that.

Point the document root at `public/` and add the scheduler cron from the [Maintenance](#-maintenance) section. Until `.installed.lock` is created, the panel answers every request with a "not installed yet" page.

</details>

---

## 🔌 Reseller API (connecting a Telegram bot)

Agents and sellers can connect the panel to their own Telegram bot and sell accounts,
renew them, fetch client configs and view their wallet and downline.

**Creating a token:** log in to the panel → the **Settings** menu → **API and bot** → create a token.
The same place holds the **API reference** page, which shows the parameters of every single endpoint.

Or straight from the API itself:

```bash
curl -X POST https://YOUR-PANEL/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"reseller1","password":"...","device_name":"my-bot"}'
```

Then send the token with every request:

```bash
curl https://YOUR-PANEL/api/v1/accounts \
  -H 'Authorization: Bearer mp_xxxxxxxx'
```

Every response has a fixed shape, so the bot only has to check `ok`:

```json
{ "ok": true,  "data": { }, "meta": { "pagination": { } } }
{ "ok": false, "error": { "code": "...", "message": "..." } }
```

**Important notes**

- Only the agent and seller roles get tokens; admins and clients do not.
- A seller only sees their own accounts, an agent sees their whole downline — the same rules as the panel itself.
- Each token can be restricted to a specific set of permissions and locked to an IP.
- Topping up a wallet is not possible from the API; in the panel itself only the admin is allowed to do it.
- The default limit is 120 requests per minute per token (adjustable between 10 and 600).

Full documentation: [`docs/API.md`](docs/API.md) — ready-made PHP and Python samples: [`docs/bot-client/`](docs/bot-client/)
For Cisco AnyConnect: [`docs/CISCO_ANYCONNECT_API.md`](docs/CISCO_ANYCONNECT_API.md)
For OpenConnect / ocserv: [`docs/OCSERV_API.md`](docs/OCSERV_API.md)

---

## 🤖 Connecting ready-made bots (Mirza and WizWiz)

You do not have to write a bot. Existing reseller bots connect to shahpanel unmodified.

| Bot | Status |
|:--|:--|
| **Mirza** (MirzaBot) | ✅ Supported |
| **WizWiz** | ✅ Supported |

### How to connect

In the bot's own panel, add a server of type **Marzban** and give it:

| Field | Value |
|:--|:--|
| Panel address | `https://YOUR-PANEL` |
| Username | the username of an **Agent** or **Seller** account |
| Password | that account's password |

That is all. Set the panel type to Marzban and the bot handles the rest.

### Things worth knowing

**Do not connect with the admin account.** Only Agents and Sellers are accepted. Every bot sale is debited from that account's wallet and recorded in the panel's accounting; the admin wallet is unlimited, so sales made through it would never appear in your profit and loss.

**Your packages appear as inbounds.** When you build a plan in the bot you see the packages assigned to that Agent, one entry per package and duration. Pick one — nothing else needs configuring.

**Volume and duration:** for a fixed package the package's own volume and duration apply and whatever you type in the bot is ignored. For an elastic (per-GB) package the bot's numbers are used.

**Sellable services:** Sanaei (3x-ui), Pasarguard and Remnawave. MikroTik, Cisco AnyConnect and OpenConnect cannot be sold this way — they are username-and-password services with no subscription link, which is a limit of the protocol itself.

**An account with 2FA enabled cannot connect a bot**, because the protocol has no field for a second-factor code. Create a separate Agent account for the bot.

**If the bot says "created" but no account exists**, check the panel log:

```bash
sudo grep -i marzban /var/www/shahpanel/storage/logs/laravel-*.log | tail -20
```

Some bots misread an error response as success. The real reason is always in the panel log.

## 📦 Automatic backups on Telegram

The panel can back your servers up and send the zip to you on Telegram on a schedule.

**Setup**, under `Settings → Server backups`:

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy its **token**.
2. Message your own bot once, then enter the **chat id**. For a group, add the bot to the group and use the group's id.
3. Press **Send a test message**. Do not rely on the schedule until that test succeeds.
4. For each server, enable the schedule and list the times — for example `03:30, 15:00`.

**Things worth knowing:**

- **Times are in the panel timezone** (`Asia/Tehran`), not UTC.
- Several servers on the **same time** arrive in **one message**. Different times produce a separate message per server.
- The message carries each server's status, what was backed up, the file size, the exact date and time, and a **separate hashtag per server** so you can search for it in Telegram.
- **A failed backup still sends a message** naming the reason. Silence is the worst outcome, because you would assume backups are running.
- Telegram refuses files over **50 MB**. In that case the status message still arrives and names the **path of the file on the server** so you can collect it manually.
- Server backups work for **MikroTik, Sanaei (3x-ui), Pasarguard and Remnawave**. A Sanaei backup is the panel's own SQLite database file, downloaded from the panel. Cisco and OpenConnect servers are not in that list.
- A Telegram outage never stops the backup being taken; the file stays on the server and only the delivery failure is logged.

### ⏰ Where do I change the backup time?

In the panel, everywhere. You never edit a cron file on the server.

1. **Settings → Server backups**: the "Backup schedule" block for servers, and the "Panel database backup schedule" block for the panel's own database.
2. The format is 24-hour `HH:MM`, several values separated by a comma or a space — for example `03:30, 15:00`. Persian digits and the Persian comma are accepted too: `۰۳:۳۰، ۱۵:۰۰`.
3. Times are in the **panel timezone** (`APP_TIMEZONE`, `Asia/Tehran` by default), not UTC.

**Two independent schedules:**

- **Server backups:** enabled per server, each server with its own times.
- **Panel database backup:** one on/off switch and one list of times for the whole panel. Turning one on or off does not touch the other, and if both are set to the same time you get two separate messages.

**If nothing arrives at all, check the panel cron first.** The installer creates exactly one system cron entry; everything else is scheduled inside the panel:

```bash
cat /etc/cron.d/shahpanel-scheduler   # must run artisan schedule:run every minute
systemctl status cron                 # the cron service must be running
```

Inside the panel, **Dashboard → System health** shows the last time `schedule:run` fired. If that timestamp is not recent, no schedule is running.

## 🌐 Tunnel address for configs

If the panel reaches the foreign server directly but your users must connect through a tunnel or relay, keep the two addresses apart.

| Field | What it is for |
|---|---|
| **Host** | the address the **panel** uses to talk to the server API |
| **Client address** | the address handed **to users** inside configs and the subscription link |
| **Client port** | when the tunnel listens on a different port |

Example: host `1.2.3.4:2053` (the panel's direct link to the server) and client address `tunnel.example.com` on port `443` (the path users connect through).

**Leave the client address empty** to keep the current behaviour; existing servers lose nothing.

A few notes:

- This only changes the **destination address**. The `sni`, `host` and `path` values are left alone, because they are the destination's TLS identity and rewriting them breaks the handshake.
- It also applies to configs that already existed on the server and were imported, because the substitution happens at **delivery** time, not when the config is created.
- Changing the setting takes effect immediately; there is no need to rebuild accounts.
- The panel's own connection to the server never uses this address — it always goes through **Host**.

## 🛡 Login firewall

It stops password guessing and can block a whole country's IP ranges.

**How it works**

1. Several wrong passwords in a row from one IP ⇒ that IP loses access to the login page for a while.
2. If it keeps hammering after being blocked, it is handed over to `ipset` and the kernel deals with it.
3. A successful login clears the earlier failures for that IP.

Only the **login paths** are blocked, not the whole site: on networks where dozens of subscribers sit behind one IP
(CGNAT), blocking the entire site would cut off innocent clients too. The client portal and the subscription
link always stay open.

**Managing it from the panel:** Admin → **Settings** → **Login firewall** — a list of blocked IPs with
country flags, one-click unblock, manual blocking and a whitelist.

**Managing it from the command line**

```bash
php artisan firewall status            # chain status and number of blocks
php artisan firewall block 1.2.3.4     # manual block (--minutes=0 means permanent)
php artisan firewall unblock 1.2.3.4   # release one IP
php artisan firewall flush             # remove all blocks
```

**Blocking a country** — the Chinese ranges are loaded and refreshed every Monday at 03:30:

```bash
php artisan firewall:sync-country-data   # download and reload
php artisan firewall cn-clear            # remove the country block
```

**Infrastructure.** The kernel-level work is done by a root helper that the installer places at
`/usr/local/sbin/panel-firewall`, and only that single file is granted to `www-data` in sudoers.
That means the web user can request a block but **cannot write the list it is itself filtered by**.
Country data is kept in `/var/lib/panel-firewall`.

> The helper only touches the `INPUT` chain; never `OUTPUT` or `FORWARD`. It also refuses to block
> loopback, private ranges and the server's own IP, so a bad rule cannot take the server offline.
> Put your own IP on the whitelist so a wrong password cannot lock you out.

---

## 🔄 Updating

```bash
cd /var/www/shahpanel
sudo bash update.sh
```

The script, in order:

1. **Backs up the database first** (in `/var/backups/shahpanel/`, the last 10 copies are kept)
2. Pulls the new code
3. Runs `composer install` and `migrate`
4. Clears the cache and restarts the queue worker

If the backup fails, it **does not touch the code**. If you have modified a file on the server by hand, it **stops** and overwrites nothing.

---

## 🔧 Maintenance

### Cron (the scheduler)

Without it the queue is not processed and accounts do not expire on time. `install.sh` sets it up itself:

```
* * * * * www-data cd /var/www/shahpanel && php artisan schedule:run >> /dev/null 2>&1
```

That single line is enough — **no separate queue service is needed**, because the scheduler itself runs a
`queue:work --stop-when-empty` every minute.

To check its health from the panel: **Settings → Automation → Cron job**

### Tasks that run automatically

| Task | Schedule |
|:--|:--|
| Account usage synchronisation (`sync:usage`) | Every 5 minutes |
| Expiry check and account disconnection (`accounts:check-expiry`) | Every 10 minutes |
| Queue processing (`queue:work`) | Every minute |
| Router metric collection and drift detection | Every 1 to 5 minutes |
| Clearing expired firewall blocks | Every 15 minutes |
| Dispatching alerts (`alerts:dispatch`) | Hourly |
| Database backup (`backup:database`) | Nightly at 02:00 |
| Daily report (`reports:daily-rollup`) | Nightly at 00:00 |
| Automatic ticket closing | Daily |
| Tunnel metric cleanup | Nightly at 03:30 |
| Country range updates | Mondays at 03:30 |

> ⚠️ The nightly backup adds a file to `storage` every night. Check disk space every now
> and then and delete the old files.

### Backups and moving to a new server

```bash
# Take a backup and send it to another server
bash scripts/db-migrate.sh backup --send root@newhost:/root/

# Restore on the new server
bash scripts/db-migrate.sh restore --latest
```

> 🔑 Be sure to move the `.env` file as well. Without the **original** `APP_KEY`, the stored router usernames and passwords cannot be decrypted.

### Route caching (optional, for speed)

```bash
cd /var/www/shahpanel
sudo -u www-data php artisan route:cache
```

Each panel's login path is changeable and is read from the database, so it gets frozen into the cache. The panel handles this itself: the cache is rebuilt whenever a path is changed from the security section.

---

## 🆘 Troubleshooting

### The panel does not come up

There is a diagnostics page independent of Laravel that shows database status, file permissions and the latest errors, and can clear the cache or run migrations.

Because it does dangerous things, it is **closed by default** and returns 404 to everyone. To open it, put a token in `.env`:

```bash
cd /var/www/shahpanel
echo "MAINTAIN_TOKEN=$(php -r 'echo bin2hex(random_bytes(16));')" >> .env
```

Then open: `https://panel.example.com/maintain.php?key=<token>`

> Once the problem is fixed, be sure to empty `MAINTAIN_TOKEN` so the page closes again. As long as this value is empty, that URL is a 404 for everyone.

### Common problems

<details>
<summary><b>After a server restart the panel returns 502</b></summary>

<br>

Usually MySQL or PHP-FPM has not come up:

```bash
systemctl start mysql php8.3-fpm nginx
systemctl enable mysql php8.3-fpm nginx   # so they start automatically next time
```

</details>

<details>
<summary><b>Accounts do not expire / usage is not updated</b></summary>

<br>

Cron is not working. Check:

```bash
systemctl status cron
cat /etc/cron.d/shahpanel-scheduler
cd /var/www/shahpanel && sudo -u www-data php artisan schedule:run
```

</details>

<details>
<summary><b>Blank page or a 500 error</b></summary>

<br>

```bash
tail -50 /var/www/shahpanel/storage/logs/laravel.log
cd /var/www/shahpanel && sudo -u www-data php artisan optimize:clear
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

</details>

<details>
<summary><b>I changed the panel login path and now I have no access</b></summary>

<br>

Read the current path from the database:

```bash
cd /var/www/shahpanel
sudo -u www-data php artisan tinker --execute="echo App\Models\Setting::getValue('portal_path_admin');"
```

To restore the default:

```bash
sudo -u www-data php artisan tinker --execute="App\Models\Setting::setValue('portal_path_admin','admin');"
sudo -u www-data php artisan optimize:clear
```

> `tinker` is a development dependency. If you installed with `composer install --no-dev`, read the `VPN_ADMIN_PATH` value from `.env` instead.

</details>

<details>
<summary><b>The connection to the MikroTik server fails</b></summary>

<br>

- The API service must be enabled on the router: `/ip service enable api`
- The port (`8728` by default, `8729` with SSL) must be open from the panel server
- The RouterOS user must have the `api` permission
- If you run a firewall, allow the panel server's IP

</details>

<details>
<summary><b>SSL certificate error when connecting to a 3x-ui or Remnawave panel</b></summary>

<br>

By default the panel **verifies** the TLS certificate of remote servers, because that panel's admin
username and password are sent with every request.

If the target panel has a self-signed certificate, clear the "Verify the panel's SSL certificate" tick
on that server's edit page. This setting is per server.

</details>

---

## ❓ FAQ

<details>
<summary><b>Can it be installed on shared hosting (cPanel / DirectAdmin)?</b></summary>

<br>

Not supported. The panel needs a per-minute cron, a background queue and direct connections to router APIs. A cheap VPS is enough.

</details>

<details>
<summary><b>How many servers can I add?</b></summary>

<br>

There is no limit. Every server has its own account cap, set from the server page.

</details>

<details>
<summary><b>Can I run MikroTik and Remnawave at the same time?</b></summary>

<br>

Yes. Each package is tied to one service type and the panel creates the account on the right server itself.

</details>

<details>
<summary><b>What happens if I change APP_KEY?</b></summary>

<br>

The usernames and passwords of all stored routers and panels become **unrecoverable**. Never change it, and keep a backup of `.env`.

</details>

<details>
<summary><b>What data does the panel send out?</b></summary>

<br>

None. The only external connections are to your own servers, to the payment gateways you have enabled, and the download of country ranges for the firewall. The two-factor QR code is generated on this server too.

</details>

---

## 🛠 Development

```bash
git clone https://github.com/shahinst/shahpanel.git shahpanel
cd shahpanel

composer install
cp .env.example .env && php artisan key:generate
php artisan migrate

npm ci && npm run build     # only if you changed the UI
php artisan serve
```

### Project structure

```
app/Services/           Domain logic (accounts, wallet, servers, remote panels)
app/Services/RouterOs/  MikroTik desired-state layer
app/Services/Tunneling/ Router-to-router tunnel orchestrator
app/Http/Controllers/   Controllers, split by role
resources/views/        User interface (Blade + Tailwind + Chart.js)
database/migrations/    89 migrations
modules/                Modules (tunnelling, payments, migration)
scripts/panel-firewall  Root firewall helper (ipset/iptables)
scripts/db-migrate.sh   Database backup and migration to a new server
ops/security-shield/    CrowdSec + fail2ban + ClamAV installation
docs/CRM_TUNNELING.md   Tunnelling documentation (Tunnel Groups and CRM Tunnels)
docs/fa/                Admin, agent and seller guides, and Remnawave setup
tools/maintain-core.php Laravel-independent diagnostics page (behind a token)
```

---

## ⚠️ Important notes

| | |
|:--|:--|
| 🔑 | **Take `APP_KEY` seriously.** It is the decryption key for every stored credential. Keep a backup of `.env`. |
| 🔒 | `install.sh` sets the `.env` file to `root:www-data` ownership and mode `640` so the server's other users cannot read it. |
| 🌐 | On an HTTPS installation always keep `SESSION_SECURE_COOKIE=true` (the automated installer sets it for you). |
| 📁 | The document root must point at `public/`. If you point it at the project root, a root `.htaccess` file is required — without it `.env` and `.git/` are readable from the web. |
| 🧹 | Once a problem is fixed, empty `MAINTAIN_TOKEN` so the maintenance page closes. |

---

## 💚 Supporting the project

If this panel has been useful to you and you would like to contribute to its development:

<a href="https://nowpayments.io/donation?api_key=1b2c76da-3f32-4887-a5e3-4e3340417001" target="_blank" rel="noreferrer noopener">
   <img src="https://nowpayments.io/images/embeds/donation-button-black.svg" alt="Crypto donation button by NOWPayments">
</a>

Every contribution goes towards developing new features, fixing bugs and supporting more panels. 🙏

If you cannot support the project financially, a ⭐ on the repository and bug reports are a big help too.

---

## 📄 License

This project is **proprietary**. Use, redistribution or resale without written permission is not allowed.

<div align="center">
<br>

**Built for Persian-speaking sellers and agents**

If it was useful to you, give it a ⭐

</div>
