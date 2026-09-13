{{-- Account action menus: core behavior is vanilla JS in resources/js/app.js (data-account-actions-*).
     The block below is a self-contained enhancement that flips the ⋮ menu ABOVE the button
     when there isn't enough room below (rows near the bottom of the page), so it never gets
     clipped by the viewport. It runs after the core handler and only adjusts vertical position. --}}
@once
@push('scripts')
<script>
(function () {
    'use strict';
    var MARGIN = 8;

    function adjustOpenMenu() {
        var menu = document.querySelector('[data-account-actions-menu].show');
        var toggle = document.querySelector('[data-account-actions-toggle][aria-expanded="true"]');
        if (!menu || !toggle) {
            return;
        }

        // Let the menu size naturally first, then measure.
        menu.style.maxHeight = '';
        menu.style.overflowY = '';

        var rect = toggle.getBoundingClientRect();
        var menuHeight = menu.offsetHeight || 0;
        var viewportH = window.innerHeight;
        var spaceBelow = viewportH - rect.bottom - MARGIN;
        var spaceAbove = rect.top - MARGIN;
        var top;

        if (menuHeight <= spaceBelow || spaceBelow >= spaceAbove) {
            // Below (default). Clamp so it stays fully on screen.
            top = rect.bottom + 4;
            if (top + menuHeight > viewportH - MARGIN) {
                top = Math.max(MARGIN, viewportH - MARGIN - menuHeight);
            }
        } else {
            // Not enough room below and more room above → flip up.
            top = rect.top - 4 - menuHeight;
            if (top < MARGIN) {
                top = MARGIN;
            }
        }

        menu.style.top = top + 'px';

        // If the menu is taller than the viewport, cap it and allow scrolling.
        if (menuHeight > viewportH - 2 * MARGIN) {
            menu.style.maxHeight = (viewportH - 2 * MARGIN) + 'px';
            menu.style.overflowY = 'auto';
        }
    }

    function schedule() {
        window.requestAnimationFrame(adjustOpenMenu);
    }

    // Runs after the core click/scroll/resize handlers have positioned the menu.
    document.addEventListener('click', schedule);
    window.addEventListener('scroll', schedule, false);
    window.addEventListener('resize', schedule, false);
})();
</script>
@endpush
@endonce
