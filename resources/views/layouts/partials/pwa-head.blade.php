@php $pwaTheme = panel_theme(); @endphp
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="{{ $pwaTheme['theme_color'] ?? '#4f46e5' }}">
<meta name="application-name" content="{{ app_display_name() }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ app_display_name() }}">
<link rel="apple-touch-icon" href="{{ asset('images/pwa/apple-touch-icon.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/pwa/icon-192.png') }}">
<script>
    // Only a secure origin may install the app; the worker is registered once the page is up.
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(@json(route('pwa.worker', [], false)), { scope: '/' }).catch(function () {});
        });
    }
</script>
