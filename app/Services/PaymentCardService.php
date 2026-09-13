<?php

namespace App\Services;

use App\Enums\PaymentCardApprovalStatus;
use App\Enums\UserRole;
use App\Models\PaymentCard;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PaymentCardService
{
    public function cardsForOwner(User $owner): Collection
    {
        return PaymentCard::query()
            ->with('user')
            ->where('user_id', $owner->id)
            ->latest('id')
            ->get();
    }

    /**
     * Published cards from the client's direct owner (seller or agent) only.
     *
     * @return Collection<int, PaymentCard>
     */
    public function visiblePaymentCardsForClient(User $client): Collection
    {
        if ($client->role !== UserRole::Client) {
            return collect();
        }

        $client->loadMissing('parent');
        $directOwner = $client->parent;

        if ($directOwner === null) {
            return collect();
        }

        return $this->visibleCardsFromOwner($client, $directOwner);
    }

    public function pendingApprovalsFor(User $approver): Collection
    {
        $ownerIds = $this->downlineOwnerIds($approver);

        return PaymentCard::query()
            ->with('user')
            ->whereIn('user_id', $ownerIds)
            ->where(function ($query): void {
                $query->where('approval_status', PaymentCardApprovalStatus::Pending)
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('deletion_requested_at')
                            ->whereNull('deletion_approved_at');
                    });
            })
            ->latest('updated_at')
            ->get();
    }

    /**
     * Cards visible to viewer from a specific owner account.
     *
     * @return Collection<int, PaymentCard>
     */
    public function visibleCardsFromOwner(User $viewer, User $owner): Collection
    {
        return $this->cardsForOwner($owner)
            ->filter(fn (PaymentCard $card): bool => $this->isVisibleTo($card, $viewer, $owner))
            ->values();
    }

    public function payeeFor(User $requester): ?User
    {
        return match ($requester->role) {
            UserRole::Agent => User::query()->role(UserRole::Admin)->orderBy('id')->first(),
            UserRole::Seller, UserRole::Client => $requester->parent,
            default => null,
        };
    }

    /**
     * @return Collection<int, PaymentCard>
     */
    public function payeeCardsFor(User $requester): Collection
    {
        $payee = $this->payeeFor($requester);

        if ($payee === null) {
            return collect();
        }

        return $this->visibleCardsFromOwner($requester, $payee);
    }

    public function create(User $owner, array $data): PaymentCard
    {
        $autoApprove = $owner->role === UserRole::Admin;

        return PaymentCard::query()->create([
            'user_id' => $owner->id,
            'card_number' => preg_replace('/\s+/', '', (string) $data['card_number']),
            'card_holder' => $data['card_holder'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'is_active' => true,
            'approval_status' => $autoApprove ? PaymentCardApprovalStatus::Approved : PaymentCardApprovalStatus::Pending,
            'approved_by_user_id' => $autoApprove ? $owner->id : null,
            'approved_at' => $autoApprove ? now() : null,
        ]);
    }

    public function requestDeletion(PaymentCard $card, User $requester): PaymentCard
    {
        $this->assertOwnsCard($card, $requester);

        if ($requester->role === UserRole::Admin) {
            $card->delete();

            return $card;
        }

        $card->update(['deletion_requested_at' => now()]);

        return $card->fresh();
    }

    public function approve(PaymentCard $card, User $approver): PaymentCard
    {
        $this->assertCanApprove($card, $approver);

        if ($card->isPendingDeletion()) {
            $card->delete();

            return $card;
        }

        $card->update([
            'approval_status' => PaymentCardApprovalStatus::Approved,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);

        return $card->fresh();
    }

    public function reject(PaymentCard $card, User $approver): PaymentCard
    {
        $this->assertCanApprove($card, $approver);

        if ($card->isPendingDeletion()) {
            $card->update(['deletion_requested_at' => null]);

            return $card->fresh();
        }

        $card->update([
            'approval_status' => PaymentCardApprovalStatus::Rejected,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);

        return $card->fresh();
    }

    public function uplineApprover(User $owner): ?User
    {
        return match ($owner->role) {
            UserRole::Agent => User::query()->role(UserRole::Admin)->orderBy('id')->first(),
            UserRole::Seller => $owner->parent,
            default => null,
        };
    }

    public function isVisibleTo(PaymentCard $card, User $viewer, ?User $cardOwner = null): bool
    {
        if (! $card->isPublished()) {
            return false;
        }

        $owner = $cardOwner ?? $card->user;

        if ($owner === null) {
            return false;
        }

        return match ($owner->role) {
            UserRole::Admin => $viewer->role === UserRole::Agent,
            UserRole::Agent => $this->viewerUnderAgent($viewer, $owner),
            UserRole::Seller => $viewer->role === UserRole::Client
                && (int) $viewer->parent_id === (int) $owner->id,
            default => false,
        };
    }

    protected function viewerUnderAgent(User $viewer, User $agent): bool
    {
        if ($viewer->role === UserRole::Seller) {
            return (int) $viewer->parent_id === (int) $agent->id;
        }

        if ($viewer->role === UserRole::Client) {
            $viewer->loadMissing('parent');

            if ((int) $viewer->parent_id === (int) $agent->id) {
                return true;
            }

            return (int) ($viewer->parent?->parent_id) === (int) $agent->id;
        }

        return false;
    }

    /**
     * @return list<int>
     */
    protected function downlineOwnerIds(User $approver): array
    {
        return match ($approver->role) {
            UserRole::Admin => User::query()
                ->whereIn('role', [UserRole::Agent->value, UserRole::Seller->value])
                ->pluck('id')
                ->all(),
            UserRole::Agent => User::query()
                ->where('parent_id', $approver->id)
                ->where('role', UserRole::Seller->value)
                ->pluck('id')
                ->all(),
            default => [],
        };
    }

    protected function assertOwnsCard(PaymentCard $card, User $user): void
    {
        if ((int) $card->user_id !== (int) $user->id) {
            throw new InvalidArgumentException(__('payment_cards.not_owner'));
        }
    }

    protected function assertCanApprove(PaymentCard $card, User $approver): void
    {
        $owner = $card->user;

        $allowed = match ($approver->role) {
            UserRole::Admin => in_array($owner->role, [UserRole::Agent, UserRole::Seller], true),
            UserRole::Agent => $owner->role === UserRole::Seller
                && (int) $owner->parent_id === (int) $approver->id,
            default => false,
        };

        if (! $allowed) {
            throw new InvalidArgumentException(__('payment_cards.cannot_approve'));
        }
    }
}
