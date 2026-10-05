<?php

namespace Modules\Dedicated\Services;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\InboundAllocation;
use App\Models\User;
use App\Services\InboundReseller\InboundAllocationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Dedicated\Models\InboundChargeRequest;
use Modules\Dedicated\Models\InboundVolumePack;
use Throwable;

/**
 * Prepaid volume for inbound agents: the agent asks for a pack, the admin
 * settles how many gigabytes are actually given, and only then does the
 * agent's wallet pay -- into the admin's revenue, where the panel's own
 * accounting sees it.
 */
class InboundVolumeService
{
    public function __construct(
        protected WalletService $wallets,
        protected InboundAllocationService $allocations,
    ) {}

    public function request(User $agent, InboundAllocation $allocation, InboundVolumePack $pack, ?string $note = null): InboundChargeRequest
    {
        if ((int) $allocation->agent_user_id !== (int) $agent->id) {
            throw new InvalidArgumentException(__('dedicated::admin.not_your_inbound'));
        }

        // A pack is sold for one server; it cannot fill an inbound elsewhere.
        if (! $pack->is_active || (int) $pack->server_id !== (int) $allocation->server_id) {
            throw new InvalidArgumentException(__('dedicated::admin.pack_not_for_inbound'));
        }

        return InboundChargeRequest::query()->create([
            'agent_user_id' => $agent->id,
            'allocation_id' => $allocation->id,
            'pack_id' => $pack->id,
            'requested_gb' => $pack->gb,
            'amount' => (string) $pack->price,
            'currency' => $pack->currency,
            'status' => InboundChargeRequest::PENDING,
            'note' => $note,
        ]);
    }

    /**
     * Give the volume and take the money, in one transaction.
     *
     * The admin may give a different amount than was asked for; the price
     * follows the gigabytes actually given, at the pack's own rate.
     */
    public function approve(InboundChargeRequest $request, int $gb, User $admin, ?string $adminNote = null): InboundChargeRequest
    {
        if ($gb < 1) {
            throw new InvalidArgumentException(__('dedicated::admin.gb_required'));
        }

        $request = DB::transaction(function () use ($request, $gb, $admin, $adminNote): InboundChargeRequest {
            $request = InboundChargeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            // A double click, or two admins at once, must not charge twice.
            if (! $request->isPending()) {
                throw new InvalidArgumentException(__('dedicated::admin.request_already_reviewed'));
            }

            $allocation = InboundAllocation::query()->whereKey($request->allocation_id)->lockForUpdate()->firstOrFail();
            $agent = User::query()->findOrFail($request->agent_user_id);

            $amount = bcdiv(bcmul((string) $request->amount, (string) $gb, 4), (string) max(1, $request->requested_gb), 2);
            $context = [
                'currency' => $request->currency,
                'source_user_id' => $agent->id,
                'description' => __('dedicated::admin.charge_description', [
                    'gb' => $gb,
                    'inbound' => $allocation->label(),
                ]),
            ];

            if (bccomp($amount, '0', 2) > 0) {
                // No credit: the volume is prepaid. An agent without the money
                // tops up their wallet first, as with any other purchase.
                $this->wallets->debit($agent, $amount, TransactionType::InboundUsage, $context);

                $owner = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();
                if ($owner !== null) {
                    $this->wallets->credit($owner, $amount, TransactionType::Revenue, $context);
                }
            }

            $allocation->quota_bytes = (int) $allocation->quota_bytes + $gb * InboundAllocation::GB;
            $allocation->save();

            $request->forceFill([
                'status' => InboundChargeRequest::APPROVED,
                'approved_gb' => $gb,
                'charged_amount' => $amount,
                'admin_note' => $adminNote,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return $request;
        });

        // An inbound switched off because its volume ran out comes back on
        // its own once there is volume again. Switching the accounts back on
        // talks to the remote panel, so it happens after the money is settled.
        $allocation = InboundAllocation::query()->find($request->allocation_id);

        if ($allocation !== null && ! $allocation->isActive()
            && $allocation->suspended_reason === InboundAllocation::SUSPENDED_QUOTA && ! $allocation->quotaReached()) {
            try {
                $this->allocations->resume($allocation);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $request;
    }

    public function reject(InboundChargeRequest $request, User $admin, ?string $adminNote = null): InboundChargeRequest
    {
        return DB::transaction(function () use ($request, $admin, $adminNote): InboundChargeRequest {
            $request = InboundChargeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $request->isPending()) {
                throw new InvalidArgumentException(__('dedicated::admin.request_already_reviewed'));
            }

            $request->forceFill([
                'status' => InboundChargeRequest::REJECTED,
                'admin_note' => $adminNote,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return $request;
        });
    }
}
