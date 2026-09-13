@can('create', \App\Models\User::class)
    <a href="{{ route('agent.sellers.create') }}" class="btn btn-light btn-sm">
        <i class="bx bx-plus"></i> {{ __('sellers.create') }}
    </a>
@endcan
