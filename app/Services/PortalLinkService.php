<?php

namespace App\Services;

use App\Models\Account;
use App\Support\PortalLinkSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PortalLinkService
{
    /**
     * عمر لینک اول از تنظیمات پنل خوانده می‌شود و اگر ادمین چیزی تنظیم نکرده
     * باشد همان ثابت config می‌ماند؛ پس نصب‌های موجود بدون هیچ تغییری دقیقاً
     * مثل قبل رفتار می‌کنند.
     */
    public function ttlMinutes(): int
    {
        $configured = PortalLinkSettings::ttlMinutes();

        if ($configured !== null) {
            return $configured;
        }

        return max(1, (int) config('shahpanel.portal_link_ttl_minutes', 5));
    }

    /** همان عمر، به شکل خواندنی («۳ روز») برای متن‌های پنل و پیامک. */
    public function ttlLabel(): string
    {
        return PortalLinkSettings::describe($this->ttlMinutes());
    }

    public function isActive(Account $account): bool
    {
        return filled($account->portal_token)
            && $account->portal_token_expires_at !== null
            && $account->portal_token_expires_at->isFuture();
    }

    public function publicUrlIfActive(Account $account): ?string
    {
        if (! $this->isActive($account)) {
            return null;
        }

        return route('portal.show', $account->portal_token);
    }

    /** لحظهٔ دقیق انقضای لینکِ فعلی — فقط وقتی لینک هنوز زنده است. */
    public function expiresAtIfActive(Account $account): ?Carbon
    {
        return $this->isActive($account) ? $account->portal_token_expires_at : null;
    }

    /**
     * مسیر «همان لینک قبلی»: تا وقتی توکن فعلی زنده است دقیقاً همان نشانی
     * برگردانده می‌شود. دلیلش رفتار واقعی نماینده‌هاست: نماینده لینک را یک بار
     * به مشتری می‌دهد و مشتری همان را نگه می‌دارد تا مصرفش را ببیند؛ اگر هر
     * بار دیدن یا فرستادن لینک توکن را عوض کند، لینکِ دست مشتری همان لحظه
     * می‌مُرد و مشتری مجبور می‌شد باز هم لینک تازه بخواهد. فقط وقتی توکن
     * منقضی شده (یا هرگز صادر نشده) باشد توکن نو ساخته می‌شود.
     *
     * هر جای پنل که لینک را فقط «نشان می‌دهد» یا «می‌فرستد» باید همین متد را
     * صدا بزند، نه regenerate().
     */
    public function ensure(Account $account): string
    {
        $active = $this->publicUrlIfActive($account);

        if ($active !== null) {
            return $active;
        }

        return $this->regenerate($account);
    }

    /**
     * چرخش عمدی توکن. این کار لینک قبلی را همان لحظه بی‌اعتبار می‌کند، پس فقط
     * وقتی صدا زده می‌شود که خودِ نماینده دکمهٔ «ساخت لینک تازه» را زده باشد
     * (مثلاً مشتری می‌گوید لینکش لو رفته). نام متد عمداً صریح است تا مخرب
     * بودنش در هر محل فراخوانی پیدا باشد و کسی آن را در مسیر نمایشی نگذارد.
     */
    public function regenerate(Account $account): string
    {
        $account->update([
            'portal_token' => Str::random((int) config('shahpanel.portal_token_length', 32)),
            'portal_token_expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);

        return route('portal.show', $account->fresh()->portal_token);
    }

    public function url(Account $account): string
    {
        return route('portal.show', $account->portal_token);
    }
}
