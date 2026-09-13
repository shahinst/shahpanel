# راهنمای نماینده (Agent)

## ورود

`/agent/login`

## وظایف

- مدیریت فروشندگان (Seller)
- تنظیم `seller_commission` برای هر بسته
- تأیید درخواست شارژ فروشندگان
- ایجاد اکانت VPN برای فروشندگان
- سفارشی‌سازی فروشگاه (`/agent/storefront`)
- مشاهده فاکتورها و زیرمجموعه

## شارژ کیف پول

1. Agent درخواست شارژ به Admin می‌دهد (`/agent/payment-requests/create`)
2. Admin تأیید → کیف Agent شارژ، Admin ledger ثبت

## فروشنده

هر Seller باید `parent_id` برابر Agent باشد.

## کمیسیون

نرخ Agent از Admin (per package) و نرخ Seller از Agent تعیین می‌شود.
