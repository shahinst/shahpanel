@extends('layouts.panel')

@section('page_title', __('dedicated::admin.create_agent'))

@section('panel_content')
<x-page-header :title="__('dedicated::admin.create_agent')" :subtitle="__('dedicated::admin.create_intro')">
    <x-slot:actions>
        <a href="{{ route('admin.dedicated.index') }}" class="btn btn-light"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('admin.dedicated.store') }}">
    @csrf
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3"><i class="bx bx-user"></i> {{ __('dedicated::admin.agent_details') }}</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">{{ __('dedicated::admin.full_name') }}</label><input name="full_name" class="form-control" value="{{ old('full_name') }}" required></div>
                <div class="col-md-6"><label class="form-label">{{ __('dedicated::admin.username') }}</label><input name="username" class="form-control" dir="ltr" value="{{ old('username') }}" required></div>
                <div class="col-md-6"><label class="form-label">{{ __('dedicated::admin.email') }}</label><input type="email" name="email" class="form-control" dir="ltr" value="{{ old('email') }}"></div>
                <div class="col-md-6"><label class="form-label">{{ __('dedicated::admin.phone') }}</label><input name="phone" class="form-control" dir="ltr" value="{{ old('phone') }}"></div>
                <div class="col-md-6"><label class="form-label">{{ __('dedicated::admin.password') }}</label><input type="password" name="password" class="form-control" dir="ltr" minlength="8" required></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3"><i class="bx bx-server"></i> {{ __('dedicated::admin.agent_server') }}</h2>
            @if ($freeServers->isEmpty())
                <div class="alert alert-warning mb-0">{{ __('dedicated::admin.no_free_servers') }}</div>
            @else
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                        <select name="server_id" class="form-select" required>
                            @foreach ($freeServers as $server)
                                <option value="{{ $server->id }}" @selected((int) old('server_id') === $server->id)>{{ $server->name }} · {{ $server->type?->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('dedicated::admin.meter_interface') }}</label>
                        <input type="text" name="meter_interface" class="form-control" dir="ltr" placeholder="ether1" value="{{ old('meter_interface') }}">
                        <div class="form-text">{{ __('dedicated::admin.meter_hint') }}</div>
                    </div>
                </div>
                <p class="text-muted small mt-3 mb-0">{{ __('dedicated::admin.more_servers_later') }}</p>
            @endif
        </div>
    </div>

    <button class="btn btn-primary" @disabled($freeServers->isEmpty())><i class="bx bx-save"></i> {{ __('dedicated::admin.create_agent') }}</button>
</form>
@endsection
