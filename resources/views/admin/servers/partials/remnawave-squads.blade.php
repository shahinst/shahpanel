@php
    $server = $server ?? null;
    $catalog = $server?->remnawaveSquadCatalog() ?? [];
    $activeUuids = array_fill_keys(
        old('remnawave_active_squads', $server?->remnawaveActiveSquadUuids() ?? []),
        true,
    );
@endphp

<div id="remnawave-squads-section" class="col-12" style="{{ ($server?->isRemnawave() || old('type') === 'remnawave') ? '' : 'display:none' }}">
    <div class="panel-form-section border rounded p-3 bg-light">
        <h4 class="panel-form-section-title h6 mb-2">
            <i class="bx bx-group align-middle"></i> {{ __('servers.remnawave_active_squads') }}
        </h4>
        <p class="text-muted small mb-3">{{ __('servers.remnawave_active_squads_hint') }}</p>

        @if ($catalog === [])
            <x-alert type="warning" class="mb-0">
                {{ __('servers.remnawave_active_squads_none_synced') }}
                @if ($server)
                    — <a href="{{ route('admin.servers.show', $server) }}">{{ __('servers.sync_remnawave_catalog') }}</a>
                @endif
            </x-alert>
        @else
            <div class="row g-2">
                @foreach ($catalog as $squad)
                    @php $uuid = (string) ($squad['uuid'] ?? ''); @endphp
                    @continue($uuid === '')
                    <div class="col-md-6 col-lg-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="remnawave_active_squads[]"
                                   value="{{ $uuid }}" id="rw_squad_{{ $uuid }}"
                                   @checked(isset($activeUuids[$uuid]))>
                            <label class="form-check-label" for="rw_squad_{{ $uuid }}">
                                <strong>{{ $squad['name'] ?? $uuid }}</strong>
                                <br><code class="small">{{ $uuid }}</code>
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($server?->remnawave_squads_synced_at)
                <p class="text-muted small mt-3 mb-0">
                    {{ __('servers.remnawave_squads_synced_at') }}:
                    {{ persian_digits($server->remnawave_squads_synced_at->format('Y-m-d H:i')) }}
                    — {{ __('servers.remnawave_squads_synced_count', ['count' => persian_digits(count($catalog))]) }}
                </p>
            @endif
        @endif

        @if (! $server)
            <div class="form-check mt-3">
                <input type="checkbox" class="form-check-input" name="remnawave_sync_after_save" value="1" id="remnawave_sync_after_save" checked>
                <label class="form-check-label" for="remnawave_sync_after_save">{{ __('servers.remnawave_sync_after_save') }}</label>
            </div>
            <p class="text-muted small mb-0">{{ __('servers.remnawave_sync_after_save_hint') }}</p>
        @endif
    </div>
</div>
