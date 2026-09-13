<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AccountCategory;
use App\Models\Account;
use App\Services\ServerOvpnProfileService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait ManagesPppAccountActions
{
    public function downloadOvpnProfile(Account $account, ServerOvpnProfileService $ovpnProfiles): BinaryFileResponse
    {
        $this->authorize('view', $account);
        $this->assertPppAccount($account);

        return $ovpnProfiles->downloadResponse($account);
    }

    protected function assertPppAccount(Account $account): void
    {
        if ($account->service_type->accountCategory() !== AccountCategory::Ppp) {
            abort(404);
        }
    }
}
