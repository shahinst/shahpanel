<div align="center">

<img src="public/images/shahpanel-logo.png" alt="shahpanel" width="380">

### VPN 管理与分销面板

**多层级 · 多语言 · 一条命令完成安装**

<br>

[![License](https://img.shields.io/badge/license-proprietary-red?style=flat-square)](#-许可证)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11-ff2d20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![MySQL](https://img.shields.io/badge/MySQL-8%2B-4479a1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)

[![Release](https://img.shields.io/github/v/release/shahinst/shahpanel?style=flat-square&logo=github&color=1668dc&label=版本)](https://github.com/shahinst/shahpanel/releases/latest)
[![Stars](https://img.shields.io/github/stars/shahinst/shahpanel?style=flat-square&logo=github&color=1668dc)](https://github.com/shahinst/shahpanel/stargazers)
[![Forks](https://img.shields.io/github/forks/shahinst/shahpanel?style=flat-square&logo=github&color=1668dc)](https://github.com/shahinst/shahpanel/network/members)
[![Issues](https://img.shields.io/github/issues/shahinst/shahpanel?style=flat-square&logo=github)](https://github.com/shahinst/shahpanel/issues)
[![Last commit](https://img.shields.io/github/last-commit/shahinst/shahpanel?style=flat-square&logo=github)](https://github.com/shahinst/shahpanel/commits)
[![Repo size](https://img.shields.io/github/repo-size/shahinst/shahpanel?style=flat-square&logo=github)](https://github.com/shahinst/shahpanel)

<br>

[快速安装](#-安装) · [功能](#-功能) · [Telegram 机器人](#-连接现成的机器人mirza-与-wizwiz) · [更新](#-更新) · [维护](#-维护) · [故障排查](#-故障排查) · [常见问题](#-常见问题) · [支持项目](#-支持本项目)

<br>

⭐ 如果它对你有用，请给项目点个星。

</div>

<div align="center">
  <a href="README.md" title="فارسی"><img src="docs/flags/ir.svg" width="26" alt="فارسی"></a>
  <a href="README.en.md" title="English"><img src="docs/flags/gb.svg" width="26" alt="English"></a>
  <a href="README.ru.md" title="Русский"><img src="docs/flags/ru.svg" width="26" alt="Русский"></a>
  <a href="README.zh.md" title="中文"><img src="docs/flags/cn.svg" width="26" alt="中文"></a>
</div>

---

## 📌 什么是 shahpanel

一个用于 **销售和管理 VPN 服务** 的 Web 面板，采用多层级结构。每一层都有自己独立的钱包、定价、客户和报表：

```
管理员 ─┬─ 代理商 ─┬─ 销售商 ─┬─ 客户
        │          │          └─ 客户
        │          └─ 销售商 ──── 客户
        └─ 代理商 ──── 销售商 ─── 客户
```

面板直接与你的服务器通信，就地 **创建、续费、限速和停用** 账号：

| 服务器 | 支持的服务 |
|:--|:--|
| 🖧 **MikroTik RouterOS** | PPPoE / PPP · WireGuard · OpenVPN · L2TP |
| 🌐 **Sanaei (3x-ui)** | VMess · VLESS · Trojan |
| 🛡 **Pasarguard** | 全部 inbound |
| 🌊 **Remnawave** | Internal Squads —— 兼容 3.x 版本 API |
| 🔒 **Cisco AnyConnect (ASA)** | 通过设备 REST API 管理 VPN 用户 |
| 🔓 **OpenConnect / ocserv** | 通过 JSON 管理 API 管理 VPN 用户 |

---

## ✨ 功能

<table>
<tr><td width="50%" valign="top">

**💰 销售与分销**
- 管理员 / 代理商 / 销售商 / 客户 层级体系
- 钱包带行锁与 `bcmath` 精确计算
- 流量套餐、时长套餐与阶梯套餐
- 代理商佣金与全局折扣
- 每个代理商可配置专属财务方案
- 自动生成账单并导出 PDF

</td><td width="50%" valign="top">

**👤 账号**
- 创建、续费、升级以及在服务器之间迁移
- 每 5 分钟同步一次流量用量
- 到期自动停用
- 客户门户，带专属链接和二维码
- 赠送账号与测试账号
- 身份代登录（impersonation）并记录日志

</td></tr>
<tr><td valign="top">

**💳 支付**
- ZarinPal
- 卡对卡转账，由管理员审核
- NOWPayments（加密货币）
- 使用 HMAC 校验 webhook 签名
- 防重放攻击与防金额不足支付

</td><td valign="top">

**🔐 安全**
- 使用 `APP_KEY` 加密服务器凭据
- 两步验证登录（TOTP），二维码本地生成
- 七层防火墙与管理员 IP 限制
- 每个面板的登录路径均可修改
- 验证码、频率限制与操作日志
- 远程面板的 TLS 证书校验，可按服务器单独配置

</td></tr>
<tr><td valign="top">

**🛡 登录防火墙**
- 多次密码错误后封禁 IP
- 持续攻击者交由内核层 `ipset` 处理
- 可整体封禁某个国家的全部 IP 段
- 封禁列表显示国旗，支持一键解封
- 白名单，以及完整的命令行控制

</td><td valign="top">

**🔌 分销 API**
- 为 Telegram 机器人（Mirza、DiBot 等）提供专属令牌
- 销售、续费、加量以及获取客户配置
- 每个代理商和销售商可设置专属价格
- 限制令牌权限并绑定 IP
- 面板内置接口说明文档

</td></tr>
<tr><td valign="top">

**🔀 网络基础设施**
- 路由器之间的 desired-state 隧道编排
- GRE 隧道，支持负载均衡与故障切换
- 配置漂移检测与自动修复
- 监控每台路由器的 CPU/RAM/conntrack
- 通过 Telegram 发送容量告警

</td><td valign="top">

**📊 管理**
- 实时仪表盘与动态图表
- 收入、流量与账号状态报表
- 自动备份路由器与数据库
- Sanaei ← Remnawave 迁移工具
- 短信与全站通知管理

</td></tr>
</table>

---

## 🚀 安装

> **安装只能通过 SSH 完成。** 没有 Web 安装向导 —— 面板上不存在任何用于安装的地址。

### 第 0 步 —— 需要准备什么

| 项目 | 说明 |
|:--|:--|
| **服务器** | Ubuntu 22.04 或 24.04（全新纯净系统，至少 1 GB 内存） |
| **权限** | `root` 用户或 `sudo` 权限 |
| **域名** | **可选。** 两种情况下都会获取有效证书 —— 有域名时使用 Let's Encrypt，无域名时直接为该 IP 签发。 |

**无需**手动安装 PHP、MySQL、Nginx 或 Composer —— 脚本会自动完成全部安装。

<details>
<summary><b>如何设置 A 记录？</b></summary>

<br>

在你的域名管理面板中创建一条记录：

| Type | Name | Content |
|:--|:--|:--|
| `A` | `panel` | 你的服务器 IP |

如果使用 Cloudflare，请在申请证书期间**临时把橙色云朵（Proxy）改为灰色**。

用下面的命令确认 DNS 已经生效：

```bash
dig +short panel.example.com
```

它应当准确返回你的服务器 IP。DNS 生效有时需要数小时。

</details>

### 第 1 步 —— 连接服务器

```bash
ssh root@YOUR_SERVER_IP
```

### 第 2 步 —— 执行安装

```bash
curl -fsSLO https://raw.githubusercontent.com/shahinst/shahpanel/master/install.sh
sudo bash install.sh
```

脚本会显示欢迎信息，并且**只问一个问题：你有域名吗？**

- **有域名** → 输入域名。安装程序会检查 `A` 记录是否确实指向本服务器，确认无误后申请免费的 **Let's Encrypt** 证书。浏览器不会有任何警告。
- **没有域名** → 直接回车。安装程序会**为服务器 IP 本身**申请一张有效的 Let's Encrypt 证书，浏览器同样不会警告。这类证书有效期很短（约 6 天），`acme.sh` 每天会自动续期四次。如果签发因故失败，面板会以自签名证书启动，并打印出重试命令。

如果你已经确定域名，或者需要无人值守安装：

```bash
sudo bash install.sh panel.example.com     # 直接指定域名
sudo bash install.sh --ip --yes            # 直接装在 IP 上，不提任何问题
```

可选参数：

| 参数 | 作用 |
|:--|:--|
| `--ip` | 不使用域名；安装在服务器 IP 上，并为该 IP 申请有效证书 |
| `--yes` | 不提任何问题，全部使用默认值 |
| `--with-phpmyadmin` | 在一个随机路径上安装 phpMyAdmin |
| `--with-security` | 安装安全防护套件（CrowdSec + fail2ban + ClamAV） |
| `--no-ssl` | 不启用 TLS（仅 HTTP）—— 不推荐 |

### 第 3 步 —— 耐心等待

安装耗时视服务器而定，**10 到 45 分钟** —— 在双核服务器上，仅数据库迁移一步就要约 15 分钟。如果某一步停留了几分钟，并不是卡死。脚本会按顺序执行 13 个步骤：

```
[1/13]  检查服务器并确定域名或 IP
[2/13]  安装 PHP 8.3 · MySQL · Nginx · ipset   (域名模式下另加 certbot)
[3/13]  安装 Composer
[4/13]  拉取面板代码
[5/13]  安装 PHP 依赖 (composer install)
[6/13]  创建数据库及其用户
[7/13]  配置 .env 与加密密钥
[8/13]  设置文件权限并创建数据库表
[9/13]  安装防火墙助手并下载国家 IP 段
[10/13] 配置 Nginx        (IP 模式下另加临时自签名证书)
[11/13] 启用 TLS
[12/13] 安装计划任务 cron (如已选择，另加 phpMyAdmin 与安全防护套件)
[13/13] 创建管理员用户
```

### 第 4 步 —— 记下登录信息

安装结束时，**管理员登录地址**、管理员密码和数据库密码**只会显示在屏幕上** —— 它们被刻意排除在日志文件之外。**请立刻记下来。**

同时也会在这里保存一份副本（只有 `root` 能读取）：

```bash
sudo cat /var/www/shahpanel/storage/app/INSTALL_CREDENTIALS.txt
```

输出大致是这样：

```
Panel URL : https://panel.example.com/p7f3a9c2e51
Username  : admin
Password  : ••••••••••••

Database  : shahpanel
DB user   : shahpanel@127.0.0.1
DB pass   : ••••••••••••
```

完整的安装日志（不含任何密码）位于 `/var/log/shahpanel-install.log`。

### 第 5 步 —— 登录

请使用上一步打印出的**那个随机地址**登录 —— 而不是 `/admin`：

```
https://panel.example.com/p7f3a9c2e51     ← 使用域名安装
https://203.0.113.45/p7f3a9c2e51          ← 安装在 IP 上
```

两种情况（域名或 IP）下证书都是有效的，浏览器不会发出警告。

> ⚠️ **管理员登录路径是随机的，不是 `/admin`。** 任何自动扫描器第一个探测的就是 `/admin`，因此这里刻意不使用它。如果你弄丢了这个地址，可以从上面那个文件中重新读取。

> ⚠️ **第一件要做的事：修改管理员密码。**
> 之后建议在个人资料中启用两步验证登录。

<details>
<summary><b>修改各面板的登录路径</b></summary>

<br>

在管理员面板中：**设置 → 安全 → 登录路径**。三个路径都可以在那里修改：

| 面板 | 默认值 | 说明 |
|:--|:--|:--|
| 管理员 | 安装时随机生成 | 只有你自己能看到 |
| 代理商 | `agent` | 管理员可以修改 |
| 销售商 | `seller` | 管理员可以修改 |

只有管理员能访问该页面；代理商和销售商不能修改自己的路径。

路径至少 3 个字符，且只能包含 `a-z`、`0-9`、`-` 和 `_`。有若干保留字（`api`、`client`、`install`、`login`、`portal`、`up` 等）不被接受。

还有一个「屏蔽默认路径」选项，可以让旧地址（`/admin`、`/agent`、`/seller`）彻底失效。

</details>

<details>
<summary><b>phpMyAdmin</b></summary>

<br>

只有加上 `--with-phpmyadmin` 才会安装，并且位于一个随机路径上（而不是 `/phpmyadmin`）。使用**面板数据库的同一个用户名和密码**登录；该用户只对面板自己的数据库有权限，而不是整个 MySQL。

⚠️ phpMyAdmin 是遭受攻击最频繁的 Web 软件之一。建议除非确实必要，否则不要安装。之后如需卸载：

```bash
apt-get remove --purge phpmyadmin
rm -f /etc/nginx/snippets/shahpanel-phpmyadmin.conf
systemctl reload nginx
```

</details>

### 第 6 步 —— 面板初始配置

登录后，按顺序：

1. **服务器** → 添加你的 MikroTik / 3x-ui / Pasarguard / Remnawave / Cisco 服务器，并点击「测试连接」。
2. **套餐** → 定义销售套餐（流量、时长、价格）。
3. **代理商** → 如有需要，创建代理商并设置其额度上限。
4. **设置 → 支付网关** → 启用所需网关并填入密钥。

<details>
<summary><b>自定义安装设置</b></summary>

<br>

可以通过环境变量修改：

```bash
sudo APP_DIR=/var/www/panel DB_NAME=mypanel ADMIN_USER=root \
     EMAIL=me@example.com bash install.sh panel.example.com
```

| 变量 | 默认值 | 说明 |
|:--|:--|:--|
| `APP_DIR` | `/var/www/shahpanel` | 安装路径 |
| `REPO` | 官方仓库 | Git 仓库地址 |
| `BRANCH` | `master` | 分支 |
| `DB_NAME` | `shahpanel` | 数据库名 |
| `DB_USER` | `shahpanel` | 数据库用户 |
| `ADMIN_USER` | `admin` | 管理员用户名 |
| `EMAIL` | 无 | Let's Encrypt 与管理员邮箱 |

如果仓库是私有的，还需要提供 `GITHUB_TOKEN`。clone 完成后令牌会从 `.git/config` 中清除，并在日志中被打码。

</details>

<details>
<summary><b>如果 SSL 证书没有签发成功</b></summary>

<br>

通常意味着 DNS 还没有生效。等 DNS 正确之后：

```bash
certbot --nginx -d panel.example.com --redirect
```

然后在 `.env` 中把这个值改为 `true`，让会话 cookie 只通过 HTTPS 传输：

```env
SESSION_SECURE_COOKIE=true
```

并清理缓存：

```bash
cd /var/www/shahpanel && sudo -u www-data php artisan optimize:clear
```

</details>

<details>
<summary><b>手动安装（适用于服务器已经配置好的情况）</b></summary>

<br>

如果 PHP、MySQL 和 Web 服务器由你自己管理，并且不想使用 `install.sh`。

**前置条件：** PHP 8.2+，并安装 `mbstring`、`xml`、`curl`、`zip`、`bcmath`、`mysql`、`gd`、`openssl`、`dom` 扩展 · MySQL 8 或 MariaDB · Composer。
不需要 Node/npm —— 构建产物（`public/build`）已包含在仓库中。

```bash
git clone https://github.com/shahinst/shahpanel.git /var/www/shahpanel
cd /var/www/shahpanel

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

然后编辑 `.env`：

```env
APP_URL=https://your-domain.com
APP_ENV=production
APP_DEBUG=false

DB_DATABASE=your_db
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
SESSION_SECURE_COOKIE=true

# 管理员登录路径 —— 请填一串随机字符，不要用 admin
VPN_ADMIN_PATH=p7f3a9c2e51
```

```bash
php artisan migrate --force
php artisan storage:link

# 文件权限
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# .env 中包含 APP_KEY，不应被服务器上的其他用户读取
chown root:www-data .env && chmod 640 .env

# 创建管理员用户并解除安装锁
php artisan install:finalize \
  --admin-username=admin \
  --admin-email=admin@example.com \
  --admin-password='<password>' \
  --admin-name=Administrator

echo "installed $(date -Is)" > .installed.lock
chown www-data:www-data .installed.lock
```

> 🔒 你可以用环境变量 `VPN_ADMIN_PASSWORD` 代替 `--admin-password`，这样密码不会出现在 `ps` 输出中。`install.sh` 本身就是这么做的。

把 document root 指向 `public/`，并按[维护](#-维护)一节添加计划任务 cron。在 `.installed.lock` 创建之前，面板对任何请求都会返回「尚未安装」页面。

</details>

---

## 🔌 分销 API（对接 Telegram 机器人）

代理商和销售商可以把面板接入自己的 Telegram 机器人，用来销售账号、
续费、获取客户配置，以及查看自己的钱包和下级。

**创建令牌：** 登录面板 ← **设置** 菜单 ← **API 与机器人** ← 创建令牌。
同一位置还有 **API 说明文档**页面，逐个列出每个接口的参数。

也可以直接通过 API 获取：

```bash
curl -X POST https://YOUR-PANEL/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"reseller1","password":"...","device_name":"my-bot"}'
```

之后在每个请求中带上令牌：

```bash
curl https://YOUR-PANEL/api/v1/accounts \
  -H 'Authorization: Bearer mp_xxxxxxxx'
```

所有响应都是固定格式，机器人只需检查 `ok` 字段：

```json
{ "ok": true,  "data": { }, "meta": { "pagination": { } } }
{ "ok": false, "error": { "code": "...", "message": "..." } }
```

**重要提示**

- 只有代理商和销售商角色能获取令牌；管理员和客户不能。
- 销售商只能看到自己的账号，代理商能看到整条下级链 —— 与面板本身的规则一致。
- 每个令牌都可以限制为若干指定权限，并绑定 IP。
- 无法通过 API 给钱包充值；即便在面板中，也只有管理员拥有该权限。
- 每个令牌默认每分钟 120 次请求上限（可在 10 到 600 之间调整）。

完整文档：[`docs/API.md`](docs/API.md) —— 现成的 PHP 与 Python 示例：[`docs/bot-client/`](docs/bot-client/)
Cisco AnyConnect 请见：[`docs/CISCO_ANYCONNECT_API.md`](docs/CISCO_ANYCONNECT_API.md)
OpenConnect / ocserv 请见：[`docs/OCSERV_API.md`](docs/OCSERV_API.md)

---

## 🤖 连接现成的机器人（Mirza 与 WizWiz）

你不必自己写机器人，现有的销售机器人无需改动即可连接 shahpanel。

| 机器人 | 状态 |
|:--|:--|
| **Mirza**（MirzaBot） | ✅ 支持 |
| **WizWiz** | ✅ 支持 |

### 如何连接

在机器人自己的面板中添加一台 **Marzban** 类型的服务器，填入：

| 字段 | 值 |
|:--|:--|
| 面板地址 | `https://YOUR-PANEL` |
| 用户名 | 一个**代理商**或**销售商**账号的用户名 |
| 密码 | 该账号的密码 |

这样就好了。面板类型选 Marzban，其余由机器人自行完成。

### 需要知道的几点

**不要用管理员账号连接。** 只接受代理商和销售商。机器人的每一笔销售都会从该账号的钱包扣除并计入面板账目；管理员钱包是无限的，通过它成交的销售不会出现在盈亏报表中。

**你的套餐会显示为 inbound。** 在机器人中建立方案时，你会看到分配给该代理商的套餐，每个套餐与时长各占一项。选一项即可，无需其他配置。

**流量与时长：** 固定套餐使用套餐自身的流量和时长，你在机器人里填的数值会被忽略；弹性套餐（按 GB 计价）则采用机器人的数值。

**可销售的服务：** Sanaei（3x-ui）、Pasarguard 与 Remnawave。MikroTik、Cisco AnyConnect 与 OpenConnect 无法通过这种方式销售——它们是用户名密码型服务，没有订阅链接，这是协议本身的限制。

**启用了两步验证的账号无法连接机器人**，因为协议中没有第二因素验证码的字段。请为机器人单独创建一个代理商账号。

**如果机器人显示“已创建”但账号并不存在**，请查看面板日志：

```bash
sudo grep -i marzban /var/www/shahpanel/storage/logs/laravel-*.log | tail -20
```

有些机器人会把错误响应误判为成功，真正的原因总在面板日志里。

## 📦 自动备份发送到 Telegram

面板可以按计划备份服务器，并把 zip 文件通过 Telegram 发给你。

在 `设置 → 服务器备份` 中**配置**：

1. 用 [@BotFather](https://t.me/BotFather) 创建机器人并复制其 **token**。
2. 先给自己的机器人发一条消息，然后填入 **chat id**。若用群组，请把机器人加入群组并填群组 id。
3. 点击**发送测试消息**。测试成功之前不要依赖定时任务。
4. 为每台服务器启用计划并填写时间，例如 `03:30, 15:00`。

**需要知道的几点：**

- **时间使用面板时区**（`Asia/Tehran`），不是 UTC。
- 多台服务器设置**相同时间**时会合并为**一条消息**；时间不同则每台服务器单独发送。
- 消息包含每台服务器的状态、备份内容、文件大小、精确日期时间，以及**每台服务器独立的话题标签**，便于在 Telegram 中搜索。
- **备份失败也会发送消息**并说明原因。沉默是最糟的结果，因为你会以为备份在正常运行。
- Telegram 不接受超过 **50 MB** 的文件。这种情况下仍会发送状态消息，并给出**服务器上的文件路径**。
- 服务器备份支持 **MikroTik、Pasarguard 和 Remnawave**。Sanaei、Cisco 和 OpenConnect 不在此列。
- Telegram 故障绝不会影响备份的生成；文件仍保存在服务器上，只有发送失败会记入日志。

### ⏰ 在哪里修改备份时间？

全部在面板里设置，完全不需要改服务器上的 cron 文件。

1. **设置 → 服务器备份**：「备份计划」区块用于服务器，「面板数据库备份计划」区块用于面板自身的数据库。
2. 格式为 24 小时制 `HH:MM`，多个值用逗号或空格分隔，例如 `03:30, 15:00`。也支持波斯数字与波斯逗号：`۰۳:۳۰، ۱۵:۰۰`。
3. 时间使用**面板时区**（`APP_TIMEZONE`，默认 `Asia/Tehran`），而不是 UTC。

**两个相互独立的计划：**

- **服务器备份：**按服务器分别启用，每台服务器有自己的时间。
- **面板数据库备份：**整个面板只有一个开关和一份时间列表。开启或关闭其中一个不会影响另一个；若两者设为同一时间，会收到两条独立消息。

**如果什么都没收到，请先检查面板的 cron。** 安装程序只创建一条系统 cron 记录，其余计划都在面板内部完成：

```bash
cat /etc/cron.d/shahpanel-scheduler   # 应每分钟执行 artisan schedule:run
systemctl status cron                 # cron 服务必须处于运行状态
```

在面板中，**仪表盘 → 系统健康**会显示 `schedule:run` 最近一次执行的时间。如果该时间不是最近的，说明所有计划都没有运行。

## 🌐 用于配置的隧道地址

如果面板直接连接境外服务器，而用户需要通过隧道或中转连接，请把这两个地址分开填写。

| 字段 | 用途 |
|---|---|
| **主机** | **面板**用来访问服务器 API 的地址 |
| **客户端地址** | 写入配置和订阅链接、**交给用户**的地址 |
| **客户端端口** | 当隧道监听其他端口时填写 |

例如：主机 `1.2.3.4:2053`（面板与服务器的直连），客户端地址 `tunnel.example.com`，端口 `443`（用户的连接路径）。

**客户端地址留空**即保持原有行为，现有服务器不受影响。

几点说明：

- 这只会更改**目标地址**。`sni`、`host` 和 `path` 保持原样，因为它们是目标的 TLS 身份，改写会导致握手失败。
- 对服务器上原有并导入进来的配置同样有效，因为替换发生在**下发**时，而不是创建时。
- 修改后立即生效，无需重建账号。
- 面板自身与服务器的连接永不使用该地址，始终通过**主机**。

## 🛡 登录防火墙

阻止密码爆破，并且可以封禁某个国家的整个 IP 段。

**工作原理**

1. 同一个 IP 连续多次输错密码 ⇒ 该 IP 在一段时间内无法访问登录页面。
2. 如果被封后仍继续攻击，就会被交给 `ipset`，由内核直接处理。
3. 一次成功登录会清除该 IP 之前的错误记录。

被封锁的只有**登录路径**，而不是整个站点：在几十个用户共用一个 IP 的网络中
（CGNAT），封锁整站会连带切断无辜客户。客户门户和订阅链接始终保持可用。

**在面板中管理：** 管理员 ← **设置** ← **登录防火墙** —— 被封 IP 列表带国旗显示、
一键解封、手动封禁与白名单。

**在命令行中管理**

```bash
php artisan firewall status            # 链状态与封禁数量
php artisan firewall block 1.2.3.4     # 手动封禁（--minutes=0 表示永久）
php artisan firewall unblock 1.2.3.4   # 解封某个 IP
php artisan firewall flush             # 清除所有封禁
```

**封禁一个国家** —— 会载入中国的 IP 段，并在每周一 03:30 更新：

```bash
php artisan firewall:sync-country-data   # 下载并重新载入
php artisan firewall cn-clear            # 解除对该国家的封禁
```

**底层实现。** 内核层的操作由一个 root 助手程序完成，安装程序会把它放在
`/usr/local/sbin/panel-firewall`，并且在 sudoers 中只把这一个文件授权给
`www-data`。也就是说，Web 用户可以请求封禁，但**无法写入用来过滤它自己的
那份列表**。国家数据保存在 `/var/lib/panel-firewall`。

> 助手程序只操作 `INPUT` 链；绝不碰 `OUTPUT` 和 `FORWARD`。它还会拒绝对
> 回环地址、私有网段和服务器自身 IP 的封禁，因此一条错误规则不会让服务器
> 失联。请把你自己的 IP 加入白名单，以免输错密码把自己锁在外面。

---

## 🔄 更新

```bash
cd /var/www/shahpanel
sudo bash update.sh
```

脚本会依次：

1. **先备份数据库**（保存在 `/var/backups/shahpanel/`，保留最近 10 份）
2. 拉取新代码
3. 执行 `composer install` 和 `migrate`
4. 清理缓存并重启队列 worker

如果备份没成功，它**不会动任何代码**。如果你在服务器上手动改过某个文件，它会**停止执行**，不会覆盖任何内容。

---

## 🔧 维护

### Cron（计划任务）

没有它，队列不会被处理，账号也不会按时到期。`install.sh` 会自动安装：

```
* * * * * www-data cd /var/www/shahpanel && php artisan schedule:run >> /dev/null 2>&1
```

这一行就够了 —— **不需要单独的队列服务**，因为调度器每分钟会自己执行一次
`queue:work --stop-when-empty`。

在面板中检查其健康状态：**设置 → 自动化 → Cron 任务**

### 自动执行的任务

| 任务 | 频率 |
|:--|:--|
| 同步账号流量用量（`sync:usage`） | 每 5 分钟 |
| 检查到期并停用账号（`accounts:check-expiry`） | 每 10 分钟 |
| 处理队列（`queue:work`） | 每分钟 |
| 采集路由器指标并检测配置漂移 | 每 1 到 5 分钟 |
| 清理已过期的防火墙封禁 | 每 15 分钟 |
| 发送告警（`alerts:dispatch`） | 每小时 |
| 备份数据库（`backup:database`） | 每晚 02:00 |
| 日报（`reports:daily-rollup`） | 每晚 00:00 |
| 自动关闭工单 | 每天 |
| 清理隧道指标 | 每晚 03:30 |
| 更新国家 IP 段 | 每周一 03:30 |

> ⚠️ 夜间备份每晚都会往 `storage` 中新增一个文件。请定期检查磁盘空间，
> 并删除旧文件。

### 备份与迁移到新服务器

```bash
# 创建备份并发送到另一台服务器
bash scripts/db-migrate.sh backup --send root@newhost:/root/

# 在新服务器上恢复
bash scripts/db-migrate.sh restore --latest
```

> 🔑 务必把 `.env` 文件一并迁移。没有**原来的** `APP_KEY`，已保存的路由器用户名和密码将无法解密。

### 路由缓存（可选，用于提速）

```bash
cd /var/www/shahpanel
sudo -u www-data php artisan route:cache
```

各面板的登录路径是可修改的，并且从数据库读取，因此会被冻结进缓存。面板会自己处理这一点：每次在安全设置中修改路径后，缓存都会被重建。

---

## 🆘 故障排查

### 面板起不来

有一个不依赖 Laravel 的诊断页面，可以显示数据库状态、文件权限和最近的错误，并且能清理缓存或执行 migration。

由于它的操作有风险，**默认是关闭的**，对所有人返回 404。要打开它，请在 `.env` 中设置一个令牌：

```bash
cd /var/www/shahpanel
echo "MAINTAIN_TOKEN=$(php -r 'echo bin2hex(random_bytes(16));')" >> .env
```

然后访问：`https://panel.example.com/maintain.php?key=<token>`

> 问题解决后，务必把 `MAINTAIN_TOKEN` 清空，让该页面重新关闭。只要这个值为空，该地址对所有人都是 404。

### 常见故障

<details>
<summary><b>服务器重启后，面板返回 502</b></summary>

<br>

通常是 MySQL 或 PHP-FPM 没有启动：

```bash
systemctl start mysql php8.3-fpm nginx
systemctl enable mysql php8.3-fpm nginx   # 下次自动启动
```

</details>

<details>
<summary><b>账号不到期 / 流量用量不更新</b></summary>

<br>

Cron 没有运行。请检查：

```bash
systemctl status cron
cat /etc/cron.d/shahpanel-scheduler
cd /var/www/shahpanel && sudo -u www-data php artisan schedule:run
```

</details>

<details>
<summary><b>白屏或 500 错误</b></summary>

<br>

```bash
tail -50 /var/www/shahpanel/storage/logs/laravel.log
cd /var/www/shahpanel && sudo -u www-data php artisan optimize:clear
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

</details>

<details>
<summary><b>我改了面板的登录路径，现在进不去了</b></summary>

<br>

从数据库中读取当前路径：

```bash
cd /var/www/shahpanel
sudo -u www-data php artisan tinker --execute="echo App\Models\Setting::getValue('portal_path_admin');"
```

恢复为默认值：

```bash
sudo -u www-data php artisan tinker --execute="App\Models\Setting::setValue('portal_path_admin','admin');"
sudo -u www-data php artisan optimize:clear
```

> `tinker` 属于开发依赖。如果你是用 `composer install --no-dev` 安装的，请从 `.env` 中读取 `VPN_ADMIN_PATH` 的值。

</details>

<details>
<summary><b>连不上 MikroTik 服务器</b></summary>

<br>

- 路由器上的 API 服务要已启用：`/ip service enable api`
- 端口（默认 `8728`，SSL 为 `8729`）要对面板服务器开放
- RouterOS 用户要具备 `api` 权限
- 如果有防火墙，请放行面板服务器的 IP

</details>

<details>
<summary><b>连接 3x-ui 或 Remnawave 面板时出现 SSL 证书错误</b></summary>

<br>

面板默认会**校验**远程服务器的 TLS 证书，因为每个请求都会发送对方面板的管理员
用户名和密码。

如果目标面板使用自签名证书，请在该服务器的编辑页面中取消勾选「校验面板 SSL 证书」。
该设置按服务器单独生效。

</details>

---

## ❓ 常见问题

<details>
<summary><b>能装在虚拟主机（cPanel / DirectAdmin）上吗？</b></summary>

<br>

不支持。面板需要分钟级 cron、后台队列以及直连路由器 API。一台便宜的 VPS 就足够了。

</details>

<details>
<summary><b>我可以添加多少台服务器？</b></summary>

<br>

没有限制。每台服务器都有自己独立的账号数量上限，可在服务器页面中设置。

</details>

<details>
<summary><b>可以同时使用 MikroTik 和 Remnawave 吗？</b></summary>

<br>

可以。每个套餐绑定一种服务类型，面板会自动在正确的服务器上创建账号。

</details>

<details>
<summary><b>如果我修改了 APP_KEY 会怎样？</b></summary>

<br>

所有已保存的路由器和面板的用户名与密码都将**无法恢复**。千万不要修改它，并且要备份 `.env`。

</details>

<details>
<summary><b>面板会向外部发送哪些信息？</b></summary>

<br>

没有。唯一的对外通信是连接你自己的服务器、你所启用的支付网关，以及为防火墙下载国家 IP 段。两步验证的二维码也是在本服务器上生成的。

</details>

---

## 🛠 开发

```bash
git clone https://github.com/shahinst/shahpanel.git shahpanel
cd shahpanel

composer install
cp .env.example .env && php artisan key:generate
php artisan migrate

npm ci && npm run build     # 仅在修改了 UI 时需要
php artisan serve
```

### 项目结构

```
app/Services/           领域逻辑（账号、钱包、服务器、远程面板）
app/Services/RouterOs/  MikroTik 的 desired-state 层
app/Services/Tunneling/ 路由器之间的隧道编排器
app/Http/Controllers/   控制器，按角色划分
resources/views/        用户界面（Blade + Tailwind + Chart.js）
database/migrations/    89 个 migration
modules/                模块（隧道、支付、迁移）
scripts/panel-firewall  防火墙 root 助手程序（ipset/iptables）
scripts/db-migrate.sh   数据库备份与迁移到新服务器
ops/security-shield/    安装 CrowdSec + fail2ban + ClamAV
docs/CRM_TUNNELING.md   隧道搭建文档（Tunnel Groups 与 CRM Tunnels）
docs/fa/                管理员、代理商、销售商指南及 Remnawave 配置说明
tools/maintain-core.php 独立于 Laravel 的诊断页面（需令牌）
```

---

## ⚠️ 重要提示

| | |
|:--|:--|
| 🔑 | **请认真对待 `APP_KEY`。** 它是所有已保存凭据的解密密钥。请备份 `.env`。 |
| 🔒 | `install.sh` 会把 `.env` 的属主设为 `root:www-data`、权限设为 `640`，使服务器上的其他用户无法读取。 |
| 🌐 | 在 HTTPS 安装上务必设置 `SESSION_SECURE_COOKIE=true`（自动安装会自行设置）。 |
| 📁 | document root 必须指向 `public/`。如果指向项目根目录，就必须有根目录下的 `.htaccess` 文件 —— 没有它，`.env` 和 `.git/` 可以从 Web 被读取。 |
| 🧹 | 问题解决后，请清空 `MAINTAIN_TOKEN`，让维护页面重新关闭。 |

---

## 💚 支持本项目

如果这个面板对你有帮助，并且你愿意为它的开发出一份力：

<a href="https://nowpayments.io/donation?api_key=1b2c76da-3f32-4887-a5e3-4e3340417001" target="_blank" rel="noreferrer noopener">
   <img src="https://nowpayments.io/images/embeds/donation-button-black.svg" alt="Crypto donation button by NOWPayments">
</a>

每一份支持都会用于开发新功能、修复缺陷以及支持更多面板。🙏

如果无力提供资金支持，给仓库点 ⭐ 和提交 bug 报告同样是很大的帮助。

---

## 📄 许可证

本项目为**专有软件（proprietary）**。未经书面许可，不得使用、再分发或销售。

<div align="center">
<br>

**为波斯语地区的销售商与代理商打造**

如果它对你有用，请点 ⭐

</div>
