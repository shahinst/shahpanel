<?php

namespace Modules\Tunneling\Console;

use App\Support\TunnelingSchema;
use App\Support\TunnelQueueHealth;
use App\Support\TunnelQueueMaintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class TunnelingDoctorCommand extends Command
{
    protected $signature = 'tunneling:doctor';

    protected $description = 'Diagnose tunneling module readiness (tables, config, routes)';

    public function handle(): int
    {
        $this->info('Tunneling doctor');

        $missing = TunnelingSchema::missingTables();

        if ($missing === []) {
            $this->line('<fg=green>✓</> All required DB tables exist.');
        } else {
            $this->error('Missing tables:');
            foreach ($missing as $table) {
                $this->line("  - {$table}");
            }
            $this->newLine();
            $this->line('Fix: php artisan migrate --force');
        }

        if (is_file(config_path('tunneling.php'))) {
            $this->line('<fg=green>✓</> config/tunneling.php');
        } else {
            $this->error('Missing config/tunneling.php — upload from repo.');
        }

        if (is_file(lang_path('fa/tunneling.php'))) {
            $this->line('<fg=green>✓</> lang/fa/tunneling.php');
        } else {
            $this->warn('Missing lang/fa/tunneling.php');
        }

        if (class_exists(\App\Services\Tunneling\TunnelGroupOrchestrator::class)) {
            $this->line('<fg=green>✓</> TunnelGroupOrchestrator class');
        } else {
            $this->error('Missing app/Services/Tunneling/ — upload full folder.');
        }

        if (Schema::hasTable('servers')) {
            foreach (['last_cpu_pct', 'metrics_sampled_at'] as $column) {
                if (Schema::hasColumn('servers', $column)) {
                    $this->line("<fg=green>✓</> servers.{$column}");
                } else {
                    $this->warn("Missing servers.{$column} — run migrate (tunneling migration adds it).");
                }
            }
        }

        $queue = TunnelQueueHealth::snapshot();
        $this->newLine();
        $this->line('Queue worker: '.($queue['healthy'] ? '<fg=green>active</>' : '<fg=red>INACTIVE</>'));
        $this->line("  Pending jobs: {$queue['pending']}");
        $this->line("  Failed jobs: {$queue['failed']}");
        $this->line('  Last worker run: '.($queue['last_run'] ?? 'never'));

        $breakdown = TunnelQueueMaintenance::pendingByClass();
        if ($breakdown !== []) {
            $this->line('  Pending by class:');
            foreach ($breakdown as $class => $count) {
                $this->line("    - {$class}: {$count}");
            }
        }

        if (! $queue['healthy']) {
            $this->warn('Fix: ensure cron runs `php artisan schedule:run` every minute (includes queue:work), or run: php artisan tunneling:run-queue --purge-background');
        }

        if ($queue['pending'] > 30) {
            $this->warn('Queue backlog detected. Run: php artisan tunneling:purge-queue --background --failed');
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }
}
