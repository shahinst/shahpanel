@extends('layouts.panel')

@section('page_title', __('security.hub_title'))

@section('panel_content')
@include('admin.security-firewall._tabs', ['securitySection' => 'panel'])

@php($errors = $errors ?? new \Illuminate\Support\ViewErrorBag())


<div class="row">
    <div class="col-lg-10">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ __('security.portal_paths') }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.security.paths.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="portal_path_admin">{{ __('security.portal_path_admin') }}</label>
                            <input type="text" class="form-control @error('portal_path_admin') is-invalid @enderror"
                                   id="portal_path_admin" name="portal_path_admin"
                                   value="{{ old('portal_path_admin', $paths['admin']) }}" dir="ltr" required>
                            @error('portal_path_admin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="text-muted">/{{ $paths['admin'] }}/login</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="portal_path_agent">{{ __('security.portal_path_agent') }}</label>
                            <input type="text" class="form-control @error('portal_path_agent') is-invalid @enderror"
                                   id="portal_path_agent" name="portal_path_agent"
                                   value="{{ old('portal_path_agent', $paths['agent']) }}" dir="ltr" required>
                            @error('portal_path_agent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="portal_path_seller">{{ __('security.portal_path_seller') }}</label>
                            <input type="text" class="form-control @error('portal_path_seller') is-invalid @enderror"
                                   id="portal_path_seller" name="portal_path_seller"
                                   value="{{ old('portal_path_seller', $paths['seller']) }}" dir="ltr" required>
                            @error('portal_path_seller')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="block_legacy_portal_paths"
                               name="block_legacy_portal_paths" value="1"
                               @checked(old('block_legacy_portal_paths', $blockLegacy))>
                        <label class="form-check-label" for="block_legacy_portal_paths">
                            {{ __('security.block_legacy_portal_paths') }}
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">{{ __('app.save') }}</button>
                </form>
            </div>
        </div>

        @if ($htaccessPending)
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">{{ __('security.htaccess_title') }}</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">{{ __('security.htaccess_auto_hint') }}</p>

                    @foreach (['admin', 'agent', 'seller'] as $role)
                        @if ($htaccessStatus[$role] ?? false)
                            @continue
                        @endif

                        <div class="border rounded p-3 mb-3">
                            <h6 class="mb-2">{{ __("security.portal_path_{$role}") }}</h6>
                            <p class="text-muted small mb-3">{{ __('security.htaccess_apply_for', ['path' => $paths[$role]]) }}</p>

                            <form method="POST" action="{{ \Illuminate\Support\Facades\Route::has('admin.security.htaccess.apply') ? route('admin.security.htaccess.apply', ['role' => $role]) : '#' }}" class="row g-2 align-items-end">
                                @csrf
                                <div class="col-md-4">
                                    <label class="form-label" for="htaccess-user-{{ $role }}">{{ __('security.htaccess_username') }}</label>
                                    <input type="text" class="form-control" id="htaccess-user-{{ $role }}" name="username"
                                           value="{{ old('username') }}" dir="ltr" required autocomplete="username">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="htaccess-pass-{{ $role }}">{{ __('security.htaccess_password') }}</label>
                                    <input type="password" class="form-control" id="htaccess-pass-{{ $role }}" name="password"
                                           required minlength="6" autocomplete="new-password">
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bx bx-wrench align-middle"></i> {{ __('security.htaccess_fix') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ __('security.firewall_title') }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.security.firewall.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="firewall_enabled"
                               name="firewall_enabled" value="1"
                               @checked(old('firewall_enabled', $firewallEnabled ?? true))>
                        <label class="form-check-label" for="firewall_enabled">
                            {{ __('security.firewall_enabled') }}
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="admin_ip_whitelist">{{ __('security.admin_ip_whitelist') }}</label>
                        <input type="text" class="form-control @error('admin_ip_whitelist') is-invalid @enderror"
                               id="admin_ip_whitelist" name="admin_ip_whitelist" dir="ltr"
                               value="{{ old('admin_ip_whitelist', $adminIpWhitelist ?? '') }}"
                               placeholder="1.2.3.4, 5.6.7.8">
                        @error('admin_ip_whitelist')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">{{ __('security.admin_ip_whitelist_hint') }}</small>
                    </div>

                    <button type="submit" class="btn btn-primary">{{ __('app.save') }}</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ __('security.l7_protection') }}</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">{{ __('security.l7_protection_hint') }}</p>
                <ul class="mt-2 mb-0">
                    <li>محدودیت ورود: ۵ تلاش در دقیقه</li>
                    <li>محدودیت ۲FA: ۱۰ تلاش در دقیقه</li>
                    <li>محدودیت پنل خریدار: ۱۲۰ درخواست در دقیقه</li>
                    <li>فایروال L7: اسکن الگوهای SQLi / XSS / Path Traversal</li>
                    <li>هدرهای X-Frame-Options، CSP، X-Content-Type-Options، HSTS (HTTPS)</li>
                    <li>هدایت خودکار از مسیرهای legacy به مسیر جدید</li>
                    <li>ورود به پنل دیگر (impersonation): حداکثر {{ (int) config('vpnpanel.impersonation_ttl_minutes', 3) }} دقیقه با تمدید در هر فعالیت</li>
                    @if (! ($htaccessPending ?? true))
                        <li class="text-success">{{ __('security.htaccess_all_applied') }}</li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
