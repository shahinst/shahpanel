{{-- "Expiring accounts" menu entry with the count for the next 3 days. --}}
@php
    $expiringRoute = $panel.'.accounts.expiring';
    $expiringCount = 0;

    if (\Illuminate\Support\Facades\Route::has($expiringRoute) && ($panel !== 'admin' || admin_section_allowed('accounts'))) {
        try {
            $viewer = auth()->user();
            $expiringCount = \Illuminate\Support\Facades\Cache::remember(
                'nav.expiring.'.$viewer->id,
                now()->addMinutes(5),
                function () use ($viewer): int {
                    $q = \App\Models\Account::query()
                        ->where('status', \App\Enums\AccountStatus::Active)
                        ->whereBetween('expiry_at', [now(), now()->addDays(3)]);

                    return match ($viewer->role) {
                        \App\Enums\UserRole::Admin => $q->count(),
                        \App\Enums\UserRole::Seller => $q->where('owner_seller_id', $viewer->id)->count(),
                        default => $q->ownedByHierarchy($viewer)->count(),
                    };
                },
            );
        } catch (\Throwable) {
            $expiringCount = 0;
        }
    }
@endphp
@if (\Illuminate\Support\Facades\Route::has($expiringRoute) && ($panel !== 'admin' || admin_section_allowed('accounts')))
    <x-sidebar-item
        :href="route($expiringRoute)"
        :label="__('expiring.menu')"
        icon="bx-alarm-exclamation"
        :badge="$expiringCount > 0 ? persian_digits($expiringCount > 99 ? '99+' : $expiringCount) : null"
        :active="request()->routeIs($expiringRoute.'*')" />
@endif
