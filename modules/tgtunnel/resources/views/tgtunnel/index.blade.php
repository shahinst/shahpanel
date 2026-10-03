@extends('layouts.panel')

@section('page_title', __('tgtunnel::tunnel.title'))

@section('panel_content')
    <x-page-header :title="__('tgtunnel::tunnel.title')">
        <p class="text-muted mb-0">{{ __('tgtunnel::tunnel.subtitle') }}</p>
    </x-page-header>

    @if (! $installed)
        <x-alert type="warning">{{ __('tgtunnel::tunnel.helper_missing_hint') }}</x-alert>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h6 class="mb-2">{{ __('tgtunnel::tunnel.status') }}</h6>
                    @include('tgtunnel::_status', ['status' => $status])
                </div>
                @if ($status !== null && $status['configured'])
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('admin.tgtunnel.test') }}">@csrf
                            <button type="submit" class="btn btn-outline-primary"><i class="bx bx-pulse"></i> {{ __('tgtunnel::tunnel.test') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.tgtunnel.disconnect') }}" data-confirm="{{ __('tgtunnel::tunnel.remove_confirm') }}">@csrf
                            <button type="submit" class="btn btn-outline-danger"><i class="bx bx-unlink"></i> {{ __('tgtunnel::tunnel.remove') }}</button>
                        </form>
                    </div>
                @endif
            </div>

            @if (is_array($test))
                <hr>
                @if ($test['ok'] ?? false)
                    <x-alert type="success">{{ __('tgtunnel::tunnel.test_ok', ['http' => $test['http'] ?? '', 'bytes' => number_format((int) ($test['bytes_received'] ?? 0))]) }}</x-alert>
                @else
                    @php $reason = $test['reason'] ?? ''; @endphp
                    <x-alert type="error">
                        {{ in_array($reason, ['not_running', 'not_routed', 'no_return', 'telegram_unreachable', 'dns_not_pinned'], true)
                            ? __('tgtunnel::tunnel.reason_'.$reason)
                            : __('tgtunnel::tunnel.test_failed', ['error' => $test['error'] ?? ('HTTP '.($test['http'] ?? '000'))]) }}
                    </x-alert>
                @endif
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h6>{{ __('tgtunnel::tunnel.config') }}</h6>
            <p class="text-muted small">{{ __('tgtunnel::tunnel.config_hint') }}</p>
            <form method="POST" action="{{ route('admin.tgtunnel.save') }}">
                @csrf
                <textarea name="config" rows="12" class="form-control font-monospace" dir="ltr" required
                    placeholder="[Interface]&#10;PrivateKey = …&#10;Address = 10.0.0.2/32&#10;&#10;[Peer]&#10;PublicKey = …&#10;Endpoint = 1.2.3.4:51820">{{ old('config') }}</textarea>
                <p class="text-muted small mt-2">{{ __('tgtunnel::tunnel.config_safety') }}</p>
                <button type="submit" class="btn btn-primary"><i class="bx bx-link"></i> {{ __('tgtunnel::tunnel.connect') }}</button>
            </form>
        </div>
    </div>
@endsection
