<?php

namespace App\Services\ServerBackup;

use App\Exceptions\RemoteProvisionException;
use App\Models\Server;
use App\Services\MikrotikService;
use App\Support\PhpSecLibLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates a native MikroTik /system backup (.backup) via API and downloads it with pure-PHP SFTP.
 *
 * Does not use proc_open, shell_exec, or external scp/sshpass binaries.
 */
class MikrotikNativeBackupService
{
    public function __construct(
        protected MikrotikService $mikrotik,
    ) {}

    /**
     * @return array{filename: string, bytes: int, remote_name: string}
     */
    public function createAndDownload(Server $server, string $localAbsolutePath): array
    {
        $remoteBase = $this->remoteBackupBaseName($server);
        $remoteFilename = $remoteBase.'.backup';

        try {
            $this->mikrotik->sendCommandWithSocketTimeout($server, '/system/backup/save', [
                'name' => $remoteBase,
                'dont-encrypt' => 'yes',
            ], (int) config('shahpanel.mikrotik.backup_save_timeout', 180));

            $this->waitForRouterFile($server, $remoteFilename);

            File::ensureDirectoryExists(dirname($localAbsolutePath), 0750, true);
            $this->downloadViaSftp($server, $remoteFilename, $localAbsolutePath);

            if (! is_file($localAbsolutePath) || filesize($localAbsolutePath) === 0) {
                throw new RemoteProvisionException(__('server_backups.mikrotik_backup_empty'));
            }

            $bytes = (int) filesize($localAbsolutePath);

            $this->removeRouterFile($server, $remoteFilename);

            return [
                'filename' => basename($localAbsolutePath),
                'bytes' => $bytes,
                'remote_name' => $remoteFilename,
            ];
        } catch (Throwable $exception) {
            $this->removeRouterFile($server, $remoteFilename);

            throw $exception;
        }
    }

    protected function remoteBackupBaseName(Server $server): string
    {
        return 'shahpanel-'.$server->id.'-'.now()->format('YmdHis');
    }

    protected function waitForRouterFile(Server $server, string $remoteFilename, int $maxAttempts = 15): void
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($this->routerFileExists($server, $remoteFilename)) {
                return;
            }

            usleep(500_000);
        }

        throw new RemoteProvisionException(__('server_backups.mikrotik_backup_not_created', [
            'file' => $remoteFilename,
        ]));
    }

    protected function routerFileExists(Server $server, string $filename): bool
    {
        $rows = $this->mikrotik->queryRouter($server, '/file/print', ['?name' => $filename]);

        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $filename) {
                return true;
            }
        }

        return false;
    }

    protected function removeRouterFile(Server $server, string $filename): void
    {
        try {
            $rows = $this->mikrotik->queryRouter($server, '/file/print', ['?name' => $filename]);

            foreach ($rows as $row) {
                if (($row['name'] ?? null) === $filename && isset($row['.id'])) {
                    $this->mikrotik->sendCommand($server, '/file/remove', ['.id' => $row['.id']]);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('MikroTik backup temp file cleanup failed', [
                'server_id' => $server->id,
                'file' => $filename,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function downloadViaSftp(Server $server, string $remoteFilename, string $localAbsolutePath): void
    {
        $username = $server->username_enc;
        $password = $server->password_enc;

        if ($username === null || $password === null) {
            throw new RemoteProvisionException(__('server_backups.mikrotik_credentials_missing'));
        }

        try {
            PhpSecLibLoader::ensureAvailable();
        } catch (\Throwable $exception) {
            throw new RemoteProvisionException(__('server_backups.mikrotik_phpseclib_required', [
                'detail' => $exception->getMessage(),
            ]));
        }

        $host = $server->apiConnectionHost();
        $sshPort = $server->mikrotikSshPort();

        if ($sshPort === null) {
            throw new RemoteProvisionException(__('server_backups.mikrotik_ssh_port_required'));
        }

        $timeout = (int) config('shahpanel.mikrotik.backup_scp_timeout', 180);

        $sftpClass = PhpSecLibLoader::sftpClass();
        $sftp = new $sftpClass($host, $sshPort, $timeout);

        if (! $sftp->login($username, $password)) {
            throw new RemoteProvisionException(__('server_backups.mikrotik_sftp_login_failed', [
                'message' => trim((string) ($sftp->getLastError() ?: ($sftp->getErrors()[0] ?? ''))),
            ]));
        }

        if ($sftp->get($remoteFilename, $localAbsolutePath) !== true) {
            throw new RemoteProvisionException(__('server_backups.mikrotik_scp_failed', [
                'message' => trim((string) ($sftp->getLastError() ?: ($sftp->getErrors()[0] ?? $remoteFilename))),
            ]));
        }
    }
}
