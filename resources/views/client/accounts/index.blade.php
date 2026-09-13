@extends('layouts.panel')

@section('page_title', __('clients.my_accounts'))

@section('panel_content')
<x-card>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>{{ __('accounts.username') }}</th>
                    <th>{{ __('accounts.package') }}</th>
                    <th>{{ __('accounts.expiry') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td>{{ $account->remote_username }}</td>
                        <td>{{ $account->package?->name }}</td>
                        <td>{{ $account->expiry_at ? jalali_date($account->expiry_at) : __('accounts.no_expiry') }}</td>
                        <td class="text-end">
                            <x-button size="sm" :href="route('client.accounts.show', $account)">{{ __('clients.view_details') }}</x-button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted">{{ __('clients.no_accounts') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $accounts->links() }}
</x-card>
@endsection
