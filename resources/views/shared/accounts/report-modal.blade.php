@php
    $reportPrefix = $reportPrefix ?? 'admin';
    $reportLabels = [
        'overview' => __('accounts.admin_report_section_overview'),
        'usage' => __('accounts.admin_report_section_usage'),
        'billing' => __('accounts.admin_report_section_billing'),
        'transactions' => __('accounts.admin_report_section_transactions'),
        'activity' => __('accounts.admin_report_section_activity'),
        'totals' => __('accounts.admin_report_section_totals'),
        'daily_date' => __('accounts.admin_report_daily_date'),
        'daily_consumed' => __('accounts.admin_report_daily_consumed'),
        'no_usage' => __('accounts.admin_report_no_usage'),
        'no_activity' => __('accounts.admin_report_no_activity'),
        'load_failed' => __('accounts.admin_report_load_failed'),
        'transaction_at' => __('accounting.transaction_at'),
        'user' => __('accounts.admin_report_user'),
        'type' => __('accounts.admin_report_type'),
        'amount' => __('accounts.admin_report_amount'),
        'service_username' => __('accounts.service_username'),
        'status' => __('app.status'),
        'package' => __('accounts.package'),
        'duration' => __('packages.duration'),
        'server' => __('clients.server'),
        'owner' => __('accounts.owner'),
        'agents' => __('menu.agents'),
        'clients' => __('menu.clients'),
        'purchased_volume' => __('accounts.purchased_volume'),
        'created_at' => __('accounts.created_at'),
        'expires_at' => __('accounts.expires_at'),
        'expires_label' => __('accounts.admin_report_expires_label'),
        'last_sync' => __('accounts.admin_report_last_sync'),
        'data_consumed' => __('accounts.data_consumed'),
        'data_limit' => __('accounts.data_limit'),
        'remaining' => __('accounts.remaining'),
        'usage_percent' => __('accounts.usage_percent'),
        'total_seller_paid' => __('accounts.admin_report_total_seller_paid'),
        'total_agent_margin' => __('accounts.admin_report_total_agent_margin'),
        'total_admin_revenue' => __('accounts.admin_report_total_admin_revenue'),
        'total_refunds' => __('accounts.admin_report_total_refunds'),
        'renewal_count' => __('accounts.admin_report_renewal_count'),
        'agent_commission_received' => __('accounts.agent_report_commission_received'),
        'unlimited' => __('accounts.unlimited_data'),
        'card_service' => __('accounts.admin_report_card_service'),
        'card_people' => __('accounts.admin_report_card_people'),
        'card_time' => __('accounts.admin_report_card_time'),
        'card_usage' => __('accounts.admin_report_card_usage'),
        'lifetime_logged' => __('accounts.admin_report_lifetime_logged'),
        'lifetime_logged_hint' => __('accounts.admin_report_lifetime_logged_hint'),
    ];
    $reportBaseUrl = $reportBaseUrl ?? url('/'.$reportPrefix.'/accounts');
@endphp

