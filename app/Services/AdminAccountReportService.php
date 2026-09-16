<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Enums\MoneyCurrency;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;

class AdminAccountReportService
{
    public function __construct(
        protected AccountingService $accountingService,
        protected AccountService $accountService,
        protected AccountPurchasedVolumeReconstructionService $volumeReconstruction,
        protected AccountBillingUsageSummaryService $billingUsageSummary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Account $account, ?User $viewer = null): array
    {
        $viewer ??= auth()->user();
        $data = $this->buildInternal($account);

        if ($viewer === null || $viewer->role === UserRole::Admin) {
            return array_merge($data, [
                'meta' => [
                    'show_seller_paid' => true,
                    'show_agent_margin' => true,
                    'show_admin_revenue' => true,
                    'show_refunds' => true,
                ],
            ]);
        }

        if ($viewer->role === UserRole::Seller) {
            return $this->applySellerView($data, $viewer, $account);
        }

        if ($viewer->role === UserRole::Agent) {
            return $this->applyAgentView($data, $viewer, $account);
        }

        abort(403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildInternal(Account $account): array
    {
        $volumeRepair = $this->volumeReconstruction->repairIfUnderRecorded($account, syncRemote: true);
        $account = $account->fresh() ?? $account;

        $account = $this->accountService->refreshUsageFromPanelAndLogs($account);
        $billingUsage = $this->billingUsageSummary->summarize($account);

        // For Pasarguard/Remnawave, the panel's own lifetime counter is the trustworthy
        // "total ever" figure. It is mirrored into accounts.lifetime_used_bytes on every
        // sync (cron), so read it from the DB instead of an extra live API call here.
        $panelLifetimeBytes = null;
        if ($this->accountService->readsUsageFromRemotePanel($account) && $account->lifetime_used_bytes !== null) {
            $panelLifetimeBytes = max(0, (int) $account->lifetime_used_bytes);
        }

        $account->load([
            'ownerSeller',
            'ownerAgent',
            'clientUser',
            'package',
            'packageDuration',
            'server',
        ]);

        $invoices = Invoice::query()
            ->where('account_id', $account->id)
            ->whereIn('type', [InvoiceType::NewAccount, InvoiceType::Renewal])
            ->with(['buyer', 'seller', 'agent'])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $transactions = Transaction::query()
            ->where('related_account_id', $account->id)
            ->with(['user', 'relatedInvoice'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $invoiceTransactions = $transactions->whereNotNull('related_invoice_id')->groupBy('related_invoice_id');
        $renewalSequence = 0;

        $billingEvents = $invoices->map(function (Invoice $invoice) use (&$renewalSequence, $invoiceTransactions): array {
            if ($invoice->type === InvoiceType::Renewal) {
                $renewalSequence++;
            }

            $eventTransactions = $invoiceTransactions->get($invoice->id, collect());

            return [
                'key' => 'invoice-'.$invoice->id,
                'type' => $invoice->type->value,
                'label' => $invoice->type === InvoiceType::NewAccount
                    ? __('accounts.admin_report_event_purchase')
                    : __('accounts.admin_report_event_renewal', ['number' => persian_digits((string) $renewalSequence)]),
                'date' => $this->formatReportDate(
                    $invoice->issued_at ?? $invoice->created_at ?? $eventTransactions->first()?->created_at,
                ),
                'invoice_number' => $invoice->invoice_number,
                'total' => format_money($invoice->total, $invoice->moneyCurrency()),
                'buyer' => $this->userBrief($invoice->buyer),
                'seller' => $this->userBrief($invoice->seller),
                'agent' => $this->userBrief($invoice->agent),
                'movements' => $eventTransactions
                    ->map(fn (Transaction $tx): array => $this->transactionRow($tx, $invoice))
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        $orphanTransactions = $transactions
            ->filter(fn (Transaction $tx): bool => $tx->related_invoice_id === null)
            ->map(fn (Transaction $tx): array => $this->transactionRow($tx))
            ->values()
            ->all();

        $activity = ActivityLog::query()
            ->where('entity_id', $account->id)
            ->where('entity_type', $account->getMorphClass())
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (ActivityLog $log): array => $this->activityRow($log))
            ->values()
            ->all();

        // این گزارش فقط به یک اکانت مربوط است و همه‌ی گردش‌های آن با ارز بسته‌ی همان اکانت ثبت می‌شوند؛
        // اگر بسته حذف شده باشد ارز پیش‌فرض پنل ملاک است.
        $totals = $this->summarizeTotals($transactions, $account->package?->moneyCurrency() ?? MoneyCurrency::default());

        return [
            'account' => $this->overview($account, $volumeRepair, $billingUsage, $panelLifetimeBytes),
            'usage' => $this->usageSection($account, $billingUsage),
            'billing_events' => $billingEvents,
            'orphan_transactions' => $orphanTransactions,
            'transactions' => $transactions->map(fn (Transaction $tx): array => $this->transactionRow($tx))->values()->all(),
            'activity' => $activity,
            'totals' => $totals,
        ];
    }

    /**
     * @param  array{expected_gb: ?float, current_gb: float, missing_gb: float, repaired: bool, message: ?string}  $volumeRepair
     * @return array<string, mixed>
     */
    protected function overview(Account $account, array $volumeRepair = [], array $billingUsage = [], ?int $panelLifetimeBytes = null): array
    {
        // data_used_bytes / data_limit_bytes mirror the remote panel (Pasarguard/Remnawave)
        // after refreshUsageFromPanelAndLogs(); remaining = limit − used, straight from the DB.
        $limitBytes = $account->isUnlimited() ? null : max(0, (int) $account->data_limit_bytes);
        $usedBytes = max(0, (int) $account->data_used_bytes);
        $remainingBytes = $limitBytes !== null ? max(0, $limitBytes - $usedBytes) : null;
        // Prefer the panel's authoritative lifetime counter; fall back to the local logged sum
        // (the local sum can be inflated by historical mixed-counter deltas).
        $lifetimeLoggedBytes = $panelLifetimeBytes ?? max(0, (int) ($billingUsage['lifetime_logged_bytes'] ?? 0));
        $usagePercent = $limitBytes !== null && $limitBytes > 0
            ? round(min(100, ($usedBytes / $limitBytes) * 100), 1)
            : null;
        $expectedGb = $volumeRepair['expected_gb'] ?? null;

        return [
            'id' => $account->id,
            'display_label' => $account->display_label,
            'remote_username' => $account->remote_username,
            'service_type' => $account->service_type->label(),
            'status' => $this->accountingService->statusLabel($account),
            'package' => $account->package?->name,
            'duration' => $account->packageDuration?->tier->label(),
            'server' => $account->server?->name,
            'owner_seller' => $this->userBrief($account->ownerSeller),
            'owner_agent' => $this->userBrief($account->ownerAgent),
            'client' => $this->userBrief($account->clientUser),
            'purchased_volume' => $account->purchasedVolumeLabel(),
            'created_at' => jalali_date($account->created_at, 'Y/m/d H:i'),
            'expiry_at' => $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d H:i') : __('accounts.no_expiry'),
            'expires_in' => $this->expiresInLabel($account),
            'last_sync_at' => $account->last_sync_at ? jalali_date($account->last_sync_at, 'Y/m/d H:i') : '—',
            'refunded_at' => $account->refunded_at ? jalali_date($account->refunded_at, 'Y/m/d H:i') : null,
            'usage' => [
                'used' => format_data_size($usedBytes),
                'limit' => $limitBytes !== null ? format_data_size($limitBytes) : __('accounts.unlimited_data'),
                'remaining' => $remainingBytes !== null ? format_data_size($remainingBytes) : '—',
                'percent' => $usagePercent !== null ? persian_digits(number_format($usagePercent, 1)).'٪' : '—',
                'lifetime_logged' => format_data_size($lifetimeLoggedBytes),
                'expected_total_gb' => $expectedGb !== null ? persian_digits($this->formatGbLabel($expectedGb)).' '.__('accounts.gb_unit') : null,
                'billing_ledger' => array_map(fn (array $entry): array => [
                    'label' => $entry['label'],
                    'delta_gb' => $entry['delta_gb'] > 0
                        ? persian_digits($this->formatGbLabel((float) $entry['delta_gb'])).' '.__('accounts.gb_unit')
                        : '—',
                    'total_gb' => persian_digits($this->formatGbLabel((float) $entry['gb'])).' '.__('accounts.gb_unit'),
                ], $billingUsage['entries'] ?? []),
                'volume_repaired' => (bool) ($volumeRepair['repaired'] ?? false),
                'volume_repair_message' => $volumeRepair['message'] ?? null,
            ],
            'edit_url' => route('admin.accounts.edit', $account),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function usageSection(Account $account, array $billingUsage = []): array
    {
        // For PasarGuard accounts, the daily breakdown is read straight from the panel's
        // own usage API (the authoritative "مصرف روزانه"); local logs are only a fallback.
        $panelDaily = $this->panelDailyUsage($account);

        if ($panelDaily !== null) {
            $daily = collect($panelDaily['daily'])
                ->map(fn (array $row): array => [
                    'date' => jalali_date($row['date'], 'Y/m/d'),
                    'consumed' => format_data_size((int) $row['bytes']),
                    'consumed_bytes' => (int) $row['bytes'],
                ])
                ->reverse()
                ->take(90)
                ->reverse()
                ->values()
                ->all();

            return [
                'daily' => $daily,
                'log_count' => count($daily),
                'period_days' => 90,
                'source' => 'panel',
            ];
        }

        $logs = AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->where('recorded_at', '>=', now()->subDays(90))
            ->orderBy('recorded_at')
            ->get();

        /** @var array<string, int> $dailyBytes */
        $dailyBytes = [];

        foreach ($logs as $log) {
            $dateKey = $log->recorded_at?->format('Y-m-d') ?? now()->format('Y-m-d');
            $delta = max(0, (int) $log->rx_delta_bytes) + max(0, (int) $log->tx_delta_bytes);
            $dailyBytes[$dateKey] = ($dailyBytes[$dateKey] ?? 0) + $delta;
        }

        $daily = collect($dailyBytes)
            ->map(fn (int $bytes, string $date): array => [
                'date' => jalali_date($date, 'Y/m/d'),
                'consumed' => format_data_size($bytes),
                'consumed_bytes' => $bytes,
            ])
            ->values()
            ->reverse()
            ->take(60)
            ->reverse()
            ->values()
            ->all();

        return [
            'daily' => $daily,
            'log_count' => $logs->count(),
            'period_days' => 90,
            'source' => 'local',
        ];
    }

    /**
     * Daily usage straight from PasarGuard's usage API, or null when unavailable.
     *
     * @return array{total_bytes: int, daily: array<int, array{date: string, bytes: int}>}|null
     */
    protected function panelDailyUsage(Account $account): ?array
    {
        $server = $account->server;

        if ($server === null) {
            return null;
        }

        $isPasarguard = $server->isPasarguard()
            || $account->service_type->isPasarguard()
            || $account->pasarguard_user_id !== null;

        if (! $isPasarguard) {
            return null;
        }

        try {
            $result = app(PasarguardService::class)->getUserUsageDaily($server, $account->remote_username, 90);

            return ! empty($result['daily']) ? $result : null;
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, string|int>
     */
    protected function summarizeTotals(Collection $transactions, MoneyCurrency $currency): array
    {
        $sellerPaid = '0.00';
        $agentMargin = '0.00';
        $adminRevenue = '0.00';
        $refunds = '0.00';
        $renewals = 0;
        $purchases = 0;

        foreach ($transactions as $transaction) {
            $amount = (string) $transaction->amount;

            if ($transaction->type === TransactionType::Purchase) {
                $purchases++;
            } elseif ($transaction->type === TransactionType::Renewal) {
                $renewals++;
            }

            if (in_array($transaction->type, [TransactionType::Purchase, TransactionType::Renewal], true)) {
                $sellerPaid = bcadd($sellerPaid, $amount, 2);
            } elseif ($transaction->type === TransactionType::Margin || $transaction->type === TransactionType::Commission) {
                $agentMargin = bcadd($agentMargin, $amount, 2);
            } elseif ($transaction->type === TransactionType::Revenue) {
                $adminRevenue = bcadd($adminRevenue, $amount, 2);
            } elseif ($transaction->type === TransactionType::Refund) {
                $refunds = bcadd($refunds, $amount, 2);
            }
        }

        return [
            'purchase_count' => $purchases,
            'renewal_count' => $renewals,
            'seller_paid' => format_money($sellerPaid, $currency),
            'agent_margin' => format_money($agentMargin, $currency),
            'admin_revenue' => format_money($adminRevenue, $currency),
            'refunds' => format_money($refunds, $currency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transactionRow(Transaction $transaction, ?Invoice $invoice = null): array
    {
        $direction = $this->transactionDirection($transaction->type);
        $invoice ??= $transaction->relationLoaded('relatedInvoice') ? $transaction->relatedInvoice : null;

        return [
            'id' => $transaction->id,
            'date' => $this->formatReportDate(
                $transaction->created_at ?? $invoice?->issued_at ?? $invoice?->created_at,
            ),
            'type' => $transaction->type->value,
            'type_label' => $this->transactionTypeLabel($transaction->type),
            'direction' => $direction,
            'amount' => format_money($transaction->amount, $transaction->moneyCurrency()),
            'balance_before' => format_money($transaction->balance_before, $transaction->moneyCurrency()),
            'balance_after' => format_money($transaction->balance_after, $transaction->moneyCurrency()),
            'user' => $this->userBrief($transaction->user),
            'description' => $transaction->description ?: '—',
            'invoice_id' => $transaction->related_invoice_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function activityRow(ActivityLog $log): array
    {
        $payload = is_array($log->payload) ? $log->payload : [];

        return [
            'date' => $this->formatReportDate($log->created_at),
            'action' => $log->action,
            'action_label' => $this->activityLabel($log->action, $payload),
            'user' => $this->userBrief($log->user),
            'payload' => $payload,
        ];
    }

    /**
     * @return array{id: int, username: string, full_name: ?string, role: string}|null
     */
    protected function userBrief(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'role' => $user->role->label(),
        ];
    }

    protected function expiresInLabel(Account $account): string
    {
        if ($account->expiry_at === null) {
            return __('accounts.no_expiry');
        }

        if ($account->expiry_at->isPast()) {
            return __('accounts.admin_report_expired_ago', [
                'time' => jalali_date($account->expiry_at, 'Y/m/d H:i'),
            ]);
        }

        return __('accounts.admin_report_expires_in', [
            'time' => jalali_date($account->expiry_at, 'Y/m/d H:i'),
        ]);
    }

    protected function transactionDirection(TransactionType $type): string
    {
        return match ($type) {
            TransactionType::Purchase,
            TransactionType::Renewal,
            TransactionType::ClientCost,
            TransactionType::FinancialPlanPurchase => 'debit',
            TransactionType::Margin,
            TransactionType::Commission,
            TransactionType::Revenue,
            TransactionType::Charge,
            TransactionType::ClientRetail,
            TransactionType::Refund => 'credit',
            TransactionType::Reactivation => 'debit',
            default => 'neutral',
        };
    }

    protected function formatReportDate(DateTimeInterface|string|null $date): string
    {
        $formatted = jalali_date($date, 'Y/m/d H:i');

        return $formatted !== '' ? $formatted : '—';
    }

    protected function formatGbLabel(float $gb): string
    {
        return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');
    }

    protected function transactionTypeLabel(TransactionType $type): string
    {
        return match ($type) {
            TransactionType::Purchase => __('accounts.admin_report_tx_purchase'),
            TransactionType::Renewal => __('accounts.admin_report_tx_renewal'),
            TransactionType::Margin => __('accounts.admin_report_tx_agent_margin'),
            TransactionType::Commission => __('accounts.admin_report_tx_commission'),
            TransactionType::Revenue => __('accounts.admin_report_tx_admin_revenue'),
            TransactionType::Refund => __('accounts.admin_report_tx_refund'),
            TransactionType::Reactivation => __('accounts.admin_report_tx_reactivation'),
            TransactionType::Adjustment => __('accounts.admin_report_tx_adjustment'),
            TransactionType::Charge => __('accounts.admin_report_tx_charge'),
            TransactionType::ClientRetail => __('accounts.admin_report_tx_client_retail'),
            TransactionType::ClientCost => __('accounts.admin_report_tx_client_cost'),
            TransactionType::FinancialPlanPurchase => __('accounts.admin_report_tx_plan_purchase'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function activityLabel(string $action, array $payload): string
    {
        return match ($action) {
            'account.created' => __('accounts.admin_report_log_created'),
            'account.renewed' => __('accounts.admin_report_log_renewed', [
                'mode' => $payload['renewal_mode'] ?? '—',
                'gb' => isset($payload['renewal_gb']) ? persian_digits((string) $payload['renewal_gb']).' '.__('accounts.gb_unit') : '—',
            ]),
            'account.disabled' => __('accounts.admin_report_log_disabled'),
            'account.enabled' => __('accounts.admin_report_log_enabled'),
            'account.reactivated' => __('accounts.admin_report_log_reactivated'),
            'account.deleted' => __('accounts.admin_report_log_deleted'),
            'account.price_adjusted' => __('accounts.admin_report_log_price_adjusted'),
            'account.expiry_adjusted' => __('accounts.admin_report_log_expiry_adjusted', [
                'days' => isset($payload['days_delta']) && $payload['days_delta'] !== null
                    ? persian_digits((string) $payload['days_delta'])
                    : '—',
                'previous' => ! empty($payload['previous_expiry'])
                    ? jalali_date($payload['previous_expiry'], 'Y/m/d')
                    : __('accounts.no_expiry'),
                'new' => ! empty($payload['new_expiry'])
                    ? jalali_date($payload['new_expiry'], 'Y/m/d')
                    : __('accounts.no_expiry'),
            ]),
            'account.gift_created' => __('accounts.admin_report_log_gift_created'),
            default => $action,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applySellerView(array $data, User $viewer, Account $account): array
    {
        $data['account']['edit_url'] = route('seller.accounts.edit', $account);
        $data['transactions'] = $this->filterCommissionRows($data['transactions'] ?? []);
        $data['orphan_transactions'] = $this->filterCommissionRows($data['orphan_transactions'] ?? []);
        $data['billing_events'] = $this->filterBillingEventsForStaff($data['billing_events'] ?? []);
        $data['totals'] = [
            'purchase_count' => $data['totals']['purchase_count'] ?? 0,
            'renewal_count' => $data['totals']['renewal_count'] ?? 0,
            'seller_paid' => $data['totals']['seller_paid'] ?? '—',
            'refunds' => $data['totals']['refunds'] ?? '—',
        ];
        $data['meta'] = [
            'show_seller_paid' => true,
            'show_agent_margin' => false,
            'show_admin_revenue' => false,
            'show_refunds' => true,
        ];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyAgentView(array $data, User $viewer, Account $account): array
    {
        $agentMargin = $this->sumAgentCommissionForViewer($account, $viewer);

        $data['account']['edit_url'] = route('agent.accounts.edit', $account);
        $data['transactions'] = $this->filterAdminRevenueRows($data['transactions'] ?? []);
        $data['orphan_transactions'] = $this->filterAdminRevenueRows($data['orphan_transactions'] ?? []);
        $data['billing_events'] = $this->filterBillingEventsForAgent($data['billing_events'] ?? []);
        $data['totals'] = [
            'purchase_count' => $data['totals']['purchase_count'] ?? 0,
            'renewal_count' => $data['totals']['renewal_count'] ?? 0,
            'seller_paid' => $data['totals']['seller_paid'] ?? '—',
            // پورسانت نماینده با ارز بسته‌ی همین اکانت نمایش داده می‌شود.
            'agent_margin' => format_money($agentMargin, $account->package?->moneyCurrency() ?? MoneyCurrency::default()),
            'refunds' => $data['totals']['refunds'] ?? '—',
        ];
        $data['meta'] = [
            'show_seller_paid' => true,
            'show_agent_margin' => true,
            'show_admin_revenue' => false,
            'show_refunds' => true,
            'agent_commission_label' => __('accounts.agent_report_commission_received'),
        ];

        return $data;
    }

    protected function sumAgentCommissionForViewer(Account $account, User $viewer): string
    {
        $total = '0.00';

        $transactions = Transaction::query()
            ->where('related_account_id', $account->id)
            ->where('user_id', $viewer->id)
            ->whereIn('type', [TransactionType::Margin, TransactionType::Commission])
            ->get();

        foreach ($transactions as $transaction) {
            $total = bcadd($total, (string) $transaction->amount, 2);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function filterCommissionRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ! in_array($row['type'] ?? '', ['margin', 'commission', 'revenue'], true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function filterAdminRevenueRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['type'] ?? '') !== 'revenue',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    protected function filterBillingEventsForStaff(array $events): array
    {
        return array_map(function (array $event): array {
            $event['movements'] = $this->filterCommissionRows($event['movements'] ?? []);

            return $event;
        }, $events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    protected function filterBillingEventsForAgent(array $events): array
    {
        return array_map(function (array $event): array {
            $event['movements'] = $this->filterAdminRevenueRows($event['movements'] ?? []);

            return $event;
        }, $events);
    }
}
