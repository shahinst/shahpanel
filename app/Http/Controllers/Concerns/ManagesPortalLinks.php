<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\RefreshSubscriptionCacheJob;
use App\Models\Account;
use App\Services\PortalLinkService;
use App\Support\SubscriptionCacheOutcome;
use Illuminate\Http\RedirectResponse;

trait ManagesPortalLinks
{
    /**
     * دکمهٔ «لینک پورتال مشتری» فقط لینک را باز می‌کند تا نماینده آن را ببیند یا
     * کپی کند؛ پس همان لینکِ فعلی برگردانده می‌شود. پیش از این هر بار باز کردن
     * این مسیر توکن را عوض می‌کرد و لینکی که نماینده قبلاً به مشتری داده بود
     * بی‌صدا از کار می‌افتاد — دقیقاً همان دردی که باید تمام شود.
     */
    public function openPortalLink(Account $account, PortalLinkService $portalLinks): RedirectResponse
    {
        $this->authorize('view', $account);

        if ($account->isRefunded()) {
            abort(404);
        }

        return redirect()->away($portalLinks->ensure($account));
    }

    /**
     * ساخت لینک تازه به‌خواست نماینده (مثلاً وقتی مشتری می‌گوید لینکش لو رفته).
     * این تنها مسیری است که توکنِ زندهٔ فعلی را می‌چرخاند، پس مثل خاموش/روشن
     * کردن اکانت یک «تغییر وضعیت» است و با همان مجوز update اجازه می‌گیرد، نه
     * view که فقط برای دیدن است.
     */
    public function regeneratePortalLink(Account $account, PortalLinkService $portalLinks): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($account->isRefunded()) {
            abort(404);
        }

        $portalLinks->regenerate($account);

        return back()->with('success', __('accounts.portal_link_regenerated'));
    }

    /**
     * ساخت دوبارهٔ کش کانفیگ به‌خواست پشتیبانی. این کش خودش پر می‌شود (ساخت،
     * تمدید، درون‌ریزی و زمان‌بندِ هر پنج دقیقه)، ولی وقتی مشتری پشت خط است
     * نمی‌توان منتظر دور بعدی صف ماند — تا آن لحظه جعبهٔ «لینک مستقیم کانفیگ»
     * خالی می‌ماند و همین شکایتِ اکانت‌های درون‌ریزی‌شده از سنایی بود.
     *
     * چرا همگام و نه dispatch: نتیجه باید در همان بازگشتِ صفحه دیده شود؛ با صف،
     * تا اجرای بعدیِ queue:work تا یک دقیقه فاصله است و کاربر فکر می‌کند دکمه
     * کار نکرده. مجوز update است چون به پنل راه دور درخواست می‌زند.
     */
    public function refreshConfigCache(Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($account->isRefunded() || ! $account->service_type->isPanelV2ray()) {
            abort(404);
        }

        $outcome = RefreshSubscriptionCacheJob::runNowFor($account);

        // پیش از این نتیجه از «کش پر است یا نه» حدس زده می‌شد و همین باعث آن
        // پیام مبهم بود: سرورِ خاموش‌شده در پنل، نشانیِ اشتراکِ خالی و پنلِ
        // بی‌جواب همه یک جمله می‌گرفتند و همه به یک لاگِ سنایی حواله می‌شدند —
        // حتی اکانت پاسارگارد و رمناویو. حالا خودِ کار می‌گوید چه شد.
        return $outcome->succeeded()
            ? back()->with('success', __('accounts.config_refresh_done'))
            : back()->with('warning', $this->configRefreshFailureMessage($account, $outcome));
    }

    /**
     * دلیلِ شکست به زبان کاربر. هر شاخه یک اتفاق واقعی است و فقط جایی به فایل
     * لاگ اشاره می‌کند که لاگ چیزی بیش از خودِ پیام داشته باشد؛ برای پنلِ
     * بی‌جواب، متن خطا داخل همان پیام می‌آید.
     */
    protected function configRefreshFailureMessage(Account $account, SubscriptionCacheOutcome $outcome): string
    {
        $message = match ($outcome->reason) {
            SubscriptionCacheOutcome::SERVER_DISABLED => __('accounts.config_refresh_server_disabled', [
                'server' => filled($outcome->detail) ? $outcome->detail : (string) $account->server_id,
            ]),
            SubscriptionCacheOutcome::SERVER_MISSING => __('accounts.config_refresh_server_missing'),
            SubscriptionCacheOutcome::NO_SUB_ID => __('accounts.config_refresh_no_sub_id', [
                'log' => $this->configRefreshLogPath($account),
            ]),
            SubscriptionCacheOutcome::CLIENT_NOT_FOUND => __('accounts.config_refresh_client_not_found', [
                'email' => filled($outcome->detail) ? $outcome->detail : '—',
            ]),
            SubscriptionCacheOutcome::NO_SUBSCRIPTION_URL => __('accounts.config_refresh_no_url'),
            SubscriptionCacheOutcome::UNREACHABLE => __('accounts.config_refresh_unreachable', [
                'error' => filled($outcome->detail) ? $outcome->detail : '—',
            ]),
            SubscriptionCacheOutcome::EMPTY_BODY => __('accounts.config_refresh_empty_body', [
                'log' => $this->configRefreshLogPath($account),
            ]),
            default => __('accounts.config_refresh_empty'),
        };

        // شکست هیچ‌وقت کش را پاک نمی‌کند (قانون سختِ RefreshSubscriptionCacheJob).
        // اگر کانفیگی از قبل هست همین را هم بگو تا پشتیبانی فکر نکند با فشار دادن
        // دکمه کانفیگ مشتری را از بین برده است.
        return filled($account->fresh()?->subscription_cache)
            ? $message.' '.__('accounts.config_refresh_cache_kept')
            : $message;
    }

    /**
     * نام واقعی فایل لاگِ همان پنل. کانال‌های لاگ daily هستند، پس فایلی به نام
     * «sanaei.log» هرگز ساخته نمی‌شود و پیام قبلی اپراتور را دنبال نامی
     * می‌فرستاد که روی سرور وجود نداشت.
     */
    protected function configRefreshLogPath(Account $account): string
    {
        return 'storage/logs/'
            .RefreshSubscriptionCacheJob::logChannelFor($account)
            .'-'.now()->format('Y-m-d').'.log';
    }
}
