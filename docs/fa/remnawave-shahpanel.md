# راه‌اندازی سرور Remnawave در shahpanel

ساختار مشابه **ثنایی (Sanaei)** و **پاسارگارد (PasarGuard)** است: سرور پنل → تست اتصال → سینک squad/inbound → پکیج با squad → ساخت اکانت روی پنل.

---

## ۱) پیش‌نیاز

1. پنل Remnawave نسخه **۲.x** نصب و در دسترس باشد.
2. از بخش **API Tokens** یک توکن دائمی بسازید (یا نام‌کاربری/رمز ادمین برای login).
3. migration را اجرا کنید: `php artisan migrate` یا `maintain.php` (فیلدهای `remnawave_*`).

---

## ۲) تعریف سرور (ادمین → سرورها)

| فیلد | مقدار |
|------|--------|
| **نوع** | `Remnawave` |
| **Host** | دامنه پنل بدون `/api` — مثلاً `panel.example.com` |
| **Port** | معمولاً `443` |
| **مسیر پایه (web_base_path)** | فقط اگر پشت Caddy روی مسیر مخفی است |
| **توکن API** | توکن دائمی → فیلد «توکن API پنل» (`api_token_enc`) |
| **X-Api-Key** | فقط در صورت Caddy با کلید جدا |

سپس **تست اتصال** — باید squadها و تعداد inbound/user نمایش داده شود. squadها در `servers.remnawave_squads` ذخیره می‌شوند.

**دریافت squad / inbound / node:** در صفحه **افزودن/ویرایش سرور** (نوع Remnawave) یا صفحه مدیریت سرور — دکمه **«سینک squad و node»** (همان مسیر `sync-inbounds`). squadها در `remnawave_squads` و nodeها در `remnawave_nodes` ذخیره می‌شوند.

### تنظیم سرور Remnawave در shahpanel

| فیلد | مقدار |
|------|--------|
| میزبان | `admin.pvline.ir` یا `https://admin.pvline.ir` — **بدون** `/api` در انتها |
| توکن API | توکن از **API Tokens** (همان JWT که با `eyJ` شروع می‌شود) |
| نام کاربری/رمز | اختیاری |

اگر توکن را در فیلد «کلید Caddy» گذاشته‌اید، بعد از به‌روزرسانی shahpanel با ذخیرهٔ دوباره سرور خودکار به «توکن API» منتقل می‌شود.

---

## ۳) تعریف پکیج

1. **نوع سرویس:** `پنل Remnawave`
2. **سرور:** فقط سرورهای نوع `remnawave`
3. **Squadها:** حداقل یک squad از لیست کش‌شده (بعد از تست اتصال)
4. **استراتژی ریست ترافیک:** `NO_RESET` / `DAY` / `WEEK` / `MONTH`

---

## ۴) جریان اکانت

- **ساخت:** `AccountService` کاربر را با `POST /api/users/` می‌سازد؛ `remnawave_uuid` و `remnawave_subscription_url` ذخیره می‌شود.
- **تمدید / تغییر حجم:** `PATCH /api/users` با UUID.
- **غیرفعال / فعال:** از طریق modify با `status` یا actions پنل.
- **سینک ترافیک:** `usedTrafficBytes` از پنل خوانده می‌شود.
- **پورتال:** لینک اشتراک از `remnawave_subscription_url`.

پیشوند نام‌کاربری اکانت: `rw-`

---

## ۵) لاگ و عیب‌یابی

- لاگ اختصاصی: `storage/logs/remnawave.log`
- بعد از تست اتصال، **لاگ عملیات** در صفحهٔ سرور جزئیات URL و squadها را نشان می‌دهد.
- خطاهای رایج: توکن اشتباه، فایروال پورت، SSL، مسیر مخفی Caddy بدون `web_base_path` / `X-Api-Key`.

---

## ۶) تفاوت با PasarGuard / Sanaei

| موضوع | Remnawave | PasarGuard | Sanaei |
|--------|-----------|------------|--------|
| گروه‌بندی | Internal Squad (UUID) | Group (ID) | Inbound ID |
| خروجی کاربر | `subscriptionUrl` | بسته به پنل | لینک ساب / کانفیگ |
| API | `/api` + envelope `response` | `/api` | `/panel/api` و … |
| Import کاربر | هنوز از UI import جدا پشتیبانی نشده | دارد | دارد |

مستندات کامل API: [remnawave-api.md](./remnawave-api.md)
