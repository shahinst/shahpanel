<div class="panel-modern-card mb-3">
    <div class="card-head">
        <h3><i class="bx bx-lock-alt"></i> {{ __('servers.l2tp_ipsec_settings') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">{{ __('servers.l2tp_ipsec_hint') }}</p>

        @if ($server->l2tp_use_ipsec === true || $server->l2tp_ipsec_secret_enc)
            <div class="alert alert-success py-2 px-3 small mb-3">
                <i class="bx bx-check-circle"></i>
                {{ __('servers.l2tp_ipsec_configured') }}
                @if ($server->l2tp_use_ipsec === true)
                    <span class="badge bg-success ms-1">{{ __('servers.l2tp_ipsec_enabled_badge') }}</span>
                @endif
            </div>
        @else
            <div class="alert alert-warning py-2 px-3 small mb-3">
                {{ __('servers.l2tp_ipsec_missing') }}
            </div>
        @endif

        <form method="POST"
              action="{{ route('admin.servers.l2tp-ipsec.store', $server) }}"
              class="row g-3 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label" for="l2tp-use-ipsec">{{ __('servers.l2tp_ipsec_use') }}</label>
                <select name="l2tp_use_ipsec" id="l2tp-use-ipsec" class="form-select form-select-sm">
                    <option value="1" @selected(old('l2tp_use_ipsec', $server->l2tp_use_ipsec) !== false)>{{ __('servers.l2tp_ipsec_use_yes') }}</option>
                    <option value="0" @selected(old('l2tp_use_ipsec', $server->l2tp_use_ipsec) === false)>{{ __('servers.l2tp_ipsec_use_no') }}</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="l2tp-ipsec-secret">{{ __('accounts.ipsec_secret') }}</label>
                <input type="text"
                       name="l2tp_ipsec_secret"
                       id="l2tp-ipsec-secret"
                       class="form-control form-control-sm"
                       dir="ltr"
                       value="{{ old('l2tp_ipsec_secret') }}"
                       placeholder="{{ $server->l2tp_ipsec_secret_enc ? __('servers.l2tp_ipsec_secret_unchanged') : '' }}"
                       autocomplete="off">
                <small class="text-muted">{{ __('servers.l2tp_ipsec_secret_hint') }}</small>
            </div>
            <div class="col-md-3">
                <div class="form-check mb-2">
                    <input type="checkbox"
                           name="push_to_router"
                           id="l2tp-push-to-router"
                           class="form-check-input"
                           value="1"
                           @checked(old('push_to_router', true))>
                    <label class="form-check-label small" for="l2tp-push-to-router">
                        {{ __('servers.l2tp_ipsec_push_to_router') }}
                    </label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bx bx-save"></i> {{ __('servers.l2tp_ipsec_save') }}
                </button>
            </div>
        </form>
    </div>
</div>
