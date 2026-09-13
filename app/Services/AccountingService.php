<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\PackageDuration;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountingService
{
    public function __construct(
        protected AgentSellerMarkupService $markupService,
        protected UserPackagePricingService $pricingService,
    ) {}
    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginateBillingEntries(User $viewer, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->billingEntriesQuery($viewer, $filters)
            ->latest('issued_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function listBillingEntries(User $viewer, array $filters = [], int $limit = 50000): Collection
    {
        return $this->billingEntriesQuery($viewer, $filters)
            ->latest('issued_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Invoice>
     */
    public function billingEntriesQuery(User $viewer, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Invoice::query()
            ->ownedByHierarchy($viewer)
            ->whereIn('type', [InvoiceType::NewAccount, InvoiceType::Renewal])
            ->with([
                'account' => function ($q): void {
                    $q->withTrashed()->with(['package', 'ownerSeller', 'server']);
                },
            ])
            ->whereHas('account', function ($q) use ($viewer, $filters): void {
                $q->withTrashed()->ownedByHierarchy($viewer);
                $this->applyAccountFilters($q, $filters);
            });

        if (! empty($filters['date_from'])) {
            $query->whereDate('issued_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('issued_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, string>
     */
    public function eventLabelsForInvoices(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $accountIds = $invoices->pluck('account_id')->unique()->filter()->all();

        $renewalSequences = [];

        if ($accountIds !== []) {
            $renewalsByAccount = Invoice::query()
                ->whereIn('account_id', $accountIds)
                ->where('type', InvoiceType::Renewal)
                ->orderBy('issued_at')
                ->orderBy('id')
                ->get()
                ->groupBy('account_id');

            foreach ($renewalsByAccount as $renewalInvoices) {
                $sequence = 0;
                foreach ($renewalInvoices as $renewalInvoice) {
                    $sequence++;
                    $renewalSequences[$renewalInvoice->id] = $sequence;
                }
            }
        }

        $labels = [];

        foreach ($invoices as $invoice) {
            if ($invoice->type === InvoiceType::NewAccount) {
                $labels[$invoice->id] = __('accounting.event_initial_purchase');
            } else {
                $sequence = $renewalSequences[$invoice->id] ?? 1;
                $labels[$invoice->id] = __('accounting.event_renewal', [
                    'number' => persian_digits((string) $sequence),
                ]);
            }
        }

        return $labels;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, array{debited: string, credited: string, margin_percent: ?string, max_markup_percent: ?float}>
     */
    public function summarizeInvoicesForViewer(User $viewer, Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $invoiceIds = $invoices->pluck('id')->all();

        $transactions = Transaction::query()
            ->whereIn('related_invoice_id', $invoiceIds)
            ->whereIn('type', [
                TransactionType::Purchase,
                TransactionType::Renewal,
                TransactionType::Commission,
                TransactionType::Margin,
                TransactionType::Revenue,
                TransactionType::Refund,
                TransactionType::Reactivation,
            ])
            ->get()
            ->groupBy('related_invoice_id');

        $summary = [];

        foreach ($invoices as $invoice) {
            $summary[$invoice->id] = $this->summarizeAccountForViewer(
                $viewer,
                $invoice->account,
                $transactions->get($invoice->id, collect()),
                collect([$invoice]),
            );
        }

        return $summary;
    }

    /**
     * @param  array{debited: string, credited: string, margin_percent?: ?string}|null  $rowSummary
     * @return list<string>
     */
    public function exportBillingRow(
        Invoice $invoice,
        Account $account,
        string $eventLabel,
        ?array $rowSummary,
        bool $includeCredited = true,
        bool $includeMarginPercent = false,
    ): array {
        $row = $rowSummary ?? ['debited' => '0', 'credited' => '0'];

        $cells = [
            jalali_date($invoice->issued_at ?? $invoice->created_at),
            $eventLabel,
            $account->remote_username,
            $account->service_type->label(),
            $this->statusLabel($account),
            $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : '—',
            $account->package?->name ?? '—',
        ];

        if ($includeCredited) {
            $cells[] = format_toman($row['credited']);
        }

        if ($includeMarginPercent) {
            $cells[] = isset($row['margin_percent']) && $row['margin_percent'] !== null
                ? persian_digits($row['margin_percent']).'٪'
                : '—';
        }

        $cells[] = format_toman($row['debited']);

        return $cells;
    }

    /**
     * @return LengthAwarePaginator<int, Account>
     */
    public function paginateAccounts(User $viewer, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->accountsQuery($viewer, $filters)
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, Account>
     */
    public function listAccounts(User $viewer, array $filters = [], int $limit = 50000): Collection
    {
        return $this->accountsQuery($viewer, $filters)
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Builder<Account>
     */
    public function accountsQuery(User $viewer, array $filters = []): Builder
    {
        $query = Account::query()
            ->withTrashed()
            ->ownedByHierarchy($viewer)
            ->with(['package', 'ownerSeller', 'server']);

        return $this->applyAccountFilters($query, $filters);
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function applyAccountFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('remote_username', 'like', '%'.$search.'%')
                    ->orWhere('display_label', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'deleted') {
                $query->whereNotNull('deleted_at');
            } else {
                $query->whereNull('deleted_at')->where('status', $filters['status']);
            }
        }

        return $query;
    }

    /**
     * @param  array{debited: string, credited: string, margin_percent?: ?string}|null  $rowSummary
     * @return list<string>
     */
    public function exportRow(Account $account, ?array $rowSummary, bool $includeCredited = true, bool $includeMarginPercent = false): array
    {
        $row = $rowSummary ?? ['debited' => '0', 'credited' => '0'];

        $cells = [
            jalali_date($account->created_at),
            $account->remote_username,
            $account->service_type->label(),
            $this->statusLabel($account),
            $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d') : '—',
            $account->package?->name ?? '—',
        ];

        if ($includeCredited) {
            $cells[] = format_toman($row['credited']);
        }

        if ($includeMarginPercent) {
            $cells[] = isset($row['margin_percent']) && $row['margin_percent'] !== null
                ? persian_digits($row['margin_percent']).'٪'
                : '—';
        }

        $cells[] = format_toman($row['debited']);

        return $cells;
    }

    public function showsMarginPercentColumn(User $viewer): bool
    {
        return $viewer->role === UserRole::Agent && $this->markupService->isEnabled();
    }

    /**
     * Sellers never earn sales profit, so the "credited" column is hidden from
     * their accounting reports entirely.
     */
    public function showsCreditedColumn(User $viewer): bool
    {
        return $viewer->role !== UserRole::Seller;
    }

    public function statusLabel(Account $account): string
    {
        if ($account->trashed()) {
            return __('accounting.deleted');
        }

        if ($account->isRefunded()) {
            return __('accounts.status_refunded');
        }

        return match ($account->status->value) {
            'active' => __('accounts.status_active'),
            'disabled' => __('accounts.status_disabled'),
            'expired' => __('accounts.status_expired'),
            'exhausted' => __('accounts.status_exhausted'),
            default => $account->status->value,
        };
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, array{debited: string, credited: string}>
     */
    public function summarizeForViewer(User $viewer, array $accountIds, ?Collection $accounts = null): array
    {
        if ($accountIds === []) {
            return [];
        }

        $accountsById = ($accounts ?? Account::query()->withTrashed()->whereIn('id', $accountIds)->get())
            ->keyBy('id');

        $transactions = Transaction::query()
            ->whereIn('related_account_id', $accountIds)
            ->whereIn('type', [
                TransactionType::Purchase,
                TransactionType::Renewal,
                TransactionType::Commission,
                TransactionType::Margin,
                TransactionType::Revenue,
                TransactionType::Refund,
                TransactionType::Reactivation,
            ])
            ->get()
            ->groupBy('related_account_id');

        $invoices = Invoice::query()
            ->whereIn('account_id', $accountIds)
            ->whereIn('type', [InvoiceType::NewAccount, InvoiceType::Renewal])
            ->get()
            ->groupBy('account_id');

        $summary = [];

        foreach ($accountIds as $accountId) {
            $account = $accountsById->get($accountId);
            $accountTransactions = $transactions->get($accountId, collect());
            $accountInvoices = $invoices->get($accountId, collect());

            $summary[$accountId] = $this->summarizeAccountForViewer(
                $viewer,
                $account,
                $accountTransactions,
                $accountInvoices
            );
        }

        return $summary;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Invoice>  $invoices
     * @return array{debited: string, credited: string, margin_percent: ?string, max_markup_percent: ?float}
     */
    protected function summarizeAccountForViewer(
        User $viewer,
        ?Account $account,
        Collection $transactions,
        Collection $invoices
    ): array {
        $debited = '0.00';
        $credited = '0.00';
        $isSeller = $viewer->role === UserRole::Seller;

        // Users who paid for this account (buyers). Their Refund is money returned (credit-back);
        // anyone else's Refund is a commission/revenue clawback (a reversal of earnings).
        $buyerIds = $transactions
            ->filter(fn (Transaction $t): bool => in_array($t->type, [TransactionType::Purchase, TransactionType::Renewal], true))
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $purchaseSeen = false;

        foreach ($transactions as $transaction) {
            if ((int) $transaction->user_id !== (int) $viewer->id) {
                continue;
            }

            if (in_array($transaction->type, [TransactionType::Purchase, TransactionType::Renewal], true)) {
                $debited = bcadd($debited, (string) $transaction->amount, 2);
                $purchaseSeen = true;
            } elseif (in_array($transaction->type, [
                TransactionType::Commission,
                TransactionType::Margin,
                TransactionType::Revenue,
            ], true)) {
                // Sellers never earn sales profit — never surface earnings in their books.
                if ($isSeller) {
                    continue;
                }
                $credited = bcadd($credited, (string) $transaction->amount, 2);
            } elseif ($transaction->type === TransactionType::Refund) {
                if (in_array((int) $transaction->user_id, $buyerIds, true)) {
                    // Buyer's purchase charge returned to their wallet.
                    if ($isSeller) {
                        // Fold the refund back into the seller's net cost instead of
                        // showing it as profit, so the deducted total stays accurate.
                        $debited = bcsub($debited, (string) $transaction->amount, 2);
                    } else {
                        $credited = bcadd($credited, (string) $transaction->amount, 2);
                    }
                } elseif (! $isSeller) {
                    // Agent commission / admin revenue reversed — cancels the earlier credit.
                    $credited = bcsub($credited, (string) $transaction->amount, 2);
                }
            } elseif ($transaction->type === TransactionType::Reactivation) {
                if (in_array((int) $transaction->user_id, $buyerIds, true)) {
                    if ($isSeller) {
                        $debited = bcadd($debited, (string) $transaction->amount, 2);
                    } else {
                        $credited = bcsub($credited, (string) $transaction->amount, 2);
                    }
                } elseif (! $isSeller) {
                    $credited = bcadd($credited, (string) $transaction->amount, 2);
                }
            }
        }

        if ($isSeller && bccomp($debited, '0', 2) < 0) {
            $debited = '0.00';
        }

        if ($viewer->role !== UserRole::Seller) {
            $sellerCost = $this->sellerCostForAccount($account, $transactions, $invoices);

            if ($viewer->role === UserRole::Admin || $viewer->role === UserRole::Agent) {
                $debited = $sellerCost;
            }
        }

        if ($isSeller && ! $purchaseSeen && bccomp($debited, '0', 2) === 0) {
            $debited = $this->invoiceTotalForSeller($account, $invoices, (int) $viewer->id);
        }

        if (
            ($viewer->role === UserRole::Admin || $viewer->role === UserRole::Agent)
            && bccomp($debited, '0', 2) === 0
        ) {
            $debited = $this->invoiceTotalForAccount($invoices);
        }

        return [
            'debited' => $debited,
            'credited' => $isSeller ? '0.00' : $credited,
            ...$this->agentMarginMetaForAccount($viewer, $account, $credited),
        ];
    }

    /**
     * @return array{margin_percent: ?string, max_markup_percent: ?float}
     */
    protected function agentMarginMetaForAccount(User $viewer, ?Account $account, string $credited): array
    {
        $maxMarkup = $this->markupService->isEnabled() ? $this->markupService->percent() : null;

        if ($viewer->role !== UserRole::Agent || $account === null || bccomp($credited, '0', 2) <= 0) {
            return [
                'margin_percent' => null,
                'max_markup_percent' => $maxMarkup,
            ];
        }

        if ($account->package_duration_id === null || $account->owner_agent_id === null) {
            return [
                'margin_percent' => null,
                'max_markup_percent' => $maxMarkup,
            ];
        }

        $duration = PackageDuration::query()->with('package')->find($account->package_duration_id);
        $agent = User::query()->find($account->owner_agent_id);

        if ($duration === null || $agent === null) {
            return [
                'margin_percent' => null,
                'max_markup_percent' => $maxMarkup,
            ];
        }

        try {
            $package = $duration->package;
            $gb = $account->purchased_data_gb !== null ? (float) $account->purchased_data_gb : null;
            $units = $package !== null && $package->isElastic()
                ? $this->pricingService->renewalUnitsFor($package, $gb)
                : '1';
            $agentUnit = $this->pricingService->requireWholesalePrice($agent, $duration);
            $agentWholesaleTotal = number_format((float) bcmul($agentUnit, $units, 4), 2, '.', '');
            $marginPercent = $this->markupService->effectiveMarginPercent($agentWholesaleTotal, $credited);
        } catch (\Throwable) {
            $marginPercent = null;
        }

        return [
            'margin_percent' => $marginPercent,
            'max_markup_percent' => $maxMarkup,
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, Invoice>  $invoices
     */
    protected function sellerCostForAccount(?Account $account, Collection $transactions, Collection $invoices): string
    {
        $sellerId = $account?->owner_seller_id;

        if ($sellerId === null) {
            return $this->invoiceTotalForAccount($invoices);
        }

        $cost = '0.00';

        foreach ($transactions as $transaction) {
            if ((int) $transaction->user_id !== (int) $sellerId) {
                continue;
            }

            if (in_array($transaction->type, [TransactionType::Purchase, TransactionType::Renewal], true)) {
                $cost = bcadd($cost, (string) $transaction->amount, 2);
            }
        }

        if (bccomp($cost, '0', 2) === 0) {
            return $this->invoiceTotalForSeller($account, $invoices, (int) $sellerId);
        }

        return $cost;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    protected function invoiceTotalForAccount(Collection $invoices): string
    {
        $total = '0.00';

        foreach ($invoices as $invoice) {
            $total = bcadd($total, (string) $invoice->total, 2);
        }

        return $total;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    protected function invoiceTotalForSeller(?Account $account, Collection $invoices, int $sellerId): string
    {
        $total = '0.00';

        foreach ($invoices as $invoice) {
            if ((int) $invoice->seller_user_id !== $sellerId) {
                continue;
            }

            $total = bcadd($total, (string) $invoice->total, 2);
        }

        return $total;
    }

    public function totalsForViewer(User $viewer, Collection $entries, array $summary): array
    {
        $totalDebited = '0.00';
        $totalCredited = '0.00';

        foreach ($entries as $entry) {
            $row = $summary[$entry->id] ?? ['debited' => '0.00', 'credited' => '0.00'];
            $totalDebited = bcadd($totalDebited, $row['debited'], 2);
            $totalCredited = bcadd($totalCredited, $row['credited'], 2);
        }

        return [
            'debited' => $totalDebited,
            'credited' => $totalCredited,
            'role' => $viewer->role,
        ];
    }
}
