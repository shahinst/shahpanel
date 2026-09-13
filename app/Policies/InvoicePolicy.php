<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
    }

    public function view(User $viewer, Invoice $invoice): bool
    {
        return $this->inScope($viewer, $invoice);
    }

    public function create(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Agent, UserRole::Seller], true);
    }

    public function update(User $viewer, Invoice $invoice): bool
    {
        return $this->inScope($viewer, $invoice);
    }

    public function delete(User $viewer, Invoice $invoice): bool
    {
        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        return $this->inScope($viewer, $invoice);
    }

    protected function inScope(User $viewer, Invoice $invoice): bool
    {
        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        $userIds = User::subtreeUserIds($viewer);

        return in_array($invoice->buyer_user_id, $userIds, true)
            || in_array($invoice->seller_user_id, $userIds, true)
            || in_array($invoice->agent_user_id, $userIds, true);
    }
}
