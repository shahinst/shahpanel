<?php

namespace App\Services\ServerBackup;

use App\Models\Server;
use App\Services\PasarguardService;
use Throwable;

class PasarguardServerBackupCollector implements ServerBackupCollector
{
    public function __construct(
        protected PasarguardService $pasarguard,
    ) {}

    public function supports(Server $server): bool
    {
        return $server->isPasarguard();
    }

    public function collect(Server $server, array $paths): array
    {
        unset($paths);

        $client = $this->pasarguard->client($server);
        $client->authenticate();

        $sections = [];
        $errors = [];

        $jobs = [
            'admin' => fn () => $client->getCurrentAdmin(),
            'system' => fn () => $this->pasarguard->getSystem($server),
            'settings' => fn () => $client->getSettings(),
            'inbounds' => fn () => $this->pasarguard->listInboundTags($server),
            'groups' => fn () => $this->pasarguard->listGroups($server),
            'hosts' => fn () => $client->listHosts(),
            'nodes' => fn () => $client->getNodes(),
            'user_templates' => fn () => $client->listUserTemplates(),
            'users' => fn () => $this->pasarguard->listAllUsers($server),
        ];

        foreach ($jobs as $name => $callback) {
            try {
                $sections[$name] = $callback();
            } catch (Throwable $exception) {
                $errors[] = [
                    'section' => $name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'sections' => $sections,
            'errors' => $errors,
        ];
    }
}
