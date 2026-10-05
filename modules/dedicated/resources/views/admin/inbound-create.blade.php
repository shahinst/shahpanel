@extends('layouts.panel')

@section('page_title', __('dedicated::admin.new_inbound_agent'))

@section('panel_content')
    <x-page-header :title="__('dedicated::admin.new_inbound_agent')">
        <p class="text-muted mb-0">{{ __('dedicated::admin.inbound_create_intro') }}</p>
        <x-slot:actions>
            <x-button :href="route('admin.inbound-agents.index')" variant="outline-secondary"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-3"><i class="bx bx-user-plus"></i> {{ __('dedicated::admin.new_inbound_agent') }}</h5>
            @if ($servers->isEmpty())
                <x-alert type="info">{{ __('dedicated::admin.no_sanaei_servers') }}</x-alert>
            @else
                <form method="POST" action="{{ route('admin.inbound-agents.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.full_name') }}</label><input name="full_name" class="form-control" value="{{ old('full_name') }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.username') }}</label><input name="username" class="form-control" dir="ltr" value="{{ old('username') }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.email') }}</label><input type="email" name="email" class="form-control" dir="ltr" value="{{ old('email') }}" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.phone') }}</label><input name="phone" class="form-control" dir="ltr" value="{{ old('phone') }}"></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.password') }}</label><input type="password" name="password" class="form-control" dir="ltr" minlength="8" required></div>
                    <div class="col-md-4"><label class="form-label">{{ __('dedicated::admin.title') }}</label><input name="title" class="form-control" value="{{ old('title') }}"></div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('dedicated::admin.server') }}</label>
                        <select name="server_id" id="ia-server" class="form-select" required>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}" @selected((int) old('server_id') === $server->id)>{{ $server->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">{{ __('dedicated::admin.inbound') }}</label>
                        @foreach ($servers as $server)
                            <div class="ia-inbounds" data-server="{{ $server->id }}">
                                @forelse ($inbounds[$server->id] ?? [] as $id => $name)
                                    <label class="form-check">
                                        <input type="checkbox" class="form-check-input" name="inbound_ids[]" value="{{ $id }}">
                                        <span class="form-check-label">{{ $name }} <span class="text-muted">#{{ $id }}</span></span>
                                    </label>
                                @empty
                                    <div class="small text-muted">{{ __('dedicated::admin.no_inbounds') }}</div>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                    <div class="col-md-3"><label class="form-label">{{ __('dedicated::admin.initial_volume') }}</label><input type="number" name="quota_gb" min="0" value="{{ old('quota_gb', 0) }}" class="form-control" dir="ltr" required></div>
                    <div class="col-12"><button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('dedicated::admin.create_agent') }}</button></div>
                </form>
                <script>
                    (function () {
                        const select = document.getElementById('ia-server');
                        const sync = () => document.querySelectorAll('.ia-inbounds').forEach((box) => {
                            const on = box.dataset.server === select.value;
                            box.style.display = on ? '' : 'none';
                            box.querySelectorAll('input').forEach((input) => { input.disabled = ! on; });
                        });
                        select.addEventListener('change', sync);
                        sync();
                    })();
                </script>
            @endif
        </div>
    </div>
@endsection
