@if ($staffCreateModal ?? false)
    <button type="button" class="btn btn-light btn-sm" data-staff-create-account-open>
        <i class="bx bx-plus"></i> {{ __('accounts.create') }}
    </button>
@else
    <x-button :href="route($prefix.'.accounts.create')" size="sm">
        <i class="bx bx-plus"></i> {{ __('accounts.create') }}
    </x-button>
@endif
