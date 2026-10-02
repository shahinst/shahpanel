<?php

namespace App\Console\Commands;

use App\Services\PanelVersionService;
use Illuminate\Console\Command;

class CheckPanelUpdate extends Command
{
    protected $signature = 'panel:check-update';

    protected $description = 'Ask GitHub whether a newer panel version is available';

    public function handle(PanelVersionService $versions): int
    {
        $state = $versions->refresh();

        if (! $state['ok']) {
            $this->warn('Update check failed: '.$state['error']);

            return self::FAILURE;
        }

        $this->info('Installed: '.$versions->current().' — latest: '.$versions->latestLabel($state)
            .($versions->updateAvailable($state) ? ' (update available)' : ' (up to date)'));

        return self::SUCCESS;
    }
}
