<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerBackup;
use App\Services\ServerBackup\ServerBackupService;
use App\Services\ServerBackup\ServerBackupStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class ServerBackupController extends Controller
{
    public function index(ServerBackupService $backupService): View
    {
        if (! Schema::hasTable('server_backups')) {
            return view('admin.settings.server-backups.index', [
                'servers' => collect(),
                'recentBackups' => collect(),
                'migrationRequired' => true,
            ]);
        }

        try {
            $servers = $backupService->backupableServers();

            $recentBackups = ServerBackup::query()
                ->with(['server', 'triggeredBy'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->groupBy(static fn (ServerBackup $backup): string => (string) $backup->server_id);

            return view('admin.settings.server-backups.index', [
                'servers' => $servers,
                'recentBackups' => $recentBackups,
                'migrationRequired' => false,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return view('admin.settings.server-backups.index', [
                'servers' => collect(),
                'recentBackups' => collect(),
                'migrationRequired' => false,
                'loadError' => $exception->getMessage(),
            ]);
        }
    }

    public function store(Request $request, ServerBackupService $backupService): RedirectResponse
    {
        if (! Schema::hasTable('server_backups')) {
            return redirect()
                ->route('admin.settings.server-backups.index')
                ->with('error', __('server_backups.migration_required'));
        }

        $validated = $request->validate([
            'server_id' => ['required', 'exists:servers,id'],
        ]);

        $server = Server::query()->findOrFail((int) $validated['server_id']);

        if (! $backupService->supports($server)) {
            return redirect()
                ->route('admin.settings.server-backups.index')
                ->with('error', __('server_backups.unsupported_server_type'));
        }

        @set_time_limit(max(120, (int) config('shahpanel.server_backup.timeout_seconds', 300)));

        try {
            $backup = $backupService->run($server, $request->user());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.settings.server-backups.index')
                ->with('error', __('server_backups.run_failed', ['message' => $exception->getMessage()]));
        }

        return redirect()
            ->route('admin.settings.server-backups.show', $backup)
            ->with('success', __('server_backups.run_success'));
    }

    public function show(ServerBackup $serverBackup, ServerBackupStorage $storage): View
    {
        if (! Schema::hasTable('server_backups')) {
            abort(503, __('server_backups.migration_required'));
        }

        try {
            $serverBackup->load(['server', 'triggeredBy']);
            $sections = $serverBackup->sectionNames();
            $requestedSection = request()->query('section');
            $activeSection = is_string($requestedSection) && in_array($requestedSection, $sections, true)
                ? $requestedSection
                : ($sections[0] ?? null);

            $sectionPayload = $activeSection !== null
                ? $storage->readSection($serverBackup, $activeSection)
                : null;

            $metadata = $storage->readMetadata($serverBackup);

            $manifest = $serverBackup->manifest ?? [];
            $backupFiles = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

            return view('admin.settings.server-backups.show', [
                'backup' => $serverBackup,
                'metadata' => $metadata,
                'sections' => $sections,
                'activeSection' => $activeSection,
                'sectionPayload' => $sectionPayload,
                'manifest' => $manifest,
                'backupFiles' => $backupFiles,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            abort(500, __('server_backups.load_failed', ['message' => $exception->getMessage()]));
        }
    }
}
