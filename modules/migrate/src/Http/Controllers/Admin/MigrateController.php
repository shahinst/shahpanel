<?php

namespace Modules\Migrate\Http\Controllers\Admin;

use App\Enums\ServerType;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerMigration;
use App\Models\ServerMigrationEntry;
use App\Services\SanaeiPortalService;
use App\Services\SanaeiToRemnawaveMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class MigrateController extends Controller
{
    public function index(Request $request, SanaeiToRemnawaveMigrationService $migrationService): View
    {
        $this->authorizeAdmin();

        $sanaeiServers = Server::query()
            ->where('type', ServerType::Sanaei)
            ->orderBy('name')
            ->get();

        $remnawaveServers = Server::query()
            ->where('type', ServerType::Remnawave)
            ->orderBy('name')
            ->get();

        $fromServerId = (int) $request->query('from_server_id', 0);
        $fromServer = $fromServerId > 0
            ? $sanaeiServers->firstWhere('id', $fromServerId)
            : null;

        $accounts = $fromServer !== null
            ? $migrationService->resolveAccounts($fromServer, null)
            : collect();

        $recentMigrations = Schema::hasTable('server_migrations')
            ? ServerMigration::query()
                ->with(['fromServer', 'toServer', 'user'])
                ->latest('id')
                ->limit(25)
                ->get()
            : collect();

        $migrationTablesReady = Schema::hasTable('server_migrations')
            && Schema::hasTable('server_migration_entries');

        $remnawavePackages = Package::query()
            ->active()
            ->where('service_type', ServiceType::Remnawave)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('migrate::index', compact(
            'sanaeiServers',
            'remnawaveServers',
            'remnawavePackages',
            'fromServer',
            'accounts',
            'recentMigrations',
            'migrationTablesReady',
        ));
    }

    public function show(ServerMigration $migration): View
    {
        $this->authorizeAdmin();

        $migration->load([
            'fromServer',
            'toServer',
            'user',
            'entries' => fn ($q) => $q->with('account')->orderBy('id'),
        ]);

        return view('migrate::show', compact('migration'));
    }

    public function store(Request $request, SanaeiToRemnawaveMigrationService $migrationService): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'from_server_id' => ['required', 'integer', 'exists:servers,id'],
            'to_server_id' => ['required', 'integer', 'exists:servers,id'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
            'dry_run' => ['sometimes', 'boolean'],
            'try_disable_source' => ['sometimes', 'boolean'],
            'migrate_all' => ['sometimes', 'boolean'],
            'target_package_id' => ['nullable', 'integer', 'exists:packages,id'],
        ]);

        $from = Server::query()->findOrFail((int) $validated['from_server_id']);
        $to = Server::query()->findOrFail((int) $validated['to_server_id']);
        $migrateAll = $request->boolean('migrate_all');
        $accountIds = $migrateAll
            ? null
            : array_values(array_map('intval', $validated['account_ids'] ?? []));
        $dryRun = (string) $request->input('dry_run', '0') === '1';
        $tryDisable = $request->boolean('try_disable_source');

        if (! $migrateAll && $accountIds === []) {
            return back()
                ->withInput()
                ->with('error', __('migrate.select_accounts_required'));
        }

        @set_time_limit(max(300, (int) config('vpnpanel.mikrotik.inline_max_seconds', 600)));

        try {
            $migration = $migrationService->run(
                $from,
                $to,
                $migrateAll ? null : $accountIds,
                $request->user(),
                $dryRun,
                $tryDisable,
                isset($validated['target_package_id']) ? (int) $validated['target_package_id'] : null,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $flash = $migration->failed_count > 0 ? 'warning' : 'success';

        return redirect()
            ->route('admin.migrate.show', $migration)
            ->with($flash, $migration->summary ?? __('migrate.done'));
    }

    public function qr(ServerMigrationEntry $entry, SanaeiPortalService $portalService): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAdmin();

        $url = trim((string) ($entry->subscription_url ?? ''));
        if ($url === '') {
            abort(404);
        }

        $png = base64_decode($portalService->qrBase64($url), true);
        if ($png === false) {
            abort(500);
        }

        $name = ($entry->remote_username ?? 'sub').'-qr.png';

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    protected function authorizeAdmin(): void
    {
        $this->authorize('viewAny', Server::class);
    }
}
