<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\RefreshSubscriptionCacheJob;
use App\Models\Account;
use App\Services\PortalLinkService;
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
     * چرا dispatchSync و نه dispatch: نتیجه باید در همان بازگشتِ صفحه دیده شود؛
     * با صف، تا اجرای بعدیِ queue:work تا یک دقیقه فاصله است و کاربر فکر می‌کند
     * دکمه کار نکرده. مجوز update است چون به پنل راه دور درخواست می‌زند.
     */
    public function refreshConfigCache(Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($account->isRefunded() || ! $account->service_type->isPanelV2ray()) {
            abort(404);
        }

        RefreshSubscriptionCacheJob::dispatchSync($account->id);

        // کار هرگز استثنا نمی‌اندازد و در بدترین حالت کش قبلی را دست‌نخورده
        // می‌گذارد، پس تنها راه فهمیدن نتیجه خواندن دوبارهٔ ردیف است.
        return filled($account->fresh()?->subscription_cache)
            ? back()->with('success', __('accounts.config_refresh_done'))
            : back()->with('warning', __('accounts.config_refresh_empty'));
    }
}
