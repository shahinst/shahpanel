{{-- Admin-only delete for an agent, seller or client (UserPolicy::delete). --}}
@can('delete', $deleteUser)
    <form method="POST" action="{{ $deleteRoute }}" class="d-inline"
          onsubmit="return confirm(@json(__('users.delete_confirm', ['name' => $deleteUser->full_name ?: $deleteUser->username])))">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-sm btn-danger" title="{{ __('app.delete') }}">
            <i class="bx bx-trash"></i> {{ __('app.delete') }}
        </button>
    </form>
@endcan
