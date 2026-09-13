<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;

class ClientAccountReassignmentService
{
    public function __construct(
        protected ClientAccountLinkService $linkService,
    ) {}

    public function reassign(Account $account, User $targetClient, User $actor): Account
    {
        return $this->linkService->reassign($account, $targetClient, $actor);
    }
}
