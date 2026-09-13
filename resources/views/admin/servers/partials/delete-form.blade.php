@can('delete', $server)
    <form method="POST"
          action="{{ route('admin.servers.destroy', $server) }}"
          class="d-inline"
          onsubmit="return confirm(@json(__('servers.delete_confirm', ['name' => $server->name])));">
        @csrf
        @method('DELETE')
        @if (! empty($fromShow))
            <input type="hidden" name="from_show" value="1">
        @endif
        <button type="submit" @class([
            'btn btn-sm btn-danger',
            $buttonClass ?? '',
        ])>
            <i class="bx bx-trash"></i> {{ $label ?? __('servers.delete') }}
        </button>
    </form>
@endcan
