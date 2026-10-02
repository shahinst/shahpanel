@if ($staffCreateModal ?? false)
    <button type="button" class="btn btn-light btn-sm" data-staff-create-account-open>
        <i class="bx bx-plus"></i> {{ __('accounts.create') }}
    </button>
@else
    <x-button :href="route($prefix.'.accounts.create')" size="sm">
        <i class="bx bx-plus"></i> {{ __('accounts.create') }}
    </x-button>
@endif
@if (($staffBulkModal ?? false) && \Illuminate\Support\Facades\Route::has($prefix.'.accounts.bulk-store'))
    <button type="button" class="btn btn-light btn-sm" data-staff-create-account-open data-bulk="1">
        <i class="bx bx-layer-plus"></i> {{ __('accounts.bulk_create') }}
    </button>
@endif
