{{-- Dashboard card: is Telegram's traffic going through the tunnel right now. --}}
@php $tgStatus = rescue(fn () => app(\Modules\TgTunnel\Support\Tunnel::class)->status(), null, false); @endphp
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h6 class="mb-2"><i class="bx bxl-telegram"></i> {{ __('tgtunnel::tunnel.title') }}</h6>
            @include('tgtunnel::_status', ['status' => $tgStatus])
        </div>
        <a href="{{ route('admin.tgtunnel.index') }}" class="btn btn-sm btn-outline-primary">{{ __('tgtunnel::tunnel.manage') }}</a>
    </div>
</div>
