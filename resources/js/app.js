import './bootstrap';
import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;
window.Chart = Chart;

Alpine.start();

/* ---------------------------------------------------------------------
   Lightweight UI behaviors (replaces Bootstrap JS + metismenu)
   - account action menus (⋮ in accounts table)
   - dropdowns:        [data-bs-toggle="dropdown"]
   - alert dismiss:    [data-bs-dismiss="alert"] / .alert .btn-close
   - sidebar drawer:   [data-vp-sidebar-toggle], overlay, Esc
   - collapsible nav:  .vp-nav__group toggles its .vp-nav__sub
--------------------------------------------------------------------- */
(function () {
    'use strict';

    const MOBILE = window.matchMedia('(max-width: 991.98px)');
    const isMobile = () => MOBILE.matches;

    /* ---- Account table action menus (vanilla — no Alpine) ---- */
    let accountMenuState = null;

    function clearAccountMenuStyles(menu) {
        menu.style.position = '';
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
        menu.style.zIndex = '';
        menu.style.minWidth = '';
    }

    function positionAccountMenu(toggle, menu) {
        const rect = toggle.getBoundingClientRect();
        const menuWidth = Math.max(menu.offsetWidth || 0, 220);
        const isRtl = document.documentElement.getAttribute('dir') === 'rtl';

        menu.style.position = 'fixed';
        menu.style.top = `${rect.bottom + 4}px`;
        menu.style.zIndex = '10050';
        menu.style.minWidth = `${menuWidth}px`;

        if (isRtl) {
            let left = rect.left;
            if (left + menuWidth > window.innerWidth - 8) {
                left = window.innerWidth - menuWidth - 8;
            }
            menu.style.left = `${Math.max(8, left)}px`;
            menu.style.right = 'auto';
        } else {
            let left = rect.right - menuWidth;
            if (left < 8) {
                left = 8;
            }
            if (left + menuWidth > window.innerWidth - 8) {
                left = window.innerWidth - menuWidth - 8;
            }
            menu.style.left = `${left}px`;
            menu.style.right = 'auto';
        }
    }

    function closeAccountActionMenu() {
        if (!accountMenuState) {
            return;
        }

        const { menu, toggle, placeholder } = accountMenuState;
        menu.classList.remove('show');
        clearAccountMenuStyles(menu);

        if (placeholder?.parentNode) {
            placeholder.parentNode.insertBefore(menu, placeholder);
            placeholder.remove();
        }

        toggle?.setAttribute('aria-expanded', 'false');
        accountMenuState = null;
    }

    function openAccountActionMenu(group, toggle, menu) {
        closeAccountActionMenu();

        const placeholder = document.createComment('account-menu-anchor');
        const parent = menu.parentNode;
        if (!parent) {
            return;
        }

        parent.insertBefore(placeholder, menu);
        document.body.appendChild(menu);
        menu.classList.add('show');
        toggle.setAttribute('aria-expanded', 'true');
        positionAccountMenu(toggle, menu);

        accountMenuState = { group, menu, toggle, placeholder };
    }

    document.addEventListener('click', function (e) {
        const toggle = e.target.closest('[data-account-actions-toggle]');
        if (toggle) {
            e.preventDefault();
            e.stopPropagation();

            const group = toggle.closest('[data-account-actions]');
            const menu = group?.querySelector('[data-account-actions-menu]');
            if (!group || !menu) {
                return;
            }

            if (accountMenuState?.group === group) {
                closeAccountActionMenu();
            } else {
                openAccountActionMenu(group, toggle, menu);
            }

            return;
        }

        if (accountMenuState?.menu?.contains(e.target)) {
            return;
        }

        closeAccountActionMenu();
    });

    window.addEventListener('scroll', function () {
        if (!accountMenuState) {
            return;
        }
        positionAccountMenu(accountMenuState.toggle, accountMenuState.menu);
    }, true);

    window.addEventListener('resize', function () {
        if (!accountMenuState) {
            return;
        }
        positionAccountMenu(accountMenuState.toggle, accountMenuState.menu);
    });

    /* ---- Sidebar drawer ---- */
    const body = document.body;
    function openSidebar() { body.classList.add('vp-sidebar-open'); }
    function closeSidebar() { body.classList.remove('vp-sidebar-open'); }
    function toggleSidebar() { body.classList.toggle('vp-sidebar-open'); }

    /* ---- Dropdowns ---- */
    function closeAllDropdowns(except) {
        document.querySelectorAll('.dropdown-menu.show').forEach((menu) => {
            if (except && menu === except) {
                return;
            }
            if (menu.hasAttribute('data-account-actions-menu')) {
                return;
            }
            menu.classList.remove('show');
        });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-account-actions-toggle], [data-account-actions-menu]')) {
            return;
        }

        const toggle = e.target.closest('[data-bs-toggle="dropdown"]');
        if (toggle) {
            e.preventDefault();
            const wrap = toggle.closest('.dropdown, .dropup');
            const menu = wrap ? wrap.querySelector('.dropdown-menu') : null;
            if (menu) {
                const willShow = !menu.classList.contains('show');
                closeAllDropdowns(menu);
                menu.classList.toggle('show', willShow);
            }
            return;
        }

        // Sidebar toggle button
        if (e.target.closest('[data-vp-sidebar-toggle]')) {
            e.preventDefault();
            toggleSidebar();
            return;
        }

        // Overlay closes sidebar
        if (e.target.closest('#vp-sidebar-overlay')) {
            closeSidebar();
            return;
        }

        // Collapsible nav group
        const groupToggle = e.target.closest('.vp-nav__group > .vp-nav__link');
        if (groupToggle) {
            e.preventDefault();
            const group = groupToggle.parentElement;
            const expanded = group.getAttribute('aria-expanded') === 'true';
            group.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            const sub = group.querySelector('.vp-nav__sub');
            if (sub) sub.style.display = expanded ? 'none' : 'flex';
            return;
        }

        // Collapse toggle
        const collapseToggle = e.target.closest('[data-bs-toggle="collapse"]');
        if (collapseToggle) {
            e.preventDefault();
            const sel = collapseToggle.getAttribute('data-bs-target') || collapseToggle.getAttribute('href');
            const target = sel ? document.querySelector(sel) : null;
            if (target) {
                target.classList.toggle('show');
                collapseToggle.setAttribute('aria-expanded', target.classList.contains('show') ? 'true' : 'false');
            }
            return;
        }

        // Alert dismiss
        const dismiss = e.target.closest('[data-bs-dismiss="alert"], .alert .btn-close');
        if (dismiss) {
            const alert = dismiss.closest('.alert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 200);
            }
            return;
        }

        // Close sidebar after tapping a real nav link on mobile
        const navLink = e.target.closest('.vp-nav__link');
        if (navLink && isMobile() && !navLink.closest('.vp-nav__group')) {
            closeSidebar();
        }

        // Click outside any dropdown closes them
        if (!e.target.closest('.dropdown, .dropup')) {
            closeAllDropdowns();
            closeAccountActionMenu();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllDropdowns();
            closeAccountActionMenu();
            closeSidebar();
        }
    });


    MOBILE.addEventListener('change', function () {
        if (!isMobile()) closeSidebar();
    });
})();
