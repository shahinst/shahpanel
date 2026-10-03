{{-- Tunnel state as a badge plus figures; used by the module page and the dashboard. --}}
@php
    $fmt = fn ($b) => $b >= 1073741824 ? round($b / 1073741824, 2).' GB' : ($b >= 1048576 ? round($b / 1048576, 1).' MB' : round($b / 1024).' KB');
@endphp
@if ($status === null)
    <span class="badge bg-secondary">{{ __('tgtunnel::tunnel.helper_missing') }}</span>
@elseif (! $status['configured'])
    <span class="badge bg-secondary">{{ __('tgtunnel::tunnel.not_configured') }}</span>
@elseif ($status['healthy'])
    <span class="badge bg-success"><i class="bx bx-check-circle"></i> {{ __('tgtunnel::tunnel.connected') }}</span>
@else
    <span class="badge bg-danger"><i class="bx bx-error-circle"></i> {{ __('tgtunnel::tunnel.disconnected') }}</span>
@endif
@if ($status !== null && $status['configured'])
    <div class="small text-muted mt-2" dir="ltr">
        {{ __('tgtunnel::tunnel.last_handshake') }}:
        {{ $status['handshake_age'] >= 0 ? __('tgtunnel::tunnel.seconds_ago', ['n' => $status['handshake_age']]) : '—' }}
        · ↓ {{ $fmt($status['rx']) }} · ↑ {{ $fmt($status['tx']) }}
        · {{ __('tgtunnel::tunnel.telegram_via') }}: {{ $status['telegram_via'] ?: '—' }}
    </div>
@endif
