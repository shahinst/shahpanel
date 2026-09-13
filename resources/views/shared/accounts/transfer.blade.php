@extends('layouts.panel')

@section('page_title', __('accounts.transfer_title'))

@section('panel_content')
<p><a href="{{ route($prefix.'.accounts.'.$account->service_type->accountCategory()->value) }}"><i class="bx bx-arrow-back"></i> {{ $account->service_type->accountCategory()->label() }}</a></p>

<x-card :title="__('accounts.transfer_title').' — '.$account->remote_username">
    <p class="text-muted">{{ __('accounts.transfer_hint') }}</p>
    <p><strong>{{ __('accounts.server') }}:</strong> {{ $account->server?->name ?? '—' }}</p>

    <form method="POST" action="{{ route($prefix.'.accounts.transfer', $account) }}">
        @csrf
        <div class="form-group">
            <label>سرور جدید</label>
            <select name="server_id" class="form-select" required style="max-width:400px;">
                @foreach ($servers as $server)
                    @if ((int) $server->id !== (int) $account->server_id)
                        <option value="{{ $server->id }}">{{ $server->name }} @if($server->location) ({{ $server->location }}) @endif</option>
                    @endif
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">{{ __('accounts.change_server') }}</button>
    </form>
</x-card>
@endsection
