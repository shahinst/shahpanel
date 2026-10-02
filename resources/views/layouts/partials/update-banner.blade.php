{{--
    "A new version is out" notice for the panel owner. Reads only the cached
    check (panel:check-update runs hourly); when there is no cached answer yet
    it is fetched once after this response, so no page waits on GitHub.
--}}
@php
    $updBanner = null;

    if (($panel ?? null) === 'admin' && auth()->check() && is_super_admin()) {
        $updVersions = app(\App\Services\PanelVersionService::class);
        $updState = $updVersions->cachedState();

        if ($updState === null && \Illuminate\Support\Facades\Cache::add('panel_update_check_pending', 1, now()->addMinutes(10))) {
            app()->terminating(fn () => $updVersions->refresh());
        }

        if ($updVersions->updateAvailable($updState) && ! request()->routeIs('admin.updates.*')) {
            $updBanner = $updVersions->latestLabel($updState);
        }
    }
@endphp
@if ($updBanner !== null)
    <div class="upd-banner" id="upd-banner" data-version="{{ $updBanner }}" role="status">
        <i class="bx bx-rocket upd-banner__icon"></i>
        <span class="upd-banner__text">{{ __('updates.banner', ['version' => $updBanner]) }}</span>
        <a href="{{ route('admin.updates.index') }}" class="upd-banner__btn">{{ __('updates.banner_action') }}</a>
        <button type="button" class="upd-banner__close" id="upd-banner-close" aria-label="{{ __('updates.banner_dismiss') }}">&times;</button>
    </div>
    <style>
        .upd-banner { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; padding: 12px 16px; border-radius: 12px; color: #fff; background: linear-gradient(120deg, #1668dc 0%, #6d3fd6 100%); box-shadow: 0 6px 18px rgba(22, 104, 220, .25); }
        .upd-banner__icon { font-size: 1.6rem; }
        .upd-banner__text { flex: 1 1 260px; font-weight: 600; }
        .upd-banner__btn { background: #fff; color: #1668dc !important; font-weight: 700; padding: 6px 14px; border-radius: 8px; text-decoration: none; white-space: nowrap; }
        .upd-banner__close { background: none; border: 0; color: #fff; font-size: 1.5rem; line-height: 1; opacity: .8; cursor: pointer; }
    </style>
    <script>
        (function () {
            var banner = document.getElementById('upd-banner');
            if (!banner) return;
            var key = 'upd-banner-dismissed:' + banner.dataset.version;
            try { if (sessionStorage.getItem(key)) { banner.remove(); return; } } catch (e) {}
            document.getElementById('upd-banner-close').addEventListener('click', function () {
                try { sessionStorage.setItem(key, '1'); } catch (e) {}
                banner.remove();
            });
        })();
    </script>
@endif
