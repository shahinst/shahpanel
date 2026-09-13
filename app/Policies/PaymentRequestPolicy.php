<?php

namespace App\Policies;

use App\Enums\PaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\PaymentRequest;
use App\Models\User;

class PaymentRequestPolicy
{
    public function viewAny(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller, UserRole::Client], true);
    }

    public function view(User $viewer, PaymentRequest $paymentRequest): bool
    {
        return $this->inScope($viewer, $paymentRequest);
    }

    public function create(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Agent, UserRole::Seller, UserRole::Client], true);
    }

    public function update(User $viewer, PaymentRequest $paymentRequest): bool
    {
        return $paymentRequest->requester_user_id === $viewer->id
            && $paymentRequest->status === PaymentRequestStatus::Pending;
    }

    public function approve(User $viewer, PaymentRequest $paymentRequest): bool
    {
        if ($paymentRequest->status !== PaymentRequestStatus::Pending) {
            return false;
        }

        if ($viewer->role === UserRole::Admin && $paymentRequest->approver_user_id === $viewer->id) {
            return true;
        }

        return $paymentRequest->approver_user_id === $viewer->id
            && in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
    }

    public function reject(User $viewer, PaymentRequest $paymentRequest): bool
    {
        return $this->approve($viewer, $paymentRequest);
    }

    protected function inScope(User $viewer, PaymentRequest $paymentRequest): bool
    {
        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        if ($viewer->role === UserRole::Client) {
            return (int) $paymentRequest->requester_user_id === (int) $viewer->id;
        }

        if (in_array($viewer->role, [UserRole::Agent, UserRole::Seller], true)) {
            return (int) $paymentRequest->requester_user_id === (int) $viewer->id
                || (int) $paymentRequest->approver_user_id === (int) $viewer->id;
        }

        return false;
    }
}
