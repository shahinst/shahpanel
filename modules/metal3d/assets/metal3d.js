/*
 * Metal3D — behaviour that CSS alone cannot give:
 *  1. Chart.js defaults for a dark surface (grid, ticks, tooltips) and digits
 *     in the reader's own script (Persian for the Persian panel, Latin else).
 *  2. A gentle tilt on the account card following the pointer.
 * Nothing here touches data or forms; remove the file and the panel still works.
 */
(function () {
    'use strict';

    var body = document.body;
    if (!body || !body.classList.contains('theme-metal3d')) return;

    var persian = body.getAttribute('data-digits') === 'fa';
    var FA = '۰۱۲۳۴۵۶۷۸۹';

    function localDigits(value) {
        var s = String(value);
        return persian ? s.replace(/\d/g, function (d) { return FA[d]; }) : s;
    }

    /* 1) Chart.js: dark defaults, applied before any page script builds a chart
          (this file is deferred after app.js, and page scripts run after it). */
    function styleCharts() {
        var Chart = window.Chart;
        if (!Chart || !Chart.defaults) return;
        var text = '#c3cad8', grid = 'rgba(148,163,184,.12)';

        Chart.defaults.color = text;
        Chart.defaults.borderColor = grid;
        Chart.defaults.font = Chart.defaults.font || {};
        Chart.defaults.font.family = getComputedStyle(body).fontFamily || Chart.defaults.font.family;
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(16,21,42,.96)';
        Chart.defaults.plugins.tooltip.titleColor = '#fff';
        Chart.defaults.plugins.tooltip.bodyColor = text;
        Chart.defaults.plugins.tooltip.borderColor = 'rgba(148,163,184,.28)';
        Chart.defaults.plugins.tooltip.borderWidth = 1;
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 10;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.elements.line.tension = 0.4;
        Chart.defaults.elements.point.radius = 2;
        Chart.defaults.elements.point.hoverRadius = 5;
        Chart.defaults.elements.bar.borderRadius = 6;

        if (Chart.defaults.scales) {
            ['linear', 'category', 'logarithmic', 'time'].forEach(function (type) {
                var scale = Chart.defaults.scales[type];
                if (!scale) return;
                scale.grid = Object.assign({}, scale.grid, { color: grid, drawBorder: false });
                scale.ticks = Object.assign({}, scale.ticks, { color: text });
            });
        }

        // Tick labels and tooltip numbers in the reader's digits. Page scripts
        // that set their own callbacks keep them (we only fill the gaps).
        if (persian) {
            var linear = Chart.defaults.scales.linear;
            if (linear) {
                linear.ticks = Object.assign({}, linear.ticks, {
                    callback: function (value) { return localDigits(Number(value).toLocaleString('en-US')); }
                });
            }
            var tooltip = Chart.defaults.plugins.tooltip;
            tooltip.callbacks = Object.assign({}, tooltip.callbacks, {
                label: function (item) {
                    var label = item.dataset && item.dataset.label ? item.dataset.label + ': ' : '';
                    var value = item.formattedValue != null ? item.formattedValue : item.raw;
                    return label + localDigits(value);
                },
                title: function (items) {
                    return items.length ? localDigits(items[0].label) : '';
                }
            });
        }

        // A soft glow under every line, drawn behind the datasets.
        Chart.register({
            id: 'metal3dGlow',
            beforeDatasetsDraw: function (chart) {
                var ctx = chart.ctx;
                ctx.save();
                ctx.shadowColor = 'rgba(0,0,0,.55)';
                ctx.shadowBlur = 12;
                ctx.shadowOffsetY = 4;
            },
            afterDatasetsDraw: function (chart) { chart.ctx.restore(); }
        });
    }

    if (window.Chart) {
        styleCharts();
    } else {
        // app.js is a deferred module; it may land after us.
        var tries = 0;
        var wait = setInterval(function () {
            if (window.Chart || ++tries > 40) { clearInterval(wait); styleCharts(); }
        }, 25);
    }

    /* 2) Account card tilt */
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) {
        document.querySelectorAll('.account-card').forEach(function (card) {
            card.addEventListener('pointermove', function (e) {
                var r = card.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width - 0.5;
                card.style.setProperty('--m3d-tilt', (x * -6).toFixed(2));
            });
            card.addEventListener('pointerleave', function () { card.style.removeProperty('--m3d-tilt'); });
        });
    }
})();
