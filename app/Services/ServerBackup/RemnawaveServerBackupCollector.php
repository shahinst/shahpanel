<?php

namespace App\Services\ServerBackup;

use App\Models\Server;
use App\Services\RemnawaveService;
use Throwable;

class RemnawaveServerBackupCollector implements ServerBackupCollector
{
    public function __construct(
        protected RemnawaveService $remnawave,
    ) {}

    public function supports(Server $server): bool
    {
        return $server->isRemnawave();
    }

    public function collect(Server $server, array $paths): array
    {
        unset($paths);

        $client = $this->remnawave->client($server);
        $client->authenticate();

        $sections = [];
        $errors = [];

        $jobs = [
            'status' => fn () => $client->getStatus(),
            'internal_squads' => fn () => $this->remnawave->listSquads($server),
            'nodes' => fn () => $this->remnawave->listNodes($server),
            'inbounds' => fn () => $this->remnawave->listInbounds($server),
            'users' => fn () => $this->remnawave->listAllUsers($server),
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
