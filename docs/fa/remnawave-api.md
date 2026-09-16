# مستندات API پنل Remnawave (برای پیاده‌سازی در لاراول)

> منبع قرارداد: `@remnawave/backend-contract` — مسیرها با پیشوند `/api`.
> نسخه **۲.x** (در ۱.x مسیر اینباند `GET /api/inbounds` است).

---

## ۰) مفاهیم پایه

| مورد | توضیح |
|------|--------|
| **Base URL** | آدرس پنل، مثلاً `https://panel.example.com` یا داخل داکر `http://remnawave:3000` |
| **پیشوند API** | همهٔ مسیرها: `{Base URL}{مسیر مخفی اختیاری}/api/...` |
| **پاکت پاسخ** | تقریباً همیشه: `{ "response": { ... } }` — دادهٔ اصلی داخل `response` |
| **احراز هویت** | `Authorization: Bearer <TOKEN>` روی همهٔ درخواست‌ها به‌جز `POST /api/auth/login` |
| **هدرها** | `Content-Type: application/json` و `Accept: application/json` |
| **Caddy** | اگر پشت Caddy با مسیر مخفی + API key: Base URL شامل مسیر مخفی + هدر `X-Api-Key: <key>` |

### دو روش توکن

1. **توکن دائمی (پیشنهادی سرور-به-سرور)** — از پنل → **API Tokens**؛ مستقیم در `Authorization`. بدون login.
2. **ورود ادمین** — `POST /api/auth/login` با `username` / `password` → `response.accessToken` (JWT).

---

## ۱) وضعیت و ورود

### `GET /api/auth/status`

بررسی ثبت ادمین، روش‌های ورود فعال، نسخهٔ پنل (در پاسخ ممکن است فیلد `version` باشد).

### `POST /api/auth/login`

**بدنه:**
```json
{
  "username": "admin",
  "password": "your-password"
}
```

**پاسخ:**
```json
{
  "response": {
    "accessToken": "eyJhbGciOiJI..."
  }
}
```

---

## ۲) اینباندها و Config Profiles (نسخه ۲.x)

در ۲.x اینباندها زیر **Config Profiles** هستند.

| متد | مسیر | توضیح |
|-----|------|--------|
| GET | `/api/config-profiles/inbounds` | همهٔ اینباندها |
| GET | `/api/config-profiles` | لیست پروفایل‌ها |
| GET | `/api/config-profiles/{uuid}` | یک پروفایل |
| GET | `/api/config-profiles/{uuid}/inbounds` | اینباندهای یک پروفایل |

**فیلدهای معمول هر inbound:** `uuid`, `tag`, `type` (`vless` / `trojan` / `shadowsocks` / …), `port`, وابستگی به پروفایل.

> **نسخه ۱.x:** اگر `GET /api/config-profiles/inbounds` برگرداند **404**، از `GET /api/inbounds` استفاده کنید.

---

## ۲-ب) Internal Squads (الزامی برای فعال‌سازی کاربر روی inbound)

| متد | مسیر | توضیح |
|-----|------|--------|
| GET | `/api/internal-squads` | لیست اسکوادها با `uuid` و `name` |

UUIDها در فیلد **`activeInternalSquads`** هنگام ساخت/ویرایش کاربر ارسال می‌شوند. بدون squad مناسب، کاربر ممکن است روی inboundها فعال نشود.

**نمونه پاسخ (ساختار متداول):**
```json
{
  "response": {
    "internalSquads": [
      { "uuid": "b2c1-...", "name": "Default Squad" }
    ]
  }
}
```
(گاهی آرایه مستقیم یا کلید `squads` — کلاینت shahpanel هر سه حالت را نرمال می‌کند.)

---

## ۳) کاربران — لیست

### `GET /api/users`

| Query | نوع | توضیح |
|-------|-----|--------|
| `size` | int | تعداد در صفحه (مثلاً 100) |
| `start` | int | آفست (0، 100، 200، …) |

**پاسخ:**
```json
{
  "response": {
    "total": 1234,
    "users": [ { /* آبجکت کاربر */ } ]
  }
}
```

برای دریافت همه: تا وقتی `len(users) < total` است، `start += size`.

---

## ۴) ساخت کاربر

### `POST /api/users/`  ← **اسلش انتهایی اجباری**

| فیلد | نوع | اجباری | توضیح |
|------|-----|:------:|--------|
| `username` | string | ✅ | بدون فاصله |
| `expireAt` | ISO 8601 UTC | ✅ | مثل `2025-12-31T23:59:59.000Z` |
| `status` | enum | – | `ACTIVE` \| `DISABLED` \| `LIMITED` \| `EXPIRED` (پیش‌فرض `ACTIVE`) |
| `trafficLimitBytes` | number | – | بایت؛ `0` = نامحدود |
| `trafficLimitStrategy` | enum | – | `NO_RESET` \| `DAY` \| `WEEK` \| `MONTH` |
| `activeInternalSquads` | string[] | – | UUID اسکوادها |
| `description` | string | – | توضیح |
| `tag` | string\|null | – | برچسب |
| `email` | string\|null | – | |
| `telegramId` | number\|null | – | |
| `hwidDeviceLimit` | number | – | سقف دستگاه |
| `shortUuid` | string | – | اختیاری؛ خودکار |
| `vlessUuid` | string | – | اختیاری؛ خودکار |
| `trojanPassword` | string | – | اختیاری؛ خودکار |
| `ssPassword` | string | – | Shadowsocks |

