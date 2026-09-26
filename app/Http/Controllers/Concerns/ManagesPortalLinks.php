<?php

namespace App\Http\Controllers\Concerns;

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
}
