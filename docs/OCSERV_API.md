# ocserv / OpenConnect — API / پروتکل و یکپارچه‌سازی پنل

## واقعیت پروتکل

پنل **مستقیماً با باینری ocserv حرف نمی‌زند**. روی سرور VPN، کنار ocserv یک **سرویس مدیریتی HTTP JSON** اجرا می‌شود و پنل فقط با همان API صحبت می‌کند.

| لایه | نقش |
|------|-----|
| OpenConnect / AnyConnect client | فقط اتصال کاربر نهایی (معمولاً پورت 443) |
| ocserv | احراز هویت، نشست‌ها، مسیریابی و تونل |
| Management API | لایه HTTP JSON روی ocserv برای CRUD کاربر و گزارش نشست/ترافیک |
| پنل ShahPanel | فراخوانی REST روی Management API |

این نوع سرور از **Cisco ASA** (`cisco_anyconnect`) کاملاً جداست. مقدار نوع سرور: `ocserv`.

## پیش‌نیاز روی سرور VPN

- سرویس Management API در حال اجرا و گوش‌دادن روی `https://{host}:{api_port}` — پورت پیش‌فرض **9443**.
- یک نام کاربری و یک **API token** برای احراز هویت Basic.
- گواهی TLS معتبر (معمولاً Let's Encrypt) — بررسی گواهی به‌صورت پیش‌فرض **روشن** است.
- دسترسی به پورت API با فایروال **فقط به IP سرور پنل** محدود شود؛ در غیر این صورت ممکن است پاسخ `403` برگردد.
- اتصال کلاینت VPN جداست و معمولاً روی پورت 443 انجام می‌شود.

## احراز هویت REST

**HTTP Basic** روی هر درخواست: نام کاربری + رمز، که در آن **رمز همان API token** است.

هدرهای اجباری:

```
Authorization: Basic base64(username:api_token)
Accept: application/json
Content-Type: application/json
```

Base URL: `https://{host}:{api_port}`

## نقاط پایانی استفاده‌شده

`{u}` = نام کاربری VPN روی سرور.

| عملیات پنل | متد | مسیر | بدنه / نکته |
|------------|-----|------|-------------|
| تست اتصال | GET | `/api/health` | انتظار `{"ok": true}` |
| لیست کاربران | GET | `/api/users` | پاسخ دارای کلید `users` |
| جزئیات کاربر | GET | `/api/users/{u}` | — |
| ساخت کاربر | POST | `/api/users` | `username`، `password`، `max_sessions`، `group` — موفق `201`، تکراری `409` |
| تغییر رمز | PUT | `/api/users/{u}/password` | `{"password":"..."}` |
| محدودیت نشست | PUT | `/api/users/{u}/limits` | `{"max_sessions": N}` |
| قفل / تعلیق | POST | `/api/users/{u}/lock` | — |
| باز کردن / ازسرگیری | POST | `/api/users/{u}/unlock` | — |
| حذف | DELETE | `/api/users/{u}` | `404` یعنی از قبل وجود ندارد (موفقیت idempotent) |
| لیست نشست‌ها | GET | `/api/sessions` | پاسخ دارای کلید `sessions` |
| قطع نشست کاربر | POST | `/api/sessions/{u}/disconnect` | `404` قابل چشم‌پوشی |
| ترافیک | GET | `/api/traffic` | — |
| تونل‌ها | GET | `/api/tunnels` | — |

نمونه درخواست ساخت کاربر:

```
POST /api/users
Authorization: Basic base64(username:api_token)
Content-Type: application/json

{"username": "VPL847291", "password": "847291ab", "max_sessions": 1, "group": null}
```

## اعمال تغییرات

تغییرات **بلافاصله اعمال و ذخیره می‌شوند**. برخلاف Cisco ASA هیچ مرحله `write memory` یا ذخیره پیکربندی وجود ندارد.

## قالب نام کاربری و رمز

- **نام کاربری:** پیشوند `VPL` + ۶ رقم — مثال: `VPL847291`
- **رمز:** ۶ رقم + ۲ حرف کوچک انگلیسی، با حذف `i`، `l` و `o` برای خوانایی — مثال: `847291ab`

## قرارداد مدیریت خطا در کلاینت

| وضعیت | رفتار پنل |
|-------|-----------|
| خطای شبکه | یک بار تلاش مجدد |
| پاسخ 4xx | بدون تلاش مجدد |
| DELETE با 404 | موفقیت تلقی می‌شود |
| POST ساخت با 409 | خطای اختصاصی provisioning گزارش می‌شود |

## فیلدهای پنل (اختصاصی — بدون ترکیب با MikroTik/V2Ray)

### ServerType = `ocserv`

- `host` / `port` — آدرس Management API (پورت پیش‌فرض 9443)
- `username` / `password` — اعتبارنامه Basic؛ **رمز همان API token است**
- آدرس VPN مشتری — می‌تواند با host مربوط به API فرق کند (ترافیک کلاینت معمولاً روی 443)
- `max_sessions` پیش‌فرض — مثلاً `1` (بازه مجاز `0` تا `1000`)
- `group` — گروه ocserv، اختیاری
- بررسی SSL — به‌صورت پیش‌فرض **روشن**

### کلیدهای پیکربندی

| کلید | مقدار پیش‌فرض / توضیح |
|------|------------------------|
| `OCSERV_DEFAULT_PORT` | `9443` |
| `OCSERV_TIMEOUT` | مهلت درخواست HTTP |
| `OCSERV_VERIFY_SSL` | پیش‌فرض روشن |
| `OCSERV_USERNAME_PREFIX` | `VPL` |
| طول ارقام نام کاربری | تعداد رقم‌های بخش عددی نام کاربری |
| طول ارقام رمز | تعداد رقم‌های بخش عددی رمز |

### لاگ

کانال اختصاصی `ocserv` که در `storage/logs/ocserv.log` نوشته می‌شود.

## محدودیت‌ها

- داده‌های ترافیک و نشست وابسته به در دسترس بودن Management API است؛ اگر سرویس مدیریتی بالا نباشد، این اطلاعات در پنل قابل دریافت نیست.
- پنل خودِ ocserv را پیکربندی نمی‌کند — مسیرها (routes)، DNS و گواهی‌ها روی سرور تنظیم می‌شوند.
- Management API نباید به‌صورت عمومی در دسترس قرار گیرد؛ دسترسی باید با فایروال فقط به IP سرور پنل محدود شود.
