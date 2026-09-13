<?php

namespace App\Services\ServerBackup;

use App\Enums\ServerBackupStatus;
use App\Enums\ServerType;
use App\Models\Server;
use App\Models\ServerBackup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ServerBackupService
{
    /** @var list<ServerBackupCollector> */
    protected array $collectors;

    public function __construct(
        protected ServerBackupStorage $storage,
        MikrotikServerBackupCollector $mikrotik,
        PasarguardServerBackupCollector $pasarguard,
        RemnawaveServerBackupCollector $remnawave,
    ) {
        $this->collectors = [$mikrotik, $pasarguard, $remnawave];
    }

    public function supports(Server $server): bool
    {
        return $this->collectorFor($server) !== null;
    }

    public function run(Server $server, ?User $triggeredBy = null): ServerBackup
    {
        $collector = $this->collectorFor($server);

        if ($collector === null) {
            throw new InvalidArgumentException(__('server_backups.unsupported_server_type'));
        }

        $paths = $this->storage->makeBackupDirectory($server->id);

        $backup = ServerBackup::query()->create([
            'server_id' => $server->id,
            'triggered_by' => $triggeredBy?->id,
            'status' => ServerBackupStatus::Running,
            'storage_dir' => $paths['relative'],
            'started_at' => now(),
        ]);

        try {
            $result = $collector->collect($server, $paths);
            $format = (string) ($result['format'] ?? 'json_sections');
            $sections = $result['sections'] ?? [];
            $files = $result['files'] ?? [];
            $errors = $result['errors'] ?? [];

            if ($format === 'mikrotik_native') {
                if ($files === []) {
                    throw new \RuntimeException(__('server_backups.mikrotik_backup_empty'));
                }

                $manifestFiles = $this->storage->registerFiles($paths['absolute'], is_array($files) ? $files : []);
                $manifestSections = [];
            } else {
                if ($sections === [] && $errors !== []) {
                    throw new \RuntimeException(__('server_backups.all_sections_failed'));
                }

                $manifestSections = $this->storage->saveSections(
                    $paths['sections_dir'],
                    is_array($sections) ? $sections : [],
                    is_array($errors) ? $errors : [],
                );
                $manifestFiles = [];
            }

            $metadata = [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_type' => $server->type->value,
                'host' => $server->host,
                'backup_id' => $backup->id,
                'created_at' => now()->toIso8601String(),
                'read_only' => true,
                'format' => $format,
            ];

            $this->storage->writeJson($paths['absolute'].DIRECTORY_SEPARATOR.'metadata.json', $metadata);

            $manifest = [
                'version' => 1,
                'format' => $format,
                'read_only' => true,
                'server_type' => $server->type->value,
                'sections' => $manifestSections,
                'files' => $manifestFiles,
                'section_errors' => $errors,
                'section_count' => count($manifestSections),
                'file_count' => count($manifestFiles),
            ];

            $this->storage->writeJson($paths['absolute'].DIRECTORY_SEPARATOR.'manifest.json', $manifest);

            $status = $format === 'mikrotik_native'
                ? ($manifestFiles !== [] ? ServerBackupStatus::Completed : ServerBackupStatus::Failed)
                : (($errors === [] || $sections !== [])
                    ? ServerBackupStatus::Completed
                    : ServerBackupStatus::Failed);

            $backup->update([
                'status' => $status,
                'manifest' => $manifest,
                'error' => $errors !== [] ? $this->formatSectionErrors($errors) : null,
                'completed_at' => now(),
            ]);

            Log::info('server_backup.completed', [
                'backup_id' => $backup->id,
                'server_id' => $server->id,
                'format' => $format,
                'sections' => count($manifestSections),
                'files' => count($manifestFiles),
                'errors' => count($errors),
            ]);

            return $backup->fresh(['server', 'triggeredBy']);
        } catch (Throwable $exception) {
            $backup->update([
                'status' => ServerBackupStatus::Failed,
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            Log::warning('server_backup.failed', [
                'backup_id' => $backup->id,
                'server_id' => $server->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return list<Server>
     */
    public function backupableServers()
    {
        return Server::query()
            ->where('is_active', true)
            ->whereIn('type', [
                ServerType::Mikrotik,
                ServerType::Pasarguard,
                ServerType::Remnawave,
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $server): bool => $this->supports($server))
            ->values();
    }

    protected function collectorFor(Server $server): ?ServerBackupCollector
    {
        foreach ($this->collectors as $collector) {
            if ($collector->supports($server)) {
                return $collector;
            }
        }

        return null;
    }

    /**
     * @param  list<array{section: string, message: string}>  $errors
     */
    protected function formatSectionErrors(array $errors): string
    {
        return collect($errors)
            ->map(fn (array $row): string => ($row['section'] ?? '?').': '.($row['message'] ?? ''))
            ->implode("\n");
    }
}
