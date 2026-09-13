<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
use App\Services\PortalLinkService;
use Illuminate\Http\RedirectResponse;

trait ManagesPortalLinks
{
    public function issuePortalLink(Account $account, PortalLinkService $portalLinks): RedirectResponse
    {
        $this->authorize('view', $account);

        if ($account->isRefunded()) {
            abort(404);
        }

        return redirect()->away($portalLinks->issue($account));
    }
}
