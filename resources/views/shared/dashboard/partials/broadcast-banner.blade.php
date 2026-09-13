@php
    $featured = $banner['featured'] ?? null;
    $history = $banner['history'] ?? [];
@endphp

@if ($featured)
    <section class="broadcast-banner" id="broadcast-banner" aria-label="{{ __('broadcasts.banner_title') }}">
        <div class="broadcast-banner__inner">
            <div class="broadcast-banner__head">
                <div class="broadcast-banner__label">
                    <i class="bx bx-broadcast"></i>
                    <span>{{ __('broadcasts.banner_title') }}</span>
                </div>
                <button type="button"
                        class="broadcast-banner__close"
                        data-broadcast-dismiss
                        data-notification-id="{{ $featured['notification_id'] }}"
                        data-dismiss-url="{{ route('broadcast-banner.dismiss') }}"
                        aria-label="{{ __('broadcasts.dismiss') }}">
                    <i class="bx bx-x"></i>
                </button>
            </div>

            <div class="broadcast-banner__body">
                @if (! empty($featured['image_url']))
                    <button type="button"
                            class="broadcast-banner__thumb"
                            data-broadcast-lightbox="{{ $featured['image_url'] }}"
                            aria-label="{{ __('broadcasts.image_alt') }}">
                        <img src="{{ $featured['image_url'] }}" alt="{{ __('broadcasts.image_alt') }}">
                    </button>
                @endif

                <div class="broadcast-banner__content">
                    <h3 class="broadcast-banner__title">{{ $featured['title'] }}</h3>
                    @if (! empty($featured['sender_name']))
                        <div class="broadcast-banner__meta">{{ __('broadcasts.from_sender', ['name' => $featured['sender_name']]) }}</div>
                    @endif
                    <p class="broadcast-banner__text">{{ $featured['body'] }}</p>
                    <div class="broadcast-banner__footer">
                        <time class="broadcast-banner__date">{{ jalali_date($featured['created_at']) }}</time>
                        @if (! empty($featured['link']))
                            <a href="{{ $featured['link'] }}" class="broadcast-banner__link" target="_blank" rel="noopener">
                                {{ __('broadcasts.open_link') }} <i class="bx bx-link-external"></i>
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            @if (count($history) > 0)
                <div class="broadcast-banner__history">
                    <button type="button"
                            class="broadcast-banner__history-toggle"
                            data-broadcast-history-toggle
                            aria-expanded="false"
                            aria-controls="broadcast-history-panel">
                        <span class="broadcast-banner__history-label" data-broadcast-history-label-show>{{ __('broadcasts.view_previous') }} ({{ persian_digits(count($history)) }})</span>
                        <span class="broadcast-banner__history-label" data-broadcast-history-label-hide hidden>{{ __('broadcasts.hide_previous') }}</span>
                        <i class="bx bx-chevron-down"></i>
                    </button>

                    <div id="broadcast-history-panel" class="broadcast-banner__history-panel">
                        @foreach ($history as $item)
                            <article class="broadcast-banner__history-item">
                                <div class="broadcast-banner__history-main">
                                    @if (! empty($item['image_url']))
                                        <button type="button"
                                                class="broadcast-banner__thumb broadcast-banner__thumb--sm"
                                                data-broadcast-lightbox="{{ $item['image_url'] }}"
                                                aria-label="{{ __('broadcasts.image_alt') }}">
                                            <img src="{{ $item['image_url'] }}" alt="{{ __('broadcasts.image_alt') }}">
                                        </button>
                                    @endif
                                    <div>
                                        <h4 class="broadcast-banner__history-title">{{ $item['title'] }}</h4>
                                        <p class="broadcast-banner__history-text">{{ $item['body'] }}</p>
                                        <div class="broadcast-banner__footer">
                                            <time class="broadcast-banner__date">{{ jalali_date($item['created_at']) }}</time>
                                            @if (! empty($item['link']))
                                                <a href="{{ $item['link'] }}" class="broadcast-banner__link" target="_blank" rel="noopener">
                                                    {{ __('broadcasts.open_link') }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>

    <div class="broadcast-lightbox" id="broadcast-lightbox" hidden aria-hidden="true">
        <button type="button" class="broadcast-lightbox__close" data-broadcast-lightbox-close aria-label="{{ __('broadcasts.dismiss') }}">
            <i class="bx bx-x"></i>
        </button>
        <img src="" alt="{{ __('broadcasts.image_alt') }}" class="broadcast-lightbox__img" data-broadcast-lightbox-img>
    </div>

    <script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrf ? csrf.getAttribute('content') : '';

        document.querySelectorAll('[data-broadcast-dismiss]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                var banner = document.getElementById('broadcast-banner');
                var lightbox = document.getElementById('broadcast-lightbox');
                var url = button.getAttribute('data-dismiss-url');
                var notificationId = button.getAttribute('data-notification-id');

                if (! url || ! notificationId) {
                    return;
                }

                var hideBanner = function () {
                    if (banner) banner.remove();
                    if (lightbox) lightbox.remove();
                };

                hideBanner();

                if (! csrfToken || typeof fetch !== 'function') {
                    return;
                }

                var body = new URLSearchParams();
                body.append('_token', csrfToken);
                body.append('notification_id', notificationId);

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body.toString(),
                    credentials: 'same-origin',
                }).catch(function () {});
            });
        });

        document.querySelectorAll('[data-broadcast-history-toggle]').forEach(function (toggle) {
            var panelId = toggle.getAttribute('aria-controls');
            var panel = panelId ? document.getElementById(panelId) : null;
            if (! panel) return;

            var labelShow = toggle.querySelector('[data-broadcast-history-label-show]');
            var labelHide = toggle.querySelector('[data-broadcast-history-label-hide]');

            toggle.addEventListener('click', function (event) {
                event.preventDefault();

                var isOpen = panel.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

                if (labelShow) labelShow.hidden = isOpen;
                if (labelHide) labelHide.hidden = ! isOpen;
            });
        });

        var lightbox = document.getElementById('broadcast-lightbox');
        var lightboxImg = lightbox ? lightbox.querySelector('[data-broadcast-lightbox-img]') : null;

        var closeLightbox = function () {
            if (! lightbox) return;
            lightbox.hidden = true;
            lightbox.setAttribute('aria-hidden', 'true');
            if (lightboxImg) lightboxImg.removeAttribute('src');
            document.body.style.overflow = '';
        };

        document.querySelectorAll('[data-broadcast-lightbox]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                if (! lightbox || ! lightboxImg) return;
                lightboxImg.src = button.getAttribute('data-broadcast-lightbox') || '';
                lightbox.hidden = false;
                lightbox.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            });
        });

        if (lightbox) {
            lightbox.addEventListener('click', function (event) {
                if (event.target === lightbox) closeLightbox();
            });
            var closeBtn = lightbox.querySelector('[data-broadcast-lightbox-close]');
            if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && lightbox && ! lightbox.hidden) closeLightbox();
            });
        }
    })();
    </script>
@endif
