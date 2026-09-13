@can('create', \App\Models\Server::class)
    <x-button :href="route('admin.servers.create')">
        <i class="bx bx-plus align-middle"></i> {{ __('servers.create') }}
    </x-button>
@endcan
