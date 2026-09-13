<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

trait ProvidesStaffAccountCreateModal
{
    /**
     * @return array{
     *     staffCreateModal: bool,
     *     prefix: string,
     *     accountOwners: \Illuminate\Support\Collection<int, User>,
     *     openCreateModal: bool
     * }
     */
    protected function staffAccountCreateModalData(Request $request): array
    {
        $prefix = $this->accountRoutePrefix();

        return [
            'staffCreateModal' => in_array($prefix, ['agent', 'seller'], true),
            'prefix' => $prefix,
            'accountOwners' => $prefix === 'agent'
                ? $this->agentAccountOwnersForCreate($request)
                : collect(),
            'openCreateModal' => $request->boolean('create')
                || old('account_display_name') !== null
                || session('open_create_modal'),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    protected function agentAccountOwnersForCreate(Request $request): \Illuminate\Support\Collection
    {
        $sellerIds = User::query()
            ->ownedByHierarchy($request->user(), 'id')
            ->role(UserRole::Seller)
            ->pluck('id');

        return User::query()
            ->whereIn('id', $sellerIds->push($request->user()->id)->unique()->values())
            ->orderBy('full_name')
            ->orderBy('username')
            ->get(['id', 'full_name', 'username', 'role']);
    }

    abstract protected function accountRoutePrefix(): string;
}
