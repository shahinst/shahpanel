# Cisco AnyConnect — API / پروتکل و یکپارچه‌سازی پنل

## واقعیت پروتکل

خود کلاینت **Cisco AnyConnect / Secure Client** API عمومی برای ساخت کاربر ندارد.
اکانت‌های Remote Access روی **Cisco ASA / Secure Firewall** مدیریت می‌شوند و پنل از **ASA REST API** استفاده می‌کند.

| لایه | نقش |
|------|-----|
| AnyConnect client | فقط اتصال SSL/DTLS به portal |
| ASA / Secure Firewall | احراز هویت، Local User DB، Group Policy، Tunnel Group |
| پنل vpnpanel | CRUD کاربر از طریق REST + CLI روی ASA |

## پیش‌نیاز روی ASA

```
rest-api image disk0:/asa-restapi-….zip
rest-api agent enable
http server enable
http 0.0.0.0 0.0.0.0 <mgmt-interface>
aaa authentication http console LOCAL
username apiadmin password **** privilege 15
http server basic-auth-client REST API Agent
```

کاربر API باید **privilege 15** داشته باشد (برای POST/PUT/DELETE).

## احراز هویت REST

1. **Basic Auth** روی هر درخواست، یا
2. `POST /api/tokenservices` → هدر `X-Auth-Token` برای درخواست‌های بعدی

هدر اجباری: `User-Agent: REST API Agent`

Base URL: `https://{mgmt-host}:{port}/api`

## نقاط پایانی استفاده‌شده

| متد | مسیر | کاربرد |
|-----|------|--------|
| GET | `/api/objects/localusers` | لیست / تست اتصال |
| POST | `/api/objects/localusers` | ساخت کاربر لوکال (در صورت پشتیبانی نسخه) |
| PUT/DELETE | `/api/objects/localusers/{name}` | به‌روزرسانی / حذف |
| POST | `/api/cli` | اعمال attributeهای VPN و fallback کامل |

بدنه CLI:

```json
{ "commands": ["username u1 password Secret privilege 0", "username u1 attributes", "service-type remote-access", "vpn-group-policy DefaultRAGroup", "vpn-simultaneous-logins 1", "exit"] }
```

## attributeهای VPN (AnyConnect RA)

```
username <name> password <pass> privilege 0
username <name> attributes
 service-type remote-access
 vpn-group-policy <GroupPolicy>
 vpn-tunnel-protocol ssl-client
 vpn-simultaneous-logins <N>
 group-lock value <TunnelGroup>   ! اختیاری
```

غیرفعال‌سازی بدون حذف: `vpn-simultaneous-logins 0`  
فعال‌سازی مجدد: بازگرداندن تعداد لاگین همزمان

## فیلدهای پنل (اختصاصی — بدون ترکیب با MikroTik/V2Ray)

### ServerType = `cisco_anyconnect`

- `host` / `port` — آدرس مدیریت HTTPS ASA (معمولاً 443)
- `username_enc` / `password_enc` — ادمین privilege 15
- `cisco_vpn_hostname` — آدرسی که به مشتری برای اتصال داده می‌شود (می‌تواند با host مدیریت فرق کند)
- `cisco_group_policy` / `cisco_tunnel_group`
- `cisco_verify_ssl` — برای گواهی self-signed معمولاً خاموش
- `cisco_write_memory` — `write memory` بعد از تغییرات
- `cisco_simultaneous_logins`

### ServiceType = `cisco_anyconnect` → AccountCategory = `anyconnect`

پکیج فقط به سرورهای `cisco_anyconnect` وصل می‌شود. منوی اکانت جدا: **اکانت Cisco AnyConnect**.

## محدودیت‌ها

- سهمیه ترافیک per-user از Local DB ASA معمولاً در REST در دسترس نیست؛ محدودیت حجم/انقضا در پنل اعمال و با disable روی ASA همگام می‌شود.
- RADIUS/LDAP خارجی در این نسخه پشتیبانی نمی‌شود (فقط Local User).
- FMC / Duo / ISE API جدا هستند و در این اسلایس استفاده نشده‌اند.
