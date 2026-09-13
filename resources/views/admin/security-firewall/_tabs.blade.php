@php
    $securitySection = $securitySection ?? 'panel';
    $sections = [
        'panel' => [
            'label' => __('security.section_panel'),
            'url' => route('admin.security.index'),
        ],
        'login' => [
            'label' => __('security.section_login'),
            'url' => route('admin.login-firewall.index'),
        ],
        'server' => [
            'label' => __('security.section_server'),
            'url' => route('admin.web-shield.index'),
        ],
    ];
@endphp

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h2 class="h4 mb-0">{{ __('security.hub_title') }}</h2>
</div>

<ul class="nav nav-pills flex-wrap gap-2 mb-4">
    @foreach ($sections as $key => $item)
        <li class="nav-item">
            <a href="{{ $item['url'] }}" @class(['nav-link', 'active' => $securitySection === $key])>
                {{ $item['label'] }}
            </a>
        </li>
    @endforeach
</ul>
