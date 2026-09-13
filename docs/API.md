# Reseller API v1

مستندات API پنل برای اتصال ربات‌های تلگرامی (میرزا، دیبات و مشابه).

**Base URL:** `https://your-domain.example/api/v1`

هر پاسخ یک پوشش ثابت دارد، پس ربات فقط کافی است `ok` را بررسی کند:

```json
{ "ok": true,  "data": { }, "meta": { } }
{ "ok": false, "error": { "code": "not_found", "message": "..." } }
```

---

## احراز هویت

دو راه برای گرفتن توکن:

1. **از پنل** — نماینده/فروشنده وارد پنل می‌شود → منوی «تنظیمات» → «API و ربات» → ساخت توکن.
2. **از API** — با نام کاربری و رمز خودِ پنل:

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "username": "reseller1", "password": "...", "device_name": "my-bot" }
```

اگر کاربر ورود دومرحله‌ای فعال دارد، `two_fa_code` هم لازم است.

پاسخ (`201`):

```json
{ "ok": true, "data": {
    "token": "mp_xxxxxxxxxxxxxxxx",
    "token_id": 12,
    "expires_at": null,
    "user": { "id": 2, "username": "amir", "role": "agent",
              "wallet": { "balance": "2495046.28", "currency": "IRT" } }
}}
```

توکن را در همهٔ درخواست‌های بعدی بفرست:

```
Authorization: Bearer mp_xxxxxxxxxxxxxxxx
```

> اگر فریم‌ورک رباتت هدر سفارشی نمی‌فرستد، می‌توانی به‌جایش پارامتر `api_token` را در query یا body بگذاری.

### نقش‌ها

فقط **نماینده (agent)** و **فروشنده (seller)** توکن می‌گیرند. ادمین و مشتری نه.

- فروشنده: فقط اکانت‌های خودش
- نماینده: خودش + کل زیرمجموعه‌اش، به‌علاوهٔ بخش `/resellers`

### دسترسی‌ها (abilities)

توکن می‌تواند محدود شود. اگر هیچ دسترسی انتخاب نشود، توکن به همه‌چیز دسترسی دارد.

| دسترسی | کاربرد |
|---|---|
| `accounts:read` | دیدن اکانت‌ها، کانفیگ، مصرف |
| `accounts:create` | فروش اکانت جدید |
| `accounts:renew` | تمدید |
| `accounts:update` | فعال/غیرفعال کردن |
| `catalog:read` | پکیج‌ها، سرورها، قیمت |
| `wallet:read` | کیف پول و تراکنش‌ها |
| `resellers:read` | زیرمجموعه‌ها (فقط نماینده) |
| `stats:read` | آمار داشبورد |

می‌توان از `accounts:*` برای کل یک گروه استفاده کرد.

### مدیریت توکن

```http
GET    /auth/me                  # کاربر + کیف پول + مشخصات توکن فعلی
POST   /auth/logout              # ابطال همین توکن
GET    /auth/tokens              # لیست توکن‌ها
POST   /auth/tokens              # ساخت توکن جدید (مثلاً فقط‌خواندنی)
DELETE /auth/tokens/{id}         # ابطال
```

---

## کاتالوگ

### پکیج‌های قابل فروش شما

```http
GET /catalog/packages
```

قیمت‌ها **مخصوص خود شماست** (قیمت عمدهٔ شما، نه قیمت پایهٔ کاتالوگ):

```json
{ "ok": true, "data": [{
    "id": 3, "name": "v2ray مستقیم", "service_type": "pasarguard",
    "is_elastic": true, "min_data_gb": 1, "max_data_gb": 500,
    "available_for_new_accounts": true,
    "durations": [
      { "id": 19, "tier": "1m", "unit_price": "3900.00",
        "purchase_total": "3900.00", "renewal_total": "3900.00" }
    ]
}]}
```

- `is_elastic: true` یعنی پکیج حجمی است و هنگام فروش باید `data_gb` بدهی (بین `min_data_gb` و `max_data_gb`).
- نماینده می‌تواند `?seller_id=` بدهد تا قیمت یکی از فروشنده‌هایش را ببیند.

### سرورها

```http
GET /catalog/servers?package_id=3
```

با `package_id` فقط سرورهایی برمی‌گردند که آن پکیج اجازه دارد.

### قیمت یک دوره

```http
GET /catalog/price?package_duration_id=19&data_gb=50
```

---

## اکانت‌ها

### لیست

```http
GET /accounts?status=active&per_page=25&sort=expiry
```

فیلترها: `status`، `service_type`، `package_id`، `server_id`، `seller_id` (نماینده)،
`search` (نام‌کاربری/برچسب/ایمیل)، `expiring_within_days`، `sort` = `newest|oldest|expiry`.

پاسخ صفحه‌بندی‌شده:

```json
{ "ok": true, "data": [ ... ], "meta": { "pagination": {
    "total": 1037, "per_page": 25, "current_page": 1,
    "last_page": 42, "has_more": true }}}
```

### یک اکانت

```http
GET /accounts/{id}
GET /accounts/{username}      # با نام کاربری هم کار می‌کند
```

### قیمت قبل از فروش

```http
GET /accounts/preview?package_id=3&package_duration_id=19&data_gb=50
```

برای نمایش قیمت به مشتری، قبل از کسر از کیف پول.

### فروش اکانت جدید

```http
POST /accounts
Content-Type: application/json

