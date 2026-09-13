@php
    $ovpnProfiles = app(\App\Services\ServerOvpnProfileService::class);
    $hasOvpnProfile = $ovpnProfiles->hasProfile($server);
@endphp

<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-upload"></i> {{ __('servers.ovpn_profile_upload') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">{{ __('servers.ovpn_profile_hint') }}</p>

        @if ($hasOvpnProfile)
            <div class="alert alert-success py-2 px-3 small mb-3">
                <i class="bx bx-check-circle"></i>
                {{ __('servers.ovpn_profile_current') }}:
                <code dir="ltr">{{ $server->ovpn_profile_original_name ?? 'profile.ovpn' }}</code>
            </div>
        @else
            <div class="alert alert-warning py-2 px-3 small mb-3">
                {{ __('servers.ovpn_profile_missing') }}
            </div>
        @endif

        <form method="POST"
              action="{{ route('admin.servers.ovpn-profile.store', $server) }}"
              enctype="multipart/form-data"
              class="row g-3 align-items-end">
            @csrf
            <div class="col-md-8">
                <label class="form-label" for="ovpn-profile-file">{{ __('servers.ovpn_profile_file') }}</label>
                <input type="file"
                       name="ovpn_profile"
                       id="ovpn-profile-file"
                       class="form-control form-control-sm"
                       accept=".ovpn,application/x-openvpn-profile"
                       required>
                <small class="text-muted">{{ __('servers.ovpn_profile_file_hint') }}</small>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bx bx-upload"></i> {{ __('servers.ovpn_profile_upload_btn') }}
                </button>
            </div>
        </form>

        @if ($hasOvpnProfile)
            <form method="POST"
                  action="{{ route('admin.servers.ovpn-profile.destroy', $server) }}"
                  class="mt-3"
                  onsubmit="return confirm(@json(__('servers.ovpn_profile_delete_confirm')));">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bx bx-trash"></i> {{ __('servers.ovpn_profile_delete') }}
                </button>
            </form>
        @endif
    </div>
</div>
