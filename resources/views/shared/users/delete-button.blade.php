{{-- Admin-only delete for an agent, seller or client (UserPolicy::delete). --}}
@can('delete', $deleteUser)
    <x-icon-action :action="$deleteRoute" method="DELETE" icon="bx-trash" variant="danger"
                   :label="__('app.delete')"
                   :confirm="__('users.delete_confirm', ['name' => $deleteUser->full_name ?: $deleteUser->username])" />
@endcan