**نمونه:**
```json
{
  "username": "test_user_001",
  "expireAt": "2025-12-31T23:59:59.000Z",
  "status": "ACTIVE",
  "trafficLimitBytes": 53687091200,
  "trafficLimitStrategy": "MONTH",
  "activeInternalSquads": ["b2c1...uuid..."],
  "description": "ساخته‌شده از shahpanel"
}
```

**پاسخ — فیلدهای مهم:**
```json
{
  "response": {
    "uuid": "...",
    "shortUuid": "...",
    "username": "test_user_001",
    "status": "ACTIVE",
    "usedTrafficBytes": 0,
    "lifetimeUsedTrafficBytes": 0,
    "trafficLimitBytes": 53687091200,
    "trafficLimitStrategy": "MONTH",
    "expireAt": "2025-12-31T23:59:59.000Z",
    "subscriptionUrl": "https://panel.example.com/sub/....",
    "vlessUuid": "...",
    "trojanPassword": "...",
    "ssPassword": "...",
    "happ": { "cryptoLink": "happ://..." },
    "activeInternalSquads": [ { "uuid": "...", "name": "..." } ],
    "createdAt": "...",
    "updatedAt": "..."
  }
}
```

برای تحویل به کاربر نهایی معمولاً **`subscriptionUrl`** کافی است.

---

## ۵) خواندن / ویرایش / حذف کاربر

| عملیات | متد | مسیر |
|--------|-----|------|
| با UUID | GET | `/api/users/{uuid}` |
| با نام‌کاربری | GET | `/api/users/by-username/{username}` |
| با تلگرام | GET | `/api/users/by-telegram-id/{telegramId}` |
| ویرایش | PATCH | `/api/users` — بدنه شامل `uuid` + فیلدهای تغییر |
| حذف | DELETE | `/api/users/{uuid}` |
| فعال | POST | `/api/users/{uuid}/actions/enable` |
| غیرفعال | POST | `/api/users/{uuid}/actions/disable` |
| صفر ترافیک | POST | `/api/users/{uuid}/actions/reset-traffic` |

ساختار پاسخ GET همان آبجکت بخش ۴ است (داخل `response`).

---

## ۶) خطاها

- HTTP غیر 2xx: معمولاً بدنه شامل `message` یا `error`.
- در shahpanel با `->throw()` و کانال لاگ `storage/logs/remnawave.log` ثبت می‌شود.
- timeout / DNS / SSL: پیام‌های فارسی در `RemoteConnectionException`.

---

## ۷) نگاشت به shahpanel (پیاده‌سازی فعلی)

| لایه | کلاس / مسیر |
|------|----------------|
| HTTP | `App\Services\Remnawave\RemnawavePanelClient` |
| سرویس | `App\Services\RemnawaveService` |
| payload اکانت | `App\Services\Remnawave\RemnawaveUserPayloadBuilder` |
| کش squad | `App\Services\Remnawave\RemnawaveSquadCatalog` |
| URL | `App\Services\Remnawave\RemnawavePanelUrl` |
| نوع سرور | `ServerType::Remnawave` |
| نوع پکیج | `ServiceType::Remnawave` |
| تنظیمات | `config/shahpanel.php` → `remnawave.*` |

فیلدهای دیتابیس:
- **servers:** `remnawave_squads`, `remnawave_squads_synced_at`, `remnawave_api_key_enc`, `api_token_enc` (توکن Bearer)
- **packages:** `remnawave_squads` (آرایه UUID), `remnawave_traffic_strategy`
- **accounts:** `remnawave_uuid`, `remnawave_subscription_url`

راهنمای راه‌اندازی در پنل: [remnawave-shahpanel.md](./remnawave-shahpanel.md)

---

## ۸) Helperهای پیشنهادی (Carbon)

```php
use Illuminate\Support\Carbon;

// انقضا ۳۰ روز بعد
$expireAt = now()->addDays(30)->utc()->toIso8601ZuluString('millisecond');

// گیگابایت → بایت
$bytes = (int) round($gb * (1024 ** 3));
```

---

## ۹) نمونه کلاینت لاراول (خلاصه)

```php
$client = Http::baseUrl($baseUrl.'/api')
    ->withToken($token)
    ->acceptJson()
    ->asJson();

// اختیاری Caddy
if ($apiKey) {
    $client = $client->withHeaders(['X-Api-Key' => $apiKey]);
}

$user = $client->post('/users/', $payload)->throw()->json('response');
```

در shahpanel به‌جای این، از `RemnawaveService::createPanelUser()` استفاده کنید تا squadها، حجم و انقضا از پکیج پر شوند.

---

## ۱۰) پرامپت برای AI / توسعه‌دهنده

```
پنل Remnawave 2.x — REST، پیشوند /api، پاسخ در کلید response.
احراز: Bearer از API Tokens یا login → accessToken.
ساخت کاربر: POST /users/ با username + expireAt (ISO Z)؛ activeInternalSquads از GET /internal-squads.
اینباند: GET /config-profiles/inbounds (fallback /inbounds).
لیست کاربر: GET /users?size=&start= با صفحه‌بندی.
ویرایش: PATCH /users با uuid در بدنه.
در shahpanel این‌ها در RemnawavePanelClient و RemnawaveService پیاده شده‌اند.
```