{
  "package_id": 3,
  "package_duration_id": 19,
  "data_gb": 50,
  "server_id": 8,
  "remote_username": "customer123",
  "client_email": "customer@example.com"
}
```

- `server_id` اختیاری است؛ اگر ندهی، کم‌بارترین سرور مجاز خودکار انتخاب می‌شود.
- `data_gb` فقط برای پکیج‌های حجمی لازم است.
- مبلغ از کیف پول شما کسر و پورسانت‌ها دقیقاً مثل فروش از خود پنل تسویه می‌شود.
- نماینده می‌تواند `owner_seller_id` بدهد تا به‌نام یکی از فروشنده‌هایش بفروشد.

پاسخ `201` شامل اکانت ساخته‌شده است.

### کانفیگ مشتری

```http
GET /accounts/{id}/config
```

هرچه مشتری برای اتصال لازم دارد:

```json
{ "ok": true, "data": {
    "wireguard": { "config": "[Interface]\n...", "qr_base64": "iVBORw0...", "error": null },
    "v2ray": { "subscription_link": "https://client.pvline.ir/xxxx", "subscription_qr": "..." },
    "ppp": { ... },
    "usage": { "used_bytes": 0, "limit_bytes": null, "remaining_bytes": null }
}}
```

بسته به نوع سرویس، یکی از این بخش‌ها پر می‌شود.

### مصرف

```http
GET /accounts/{id}/usage
GET /accounts/{id}/usage?refresh=1     # خواندن زندهٔ مصرف از پنل سرور
```

`refresh=1` کندتر است چون به سرور ریموت وصل می‌شود — برای دکمهٔ «بروزرسانی» مناسب است، نه برای لیست.

### تمدید

```http
POST /accounts/{id}/renew
{ "package_duration_id": 19, "renewal_mode": "same" }
```

`renewal_mode`:

| مقدار | معنی |
|---|---|
| `same` | همان حجم قبلی، مصرف **صفر** می‌شود (دورهٔ تازه) |
| `add_volume` | حجم اضافه می‌شود، مصرف **حفظ** می‌شود (`data_gb` لازم است) |
| `upgrade_volume` | حجم کل به عدد بالاتری می‌رود، مصرف حفظ می‌شود |

> در حالت `add_volume` مجموع حجم نباید از `max_data_gb` پکیج بیشتر شود.

### فعال / غیرفعال

```http
POST /accounts/{id}/enable
POST /accounts/{id}/disable
```

---

## کیف پول

```http
GET /wallet
GET /wallet/transactions?per_page=50&from=2026-08-01
```

> شارژ کیف پول از طریق API ممکن نیست — در خود پنل هم فقط ادمین می‌تواند موجودی را تغییر دهد.

---

## زیرمجموعه‌ها (فقط نماینده)

```http
GET /resellers?search=ali
GET /resellers/{id}
GET /resellers/{id}/accounts
```

---

## آمار

```http
GET /stats/dashboard
```

```json
{ "ok": true, "data": {
    "wallet": { "balance": "2495046.28", "currency": "IRT" },
    "accounts": { "total": 1037, "active": 438, "expired": 189,
                  "expiring_7d": 77, "created_today": 2 },
    "panel": { "sellers": 12, "accounts_wireguard": 633, ... }
}}
```

---

## خطاها

| کد HTTP | `error.code` | معنی |
|---|---|---|
| 401 | `unauthenticated` | توکن نیامده یا نامعتبر است |
| 403 | `ability_missing` | توکن این دسترسی را ندارد |
| 403 | `role_forbidden` | نقش شما اجازه ندارد |
| 403 | `ip_not_allowed` | آی‌پی در فهرست مجاز توکن نیست |
| 404 | `not_found` | وجود ندارد **یا** متعلق به شما نیست |
| 422 | — | خطای اعتبارسنجی (کلید `errors` استاندارد لاراول) |
| 429 | `rate_limited` | سقف درخواست پر شده؛ `Retry-After` را ببین |

> ۴۰۴ عمداً بین «نبود» و «مال شما نیست» فرق نمی‌گذارد تا شناسه‌های دیگران قابل حدس‌زدن نباشد.

## محدودیت نرخ

پیش‌فرض **۱۲۰ درخواست در دقیقه** برای هر توکن (هنگام ساخت توکن قابل تنظیم بین ۱۰ تا ۶۰۰).
اندپوینت `login` جدا و سخت‌گیرانه‌تر است (۵ تلاش ناموفق ⇒ قفل ۱۵ دقیقه‌ای).

هدرهای `X-RateLimit-Limit` و `X-RateLimit-Remaining` در هر پاسخ می‌آیند.

## امنیت

- توکن‌ها فقط به‌صورت `sha256` ذخیره می‌شوند؛ متن اصلی فقط یک‌بار هنگام ساخت نمایش داده می‌شود.
- هر توکن می‌تواند به فهرستی از IP یا رنج CIDR محدود شود.
- همهٔ کوئری‌ها با همان اسکوپ سلسله‌مراتبی خود پنل فیلتر می‌شوند؛ API مسیر جداگانه‌ای برای دور زدن قوانین ندارد.
- ابطال توکن فوری است.