<div class="modal admin-account-report-modal" id="admin-account-report-modal" tabindex="-1" role="dialog" aria-labelledby="admin-account-report-title" hidden>
    <div class="modal-dialog admin-account-report-modal__dialog" role="document">
        <div class="modal-content admin-account-report-modal__content">
            <div class="modal-header">
                <h5 class="modal-title" id="admin-account-report-title">{{ __('accounts.admin_report_title') }}</h5>
                <button type="button" class="btn-close admin-account-report-modal__close" aria-label="{{ __('app.cancel') }}"></button>
            </div>
            <div class="modal-body" id="admin-account-report-body">
                <div class="admin-account-report-loading text-center py-5" id="admin-account-report-loading">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-2 mb-0">{{ __('app.loading') }}</p>
                </div>
                <div id="admin-account-report-content" hidden></div>
                <div class="alert alert-danger mb-0" id="admin-account-report-error" hidden></div>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-outline-primary" id="admin-account-report-edit" target="_blank" hidden>
                    <i class="bx bx-edit"></i> {{ __('app.edit') }}
                </a>
                <button type="button" class="btn btn-secondary admin-account-report-modal__close">{{ __('app.cancel') }}</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modal = document.getElementById('admin-account-report-modal');
    if (!modal) return;

    const labels = @json($reportLabels);
    const reportBaseUrl = @json($reportBaseUrl);

    const loadingEl = document.getElementById('admin-account-report-loading');
    const contentEl = document.getElementById('admin-account-report-content');
    const errorEl = document.getElementById('admin-account-report-error');
    const editLink = document.getElementById('admin-account-report-edit');
    const titleEl = document.getElementById('admin-account-report-title');
    let requestId = 0;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function userLine(user) {
        if (!user) return '—';
        const name = user.full_name || user.username;
        return escapeHtml(name + ' (' + user.role + ')');
    }

    function reportUrlFor(accountId) {
        return reportBaseUrl.replace(/\/$/, '') + '/' + accountId + '/report';
    }

    function openModal() {
        modal.hidden = false;
        modal.classList.add('show');
        document.body.classList.add('admin-account-report-modal-open');
    }

    function closeModal() {
        modal.hidden = true;
        modal.classList.remove('show');
        document.body.classList.remove('admin-account-report-modal-open');
    }

    function showLoading() {
        loadingEl.hidden = false;
        contentEl.hidden = true;
        errorEl.hidden = true;
        contentEl.innerHTML = '';
    }

    function showError(message) {
        loadingEl.hidden = true;
        contentEl.hidden = true;
        errorEl.hidden = false;
        errorEl.textContent = message;
    }

    function renderSection(title, innerHtml) {
        return '<section class="admin-account-report-section"><h6 class="admin-account-report-section__title">' + escapeHtml(title) + '</h6>' + innerHtml + '</section>';
    }

    function userBlock(user) {
        if (!user) {
            return '<span class="text-muted">—</span>';
        }

        return '<div class="admin-report-user">'
            + '<strong class="admin-report-user__name">' + escapeHtml(user.full_name || user.username) + '</strong>'
            + '<span class="admin-report-user__username" dir="ltr">' + escapeHtml(user.username) + '</span>'
            + '<span class="admin-report-user__role">' + escapeHtml(user.role) + '</span>'
            + '</div>';
    }

    function renderMiniRow(label, valueHtml) {
        return '<li class="admin-report-mini-row">'
            + '<span class="admin-report-mini-row__label">' + escapeHtml(label) + '</span>'
            + '<span class="admin-report-mini-row__value">' + valueHtml + '</span>'
            + '</li>';
    }

    function renderOverviewCard(title, rowsHtml) {
        return '<div class="admin-report-card">'
            + '<h6 class="admin-report-card__title">' + escapeHtml(title) + '</h6>'
            + '<ul class="admin-report-mini-list">' + rowsHtml + '</ul>'
            + '</div>';
    }

    function renderOverview(account) {
        const usage = account.usage || {};
        const percentRaw = String(usage.percent || '0');
        const percentNum = Math.min(100, Math.max(0, parseFloat(percentRaw.replace(/[^\d.]/g, '')) || 0));
        const showUsage = usage.limit && usage.limit !== '—' && usage.limit !== labels.unlimited;

        let html = '<div class="admin-report-overview">';
        html += '<div class="admin-report-overview__hero">';
        html += '<div class="admin-report-overview__identity">';
        html += '<div class="admin-report-overview__name">' + escapeHtml(account.display_label || account.remote_username || '—') + '</div>';
        if (account.display_label) {
            html += '<code class="admin-report-overview__username" dir="ltr">' + escapeHtml(account.remote_username) + '</code>';
        }
        html += '</div>';
        html += '<span class="admin-report-status">' + escapeHtml(account.status || '—') + '</span>';
        html += '</div>';

        html += '<div class="admin-report-cards-grid">';
        html += renderOverviewCard(labels.card_service,
            renderMiniRow(labels.package, escapeHtml(account.package || '—'))
            + renderMiniRow(labels.duration, escapeHtml(account.duration || '—'))
            + renderMiniRow(labels.server, escapeHtml(account.server || '—'))
            + renderMiniRow(labels.purchased_volume, '<strong>' + escapeHtml(account.purchased_volume || '—') + '</strong>')
        );
        html += renderOverviewCard(labels.card_people,
            renderMiniRow(labels.owner, userBlock(account.owner_seller))
            + renderMiniRow(labels.agents, userBlock(account.owner_agent))
            + renderMiniRow(labels.clients, userBlock(account.client))
        );
        html += renderOverviewCard(labels.card_time,
            renderMiniRow(labels.created_at, escapeHtml(account.created_at || '—'))
            + renderMiniRow(labels.expires_at, escapeHtml(account.expiry_at || '—'))
            + renderMiniRow(labels.expires_label, escapeHtml(account.expires_in || '—'))
            + renderMiniRow(labels.last_sync, escapeHtml(account.last_sync_at || '—'))
        );
        html += '</div>';

        if (showUsage) {
            if (usage.volume_repair_message) {
                html += '<div class="admin-report-usage-repair alert alert-success py-2 px-3 small mb-2">' + escapeHtml(usage.volume_repair_message) + '</div>';
            }
            html += '<div class="admin-report-usage-panel">';
            html += '<div class="admin-report-usage-panel__head">';
            html += '<span>' + escapeHtml(labels.card_usage) + '</span>';
            html += '<span class="admin-report-usage-panel__percent">' + escapeHtml(usage.percent || '—') + '</span>';
            html += '</div>';
            html += '<div class="admin-report-usage-bar"><div class="admin-report-usage-bar__fill" style="width:' + percentNum + '%"></div></div>';
            html += '<div class="admin-report-usage-stats">';
            html += '<div class="admin-report-usage-stat"><span>' + escapeHtml(labels.data_consumed) + '</span><strong>' + escapeHtml(usage.used || '—') + '</strong></div>';
            html += '<div class="admin-report-usage-stat"><span>' + escapeHtml(labels.data_limit) + '</span><strong>' + escapeHtml(usage.limit || '—') + '</strong></div>';
            html += '<div class="admin-report-usage-stat"><span>' + escapeHtml(labels.remaining) + '</span><strong>' + escapeHtml(usage.remaining || '—') + '</strong></div>';
            if (usage.lifetime_logged && usage.lifetime_logged !== usage.used) {
                html += '<div class="admin-report-usage-stat admin-report-usage-stat--wide"><span>' + escapeHtml(labels.lifetime_logged) + '</span><strong>' + escapeHtml(usage.lifetime_logged) + '</strong></div>';
                html += '<div class="admin-report-usage-hint text-muted small">' + escapeHtml(labels.lifetime_logged_hint) + '</div>';
            }
            html += '</div></div>';
        }

        html += '</div>';
        return html;
    }

    function renderKpi(label, value, tone, icon) {
        return '<div class="admin-report-kpi admin-report-kpi--' + tone + '">'
            + '<div class="admin-report-kpi__icon"><i class="bx ' + icon + '"></i></div>'
            + '<div class="admin-report-kpi__body">'
            + '<span class="admin-report-kpi__label">' + escapeHtml(label) + '</span>'
            + '<strong class="admin-report-kpi__value">' + escapeHtml(value || '—') + '</strong>'
            + '</div></div>';
    }

    function renderTotalsKpis(totals, meta) {
        meta = meta || {};
        let html = '<div class="admin-report-kpi-grid">';

        if (meta.show_seller_paid !== false) {
            html += renderKpi(labels.total_seller_paid, totals.seller_paid, 'debit', 'bx-wallet');
        }
        if (meta.show_agent_margin) {
            html += renderKpi(
                meta.agent_commission_label || labels.agent_commission_received || labels.total_agent_margin,
                totals.agent_margin,
                'success',
                'bx-trending-up'
            );
        }
        if (meta.show_admin_revenue) {
            html += renderKpi(labels.total_admin_revenue, totals.admin_revenue, 'primary', 'bx-coin-stack');
        }
        if (meta.show_refunds !== false) {
            html += renderKpi(labels.total_refunds, totals.refunds, 'warning', 'bx-undo');
        }
        html += renderKpi(labels.renewal_count, String(totals.renewal_count ?? '0'), 'slate', 'bx-revision');
        html += '</div>';

        return html;
    }

    function renderMovements(movements) {
        if (!movements || !movements.length) return '<p class="text-muted small mb-0">—</p>';
        return '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 admin-account-report-table"><thead><tr><th>' + escapeHtml(labels.transaction_at) + '</th><th>' + escapeHtml(labels.user) + '</th><th>' + escapeHtml(labels.type) + '</th><th>' + escapeHtml(labels.amount) + '</th></tr></thead><tbody>'
            + movements.map(function (row) {
                const tone = row.direction === 'debit' ? 'text-danger' : (row.direction === 'credit' ? 'text-success' : '');
                return '<tr><td>' + escapeHtml(row.date || '—') + '</td><td>' + userLine(row.user) + '</td><td>' + escapeHtml(row.type_label) + '</td><td class="' + tone + '">' + escapeHtml(row.amount) + '</td></tr>';
            }).join('')
            + '</tbody></table></div>';
    }

    function renderReport(data) {
        const account = data.account || {};
        const meta = data.meta || {};
        titleEl.textContent = account.display_label
            ? account.display_label + ' — ' + account.remote_username
            : (account.remote_username || labels.overview);

        if (account.edit_url) {
            editLink.href = account.edit_url;
            editLink.hidden = false;
        } else {
            editLink.hidden = true;
        }

        let html = '';

        html += renderSection(labels.overview, renderOverview(account));

        const daily = (data.usage && data.usage.daily) || [];
        const usageHtml = daily.length
            ? '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 admin-account-report-table"><thead><tr><th>' + escapeHtml(labels.daily_date) + '</th><th>' + escapeHtml(labels.daily_consumed) + '</th></tr></thead><tbody>'
                + daily.map(function (row) {
                    return '<tr><td>' + escapeHtml(row.date) + '</td><td>' + escapeHtml(row.consumed) + '</td></tr>';
                }).join('')
                + '</tbody></table></div>'
            : '<p class="text-muted small mb-0">' + escapeHtml(labels.no_usage) + '</p>';
        html += renderSection(labels.usage, usageHtml);

        const totals = data.totals || {};
        html += renderSection(labels.totals, renderTotalsKpis(totals, meta));

        const billing = (data.billing_events || []).map(function (event) {
            return '<div class="admin-account-report-event"><div class="admin-account-report-event__head"><strong>' + escapeHtml(event.label) + '</strong><time class="admin-account-report-event__date" datetime="">' + escapeHtml(event.date || '—') + '</time></div>'
                + '<div class="small text-muted mb-2">' + escapeHtml(event.invoice_number || '') + ' — ' + escapeHtml(event.total || '') + '</div>'
                + renderMovements(event.movements)
                + '</div>';
        }).join('');
        html += renderSection(labels.billing, billing || '<p class="text-muted small mb-0">—</p>');

        html += renderSection(labels.transactions, renderMovements(data.transactions || []));

        const activity = data.activity || [];
        const activityHtml = activity.length
            ? '<div class="admin-account-report-timeline">' + activity.map(function (row) {
                return '<div class="admin-account-report-timeline__item"><div class="admin-account-report-timeline__date">' + escapeHtml(row.date) + '</div><div class="admin-account-report-timeline__text">' + escapeHtml(row.action_label) + '</div><div class="admin-account-report-timeline__meta text-muted small">' + userLine(row.user) + '</div></div>';
            }).join('') + '</div>'
            : '<p class="text-muted small mb-0">' + escapeHtml(labels.no_activity) + '</p>';
        html += renderSection(labels.activity, activityHtml);

        contentEl.innerHTML = html;
        loadingEl.hidden = true;
        errorEl.hidden = true;
        contentEl.hidden = false;
    }

    function loadReport(accountId) {
        showLoading();
        openModal();
        const currentRequest = ++requestId;

        fetch(reportUrlFor(accountId), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (currentRequest !== requestId) return;
                if (!result.ok) {
                    showError(result.payload.message || result.payload.error || labels.load_failed);
                    return;
                }
                renderReport(result.payload);
            })
            .catch(function () {
                if (currentRequest !== requestId) return;
                showError(labels.load_failed);
            });
    }

    function bindTrigger(trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            const accountId = trigger.getAttribute('data-account-report')
                || trigger.getAttribute('data-admin-account-report');
            if (accountId) loadReport(accountId);
        });
    }

    document.querySelectorAll('[data-account-report], [data-admin-account-report]').forEach(bindTrigger);

    modal.querySelectorAll('.admin-account-report-modal__close').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            closeModal();
        });
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) closeModal();
    });
})();
</script>
@endpush
