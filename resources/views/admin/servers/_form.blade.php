    @php
    $server = $server ?? null;
    $selectedType = old('type', $server?->type?->value ?? \App\Enums\ServerType::Mikrotik->value);
    $isMikrotikForm = $selectedType === \App\Enums\ServerType::Mikrotik->value;
    $isPanelForm = in_array($selectedType, ['sanaei', 'pasarguard', 'remnawave'], true);
    $isCiscoForm = $selectedType === \App\Enums\ServerType::CiscoAnyconnect->value;
    $isOcservForm = $selectedType === \App\Enums\ServerType::Ocserv->value;
    $showUserPassForm = $isMikrotikForm || ($isPanelForm && $selectedType !== 'remnawave') || $isCiscoForm || $isOcservForm;
@endphp

<div class="row">
    <div class="col-lg-6">
        <div class="panel-form-section">
            <h4 class="panel-form-section-title"><i class="bx bx-info-circle align-middle"></i> {{ __('ui.section_basic_info') }}</h4>
            <x-form.group :label="__('servers.name')">
                <input name="name" value="{{ old('name', $server?->name) }}" required class="form-control">
            </x-form.group>
            <x-form.group label="{{ __('ui.col_location') }}">
                <input name="location" value="{{ old('location', $server?->location) }}" class="form-control">
            </x-form.group>
            <x-form.group :label="__('servers.type')">
                <select name="type" class="form-select" id="server-type">
                    @foreach (\App\Enums\ServerType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(old('type', $server?->type?->value) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </x-form.group>
            <div id="pasarguard-mode-group" style="display:none">
                <x-form.group :label="__('servers.pasarguard_mode')">
                    <select name="pasarguard_mode" class="form-select">
                        @foreach (\App\Enums\PasarguardConnectionMode::cases() as $mode)
                            <option value="{{ $mode->value }}" @selected(old('pasarguard_mode', $server?->pasarguard_mode?->value ?? \App\Enums\PasarguardConnectionMode::Reseller->value) === $mode->value)>
                                {{ $mode->label() }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted d-block mt-1">{{ __('servers.pasarguard_mode_hint') }}</small>
                </x-form.group>
            </div>
            <div id="server-role-group">
                <x-form.group :label="__('servers.role')">
                    <select name="role" class="form-select">
                        <option value="internal" @selected(old('role', $server?->role ?? 'internal') === 'internal')>{{ __('servers.role_internal') }}</option>
                        <option value="external" @selected(old('role', $server?->role ?? 'internal') === 'external')>{{ __('servers.role_external') }}</option>
                    </select>
                    <small class="text-muted d-block mt-1">{{ __('servers.role_hint') }}</small>
                </x-form.group>
            </div>
            <x-form.group label="{{ __('ui.max_accounts_label') }}">
                <input name="max_accounts" type="number" value="{{ old('max_accounts', $server?->max_accounts) }}" class="form-control">
            </x-form.group>
            <div class="row">
                <div class="col-sm-6">
                    <x-form.checkbox name="is_public" label="{{ __('ui.public') }}" :checked="old('is_public', $server?->is_public)" :hiddenZero="true" />
                </div>
                <div class="col-sm-6">
                    <x-form.checkbox name="is_active" label="{{ __('app.active') }}" :checked="old('is_active', $server?->is_active ?? true)" :hiddenZero="true" />
                </div>
                <div class="col-sm-6">
                    <x-form.checkbox
                        name="show_in_account_filters"
                        :label="__('servers.show_in_account_filters')"
                        :checked="old('show_in_account_filters', $server?->show_in_account_filters ?? true)"
                        :hiddenZero="true"
                    />
                    <small class="text-muted d-block">{{ __('servers.show_in_account_filters_hint') }}</small>
                </div>
                <div class="col-sm-6">
                    <x-form.checkbox
                        name="show_on_dashboard"
                        :label="__('servers.show_on_dashboard')"
                        :checked="old('show_on_dashboard', $server?->show_on_dashboard ?? true)"
                        :hiddenZero="true"
                    />
                    <small class="text-muted d-block">{{ __('servers.show_on_dashboard_hint') }}</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="panel-form-section">
            <h4 class="panel-form-section-title"><i class="bx bx-link align-middle"></i> {{ __('ui.section_connection_auth') }}</h4>
            <x-form.group :label="__('servers.host')">
                <input name="host" value="{{ old('host', $server?->host) }}" required class="form-control"
                       placeholder="panel1.example.com" id="server-host">
                <small class="text-muted d-block mt-1" id="mikrotik-host-hint" @style(['display: none' => ! $isMikrotikForm])>{{ __('servers.mikrotik_host_hint') }}</small>
                <small class="text-muted" id="panel-host-hint" style="display:none"></small>
            </x-form.group>
            <x-form.group :label="__('servers.public_ip')" id="mikrotik-public-ip-group" @style(['display: none' => ! $isMikrotikForm])>
                <input name="public_ip" value="{{ old('public_ip', $server?->public_ip) }}" class="form-control"
                       placeholder="1.2.3.4">
                <small class="text-muted d-block mt-1">{{ __('servers.mikrotik_public_ip_hint') }}</small>
            </x-form.group>
            <x-form.group :label="__('servers.port')" id="server-port-group">
                <input name="port" type="number" value="{{ old('port', $server?->port ?? 8728) }}" required class="form-control" id="server-port">
                <small class="text-muted d-block mt-1" id="mikrotik-api-port-hint" @style(['display: none' => ! $isMikrotikForm])>{{ __('servers.mikrotik_api_port_hint') }}</small>
            </x-form.group>
            <x-form.group :label="__('servers.web_base_path')" id="panel-base-path-group" style="display:none">
                <input name="web_base_path" value="{{ old('web_base_path', $server?->web_base_path ? ltrim($server->web_base_path, '/') : '') }}" class="form-control"
                       placeholder="{{ __('ui.base_path_placeholder') }}">
                <small class="text-muted d-block mt-1" id="panel-base-path-hint"></small>
            </x-form.group>
            <x-form.group :label="__('servers.panel_username')" id="panel-username-group" @style(['display: none' => ! $showUserPassForm])>
                <input name="username" class="form-control" autocomplete="off"
                       value="{{ old('username', $isMikrotikForm && $server?->username_enc ? $server->username_enc : '') }}"
                       placeholder="{{ $server && ! $isMikrotikForm ? __('ui.unchanged_placeholder') : '' }}">
                @if ($server?->isMikrotik() && $server->username_enc && $server->password_enc)
                    <small class="text-muted d-block mt-1" id="mikrotik-credentials-status">
                        <span class="text-success">{{ __('servers.mikrotik_credentials_saved') }}</span>
                    </small>
                @elseif ($server?->isMikrotik())
                    <small class="text-muted d-block mt-1" id="mikrotik-credentials-status">
                        <span class="text-danger fw-semibold">{{ __('servers.mikrotik_credentials_missing') }}</span>
                    </small>
                @endif
            </x-form.group>
            <x-form.group :label="__('servers.panel_password')" id="panel-password-group" @style(['display: none' => ! $showUserPassForm])>
                <input name="password" type="password" class="form-control" autocomplete="new-password"
                       placeholder="{{ $server ? __('ui.unchanged_placeholder') : '' }}">
                @if ($isMikrotikForm && $server)
                    <small class="text-muted d-block mt-1" id="panel-password-hint">{{ __('servers.mikrotik_credentials_edit_hint') }}</small>
                @else
                    <small class="text-muted d-block mt-1" id="panel-password-hint" style="display:none"></small>
                @endif
            </x-form.group>
            <div id="panel-token-group" style="display:none">
                <x-form.group :label="__('servers.panel_api_token')" id="panel-token-label">
                    <input name="api_token" id="server-api-token" class="form-control" autocomplete="off"
                           placeholder="{{ $server?->isRemnawave() ? __('ui.remnawave_token_placeholder') : ($server ? __('ui.unchanged_placeholder') : '') }}">
                    <small class="text-muted d-block mt-1" id="panel-token-hint"></small>
                    @if ($server?->isRemnawave())
                        <div id="remnawave-token-status" class="small mt-2">
                            @if ($server->hasStoredRemnawaveApiToken())
                                <span class="text-success">{{ __('servers.remnawave_api_token_saved') }}</span>
                            @else
                                <span class="text-danger fw-semibold">{{ __('servers.remnawave_api_token_missing_edit') }}</span>
                            @endif
                        </div>
                    @endif
                </x-form.group>
            </div>
            <div id="mikrotik-api-fields" @style(['display: none' => ! $isMikrotikForm])>
                <x-form.group :label="__('servers.mikrotik_ssh_port')">
                    <input name="ssh_port" type="number" min="1" max="65535" class="form-control"
                           value="{{ old('ssh_port', $server?->ssh_port) }}"
                           placeholder="2222">
                    <small class="text-muted d-block mt-1">{{ __('servers.mikrotik_ssh_port_hint') }}</small>
                </x-form.group>
                <x-form.group :label="__('servers.wireguard_persistent_keepalive')" id="mikrotik-wg-keepalive-group">
                    <input name="wireguard_persistent_keepalive" type="number" min="0" max="3600" class="form-control"
                           value="{{ old('wireguard_persistent_keepalive', $server?->wireguard_persistent_keepalive ?? 10) }}">
                    <small class="text-muted d-block mt-1">{{ __('servers.wireguard_persistent_keepalive_hint') }}</small>
                </x-form.group>
                <x-form.group label="{{ __('ui.api_token_label') }}">
                    <input name="mikrotik_api_token" class="form-control" placeholder="{{ __('ui.mikrotik_only_optional') }}">
                </x-form.group>
            </div>
        </div>
    </div>

    @include('admin.servers.partials.remnawave-squads', ['server' => $server])

    <div class="col-12">
        <div class="panel-form-section">
            
    <div class="col-12" id="cisco-anyconnect-section" @style(['display: none' => ! $isCiscoForm])>
        <div class="panel-form-section">
            <h4 class="panel-form-section-title"><i class="bx bx-network-chart align-middle"></i> Cisco AnyConnect / ASA</h4>
            <p class="text-muted small">{{ __('servers.cisco_anyconnect_section_hint') }}</p>
            <div class="row">
                <div class="col-md-6">
                    <x-form.group :label="__('servers.cisco_vpn_hostname')">
                        <input name="cisco_vpn_hostname" value="{{ old('cisco_vpn_hostname', $server?->cisco_vpn_hostname) }}" class="form-control" dir="ltr" placeholder="vpn.example.com">
                        <small class="text-muted d-block mt-1">{{ __('servers.cisco_vpn_hostname_hint') }}</small>
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.group :label="__('servers.cisco_group_policy')">
                        <input name="cisco_group_policy" value="{{ old('cisco_group_policy', $server?->cisco_group_policy) }}" class="form-control" dir="ltr" placeholder="DefaultRAGroup">
                        <small class="text-muted d-block mt-1">{{ __('servers.cisco_group_policy_hint') }}</small>
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.group :label="__('servers.cisco_tunnel_group')">
                        <input name="cisco_tunnel_group" value="{{ old('cisco_tunnel_group', $server?->cisco_tunnel_group) }}" class="form-control" dir="ltr" placeholder="AnyConnect">
                        <small class="text-muted d-block mt-1">{{ __('servers.cisco_tunnel_group_hint') }}</small>
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.group :label="__('servers.cisco_simultaneous_logins')">
                        <input name="cisco_simultaneous_logins" type="number" min="0" max="100" value="{{ old('cisco_simultaneous_logins', $server?->cisco_simultaneous_logins ?? 1) }}" class="form-control">
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.checkbox name="cisco_verify_ssl" :label="__('servers.cisco_verify_ssl')" :checked="old('cisco_verify_ssl', $server?->cisco_verify_ssl ?? false)" :hiddenZero="true" />
                    <small class="text-muted d-block">{{ __('servers.cisco_verify_ssl_hint') }}</small>
                </div>
                <div class="col-md-6">
                    <x-form.checkbox name="cisco_write_memory" :label="__('servers.cisco_write_memory')" :checked="old('cisco_write_memory', $server?->cisco_write_memory ?? true)" :hiddenZero="true" />
                    <small class="text-muted d-block">{{ __('servers.cisco_write_memory_hint') }}</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12" id="ocserv-section" @style(['display: none' => ! $isOcservForm])>
        <div class="panel-form-section">
            <h4 class="panel-form-section-title"><i class="bx bx-globe align-middle"></i> OpenConnect (ocserv)</h4>
            <p class="text-muted small">{{ __('servers.ocserv_section_hint') }}</p>
            <div class="row">
                <div class="col-md-6">
                    <x-form.group :label="__('servers.ocserv_vpn_address')">
                        <input name="ocserv_vpn_address" value="{{ old('ocserv_vpn_address', $server?->ocserv_vpn_address) }}" class="form-control" dir="ltr" placeholder="ocserv1.example.com">
                        <small class="text-muted d-block mt-1">{{ __('servers.ocserv_vpn_address_hint') }}</small>
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.group :label="__('servers.ocserv_default_max_sessions')">
                        <input name="ocserv_default_max_sessions" type="number" min="0" max="1000" value="{{ old('ocserv_default_max_sessions', $server?->ocserv_default_max_sessions ?? 1) }}" class="form-control">
                        <small class="text-muted d-block mt-1">{{ __('servers.ocserv_default_max_sessions_hint') }}</small>
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.group :label="__('servers.ocserv_group')">
                        <input name="ocserv_group" value="{{ old('ocserv_group', $server?->ocserv_group) }}" class="form-control" dir="ltr" placeholder="">
                        <small class="text-muted d-block mt-1">{{ __('servers.ocserv_group_hint') }}</small>
                    </x-form.group>
                </div>
                <div class="col-md-6">
                    <x-form.checkbox name="ocserv_verify_ssl" :label="__('servers.ocserv_verify_ssl')" :checked="old('ocserv_verify_ssl', $server?->ocserv_verify_ssl ?? true)" :hiddenZero="true" />
                    <small class="text-muted d-block">{{ __('servers.ocserv_verify_ssl_hint') }}</small>
                </div>
            </div>
        </div>
    </div>


                    <h4 class="panel-form-section-title"><i class="bx bx-note align-middle"></i> {{ __('ui.col_note') }}</h4>
            <x-form.group wide label="{{ __('ui.col_note') }}">
                <textarea name="notes" rows="3" class="form-control">{{ old('notes', $server?->notes) }}</textarea>
            </x-form.group>
        </div>
    </div>

    <x-form.actions>
        <x-button type="submit"><i class="bx bx-save"></i> {{ __('app.save') }}</x-button>
        <x-button :href="$cancelUrl ?? route('admin.servers.index')" variant="secondary">{{ __('app.cancel') }}</x-button>
    </x-form.actions>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect = document.getElementById('server-type') || document.querySelector('select[name="type"]');
    var portInput = document.getElementById('server-port');
    var mikrotikHostHint = document.getElementById('mikrotik-host-hint');
    var panelHint = document.getElementById('panel-host-hint');
    var mikrotikPublicIpGroup = document.getElementById('mikrotik-public-ip-group');
    var basePathGroup = document.getElementById('panel-base-path-group');
    var basePathHint = document.getElementById('panel-base-path-hint');
    var panelUser = document.getElementById('panel-username-group');
    var panelPass = document.getElementById('panel-password-group');
    var panelToken = document.getElementById('panel-token-group');
    var panelTokenHint = document.getElementById('panel-token-hint');
    var mikrotikApiFields = document.getElementById('mikrotik-api-fields');
    var pasarguardModeGroup = document.getElementById('pasarguard-mode-group');
    var serverRoleGroup = document.getElementById('server-role-group');
    var panelTokenLabel = document.getElementById('panel-token-label');
    var serverApiToken = document.getElementById('server-api-token');
    var defaultTokenLabel = @json(__('servers.panel_api_token'));
    var authLabels = {
        username: { panel: @json(__('servers.panel_username')), mikrotik: @json(__('servers.mikrotik_username')) },
        password: { panel: @json(__('servers.panel_password')), mikrotik: @json(__('servers.mikrotik_password')) },
    };
    var authHints = {
        mikrotikEdit: @json(__('servers.mikrotik_credentials_edit_hint')),
    };
    var hints = {
        sanaei: { host: @json(__('servers.sanaei_host_hint')), base: @json(__('servers.sanaei_base_path_hint')), token: @json(__('servers.sanaei_token_hint')), port: '2053' },
        pasarguard: { host: @json(__('servers.pasarguard_host_hint')), base: @json(__('servers.pasarguard_base_path_hint')), token: @json(__('servers.pasarguard_token_hint')), port: '443' },
        remnawave: { host: @json(__('servers.remnawave_host_hint')), base: @json(__('servers.remnawave_base_path_hint')), token: @json(__('servers.remnawave_token_hint')), tokenLabel: @json(__('servers.remnawave_api_token')), port: '443' },
        cisco_anyconnect: { host: @json(__('servers.cisco_anyconnect_host_hint')), base: '', token: '', port: '443' },
        ocserv: { host: @json(__('servers.ocserv_host_hint')), base: '', token: '', port: '9443' }
    };
    if (!typeSelect) return;

    function setFieldGroupEnabled(group, enabled) {
        if (!group) return;
        group.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !enabled;
        });
    }

    function setGroupLabel(groupId, text) {
        var group = document.getElementById(groupId);
        if (!group) return;
        var label = group.querySelector('label.form-label');
        if (label) label.textContent = text;
    }

    function syncServerTypeUi() {
        var type = typeSelect.value;
        var isPanel = type === 'sanaei' || type === 'pasarguard' || type === 'remnawave';
        var isMikrotik = type === 'mikrotik';
        var isCisco = type === 'cisco_anyconnect';
        var isOcserv = type === 'ocserv';
        var showUserPass = (isPanel && type !== 'remnawave') || isMikrotik || isCisco || isOcserv;
        var cfg = hints[type] || null;
        if (panelHint) {
            panelHint.textContent = cfg ? cfg.host : '';
            panelHint.style.display = (cfg && !isMikrotik) ? 'block' : 'none';
        }
        if (mikrotikHostHint) mikrotikHostHint.style.display = isMikrotik ? 'block' : 'none';
        var mikrotikApiPortHint = document.getElementById('mikrotik-api-port-hint');
        if (mikrotikApiPortHint) mikrotikApiPortHint.style.display = isMikrotik ? 'block' : 'none';
        var portGroup = document.getElementById('server-port-group');
        if (portGroup) {
            var portLabel = portGroup.querySelector('label.form-label');
            if (portLabel) portLabel.textContent = isMikrotik ? @json(__('servers.mikrotik_api_port')) : @json(__('servers.port'));
        }
        if (mikrotikPublicIpGroup) mikrotikPublicIpGroup.style.display = isMikrotik ? 'block' : 'none';
        if (basePathGroup) basePathGroup.style.display = isPanel ? 'block' : 'none';
        if (basePathHint) basePathHint.textContent = cfg ? cfg.base : '';
        if (panelUser) panelUser.style.display = showUserPass ? 'block' : 'none';
        if (panelPass) panelPass.style.display = showUserPass ? 'block' : 'none';
        setGroupLabel('panel-username-group', isMikrotik ? authLabels.username.mikrotik : authLabels.username.panel);
        setGroupLabel('panel-password-group', isMikrotik ? authLabels.password.mikrotik : authLabels.password.panel);
        var passHint = document.getElementById('panel-password-hint');
        if (passHint) {
            passHint.textContent = isMikrotik ? authHints.mikrotikEdit : '';
            passHint.style.display = isMikrotik ? 'block' : 'none';
        }
        if (panelToken) panelToken.style.display = isPanel ? 'block' : 'none';
        if (panelTokenHint) panelTokenHint.textContent = cfg ? cfg.token : '';
        if (panelTokenLabel) {
            var labelEl = panelTokenLabel.querySelector('label') || panelTokenLabel;
            labelEl.textContent = (type === 'remnawave' && cfg && cfg.tokenLabel) ? cfg.tokenLabel : defaultTokenLabel;
        }
        if (mikrotikApiFields) mikrotikApiFields.style.display = isMikrotik ? 'block' : 'none';
        setFieldGroupEnabled(document.getElementById('panel-token-group'), isPanel);
        setFieldGroupEnabled(document.getElementById('panel-username-group'), showUserPass);
        setFieldGroupEnabled(document.getElementById('panel-password-group'), showUserPass);
        setFieldGroupEnabled(mikrotikApiFields, isMikrotik);
        if (pasarguardModeGroup) pasarguardModeGroup.style.display = type === 'pasarguard' ? 'block' : 'none';
        if (serverRoleGroup) serverRoleGroup.style.display = type === 'pasarguard' ? 'none' : 'block';
        var rwSquads = document.getElementById('remnawave-squads-section');
        if (rwSquads) rwSquads.style.display = type === 'remnawave' ? 'block' : 'none';
        var ciscoSec = document.getElementById('cisco-anyconnect-section');
        if (ciscoSec) ciscoSec.style.display = isCisco ? 'block' : 'none';
        setFieldGroupEnabled(ciscoSec, isCisco);
        var ocservSec = document.getElementById('ocserv-section');
        if (ocservSec) ocservSec.style.display = isOcserv ? 'block' : 'none';
        setFieldGroupEnabled(ocservSec, isOcserv);
        if (cfg && portInput && (portInput.value === '8728' || portInput.value === '' || portInput.value === '2053' || portInput.value === '443' || portInput.value === '9443')) {
            if (isCisco || isOcserv || isPanel) portInput.value = cfg.port;
            else if (isMikrotik) portInput.value = '8728';
            else portInput.value = cfg.port;
        }
    }

    typeSelect.addEventListener('change', syncServerTypeUi);
    syncServerTypeUi();
});
</script>
@endpush
