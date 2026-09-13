<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceLog;
use App\Models\Setting;
use App\Services\DatabaseBackupService;
use App\Services\DatabaseMaintenanceService;
use App\Support\PanelMaintenanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MaintenanceController extends Controller
{
    public function index(
        DatabaseMaintenanceService $maintenanceService,
        DatabaseBackupService $backupService,
    ): View {
        $missingTables = $maintenanceService->missingTables();
        $logs = Schema::hasTable('maintenance_logs')
            ? MaintenanceLog::query()->latest('ran_at')->limit(20)->get()
            : collect();

        return view('admin.maintenance.index', [
            'missingTables' => $missingTables,
            'logs' => $logs,
            'maintenanceEnabled' => PanelMaintenanceSettings::isEnabled(),
            'maintenanceMessage' => PanelMaintenanceSettings::message(),
            'backups' => $backupService->listBackups(),
            'lastBackupAt' => Setting::getValue('last_database_backup_at'),
            'databaseDriver' => config('database.connections.'.config('database.default').'.driver'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'maintenance_enabled' => ['nullable', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:1000'],
        ]);

        PanelMaintenanceSettings::setEnabled($request->boolean('maintenance_enabled'));
        PanelMaintenanceSettings::setMessage($validated['maintenance_message'] ?? null);

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', __('maintenance.settings_saved'));
    }

    public function migrate(DatabaseMaintenanceService $maintenanceService): RedirectResponse
    {
        $result = $maintenanceService->runMigrations(auth()->user());

        return redirect()
            ->route('admin.maintenance.index')
            ->with($result['status'] === 'success' ? 'success' : 'warning', __('maintenance.migrate_done'))
            ->with('operation_log', $result['lines']);
    }

    public function verifySchema(DatabaseMaintenanceService $maintenanceService): RedirectResponse
    {
        $result = $maintenanceService->verifySchema(auth()->user());

        return redirect()
            ->route('admin.maintenance.index')
            ->with($result['status'] === 'success' ? 'success' : 'warning', __('maintenance.verify_done'))
            ->with('operation_log', $result['lines']);
    }

    public function storeDatabaseBackup(DatabaseBackupService $backupService): RedirectResponse
    {
        try {
            $result = $backupService->createBackup();
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', __('maintenance.backup_failed'));
        }

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', __('maintenance.backup_created', ['file' => $result['filename']]));
    }

    public function restoreDatabaseBackup(Request $request, DatabaseBackupService $backupService): RedirectResponse
    {
        $request->validate([
            'confirm_restore' => ['accepted'],
            'backup_file' => ['nullable', 'string', 'max:255'],
            'upload_backup' => ['nullable', 'file', 'max:512000'],
        ]);

        try {
            if ($request->hasFile('upload_backup')) {
                $backupService->restoreFromUploadedFile($request->file('upload_backup'));
            } elseif ($request->filled('backup_file')) {
                $backupService->restoreFromFilename((string) $request->string('backup_file'));
            } else {
                return redirect()
                    ->route('admin.maintenance.index')
                    ->with('error', __('maintenance.restore_file_required'));
            }
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', __('maintenance.restore_failed'));
        }

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', __('maintenance.restore_done'));
    }

    public function downloadDatabaseBackup(Request $request, DatabaseBackupService $backupService): BinaryFileResponse|RedirectResponse
    {
        $filename = basename((string) $request->query('file', ''));

        if ($filename === '') {
            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', __('maintenance.backup_not_found'));
        }

        try {
            $path = $backupService->resolveBackupPath($filename);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', $exception->getMessage());
        }

        return response()->download($path, basename($path));
    }

    public function destroyDatabaseBackup(string $filename, DatabaseBackupService $backupService): RedirectResponse
    {
        try {
            $backupService->deleteBackup($filename);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.maintenance.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.maintenance.index')
            ->with('success', __('maintenance.backup_deleted'));
    }
}
