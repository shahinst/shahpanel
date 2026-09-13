<?php

namespace App\Console\Commands;

use App\Services\FirewallService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Refreshes both country datasets:
 *   - the China ranges the firewall drops outright
 *   - the IP→country table the admin list reads flags from
 *
 * Both come from the same source, so one command keeps them consistent.
 */
class SyncCountryDataCommand extends Command
{
    protected $signature = 'firewall:sync-country-data
        {--skip-geo : refresh only the blocked-country ranges}';

    protected $description = 'Download country IP ranges for the firewall and the admin flag lookup';

    protected const DIR = '/var/lib/panel-firewall';

    protected const CN_URL = 'https://www.ipdeny.com/ipblocks/data/countries/cn.zone';

    protected const ALL_URL = 'https://www.ipdeny.com/ipblocks/data/countries/all-zones.tar.gz';

    public function handle(FirewallService $firewall): int
    {
        if (! $this->refreshChina($firewall)) {
            return self::FAILURE;
        }

        if (! $this->option('skip-geo') && ! $this->refreshGeoTable($firewall)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function refreshChina(FirewallService $firewall): bool
    {
        $this->info('Downloading China ranges…');

        // The download happens root-side: the web user may ask for a refresh
        // but must never be able to write the list it will be blocked by.
        if (! $firewall->fetchLists()) {
            $this->error('could not download the China list; the existing set is left alone');

            return false;
        }

        if (! $firewall->loadCountryBlock()) {
            $this->error('the helper refused to load the ranges');

            return false;
        }

        $this->info('China ranges loaded: '.$firewall->status()['cn']);

        return true;
    }

    protected function refreshGeoTable(FirewallService $firewall): bool
    {
        $this->info('Downloading per-country ranges for flag lookup…');

        $extract = self::DIR.'/zones';

        if (! $firewall->fetchZones()) {
            $this->error('could not download the country archive');

            return false;
        }

        $files = glob($extract.'/*.zone') ?: [];

        if ($files === []) {
            $this->error('the archive held no zone files');

            return false;
        }

        $this->info('Rebuilding lookup table from '.count($files).' countries…');

        // Build into a scratch table, then swap, so the admin screen never
        // reads a half-written table.
        DB::statement('DROP TABLE IF EXISTS ip_country_ranges_new');
        DB::statement('CREATE TABLE ip_country_ranges_new LIKE ip_country_ranges');

        $rows = [];
        $total = 0;
        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $code = strtoupper(pathinfo($file, PATHINFO_FILENAME));

            if (! preg_match('/^[A-Z]{2}$/', $code)) {
                $bar->advance();

                continue;
            }

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $cidr) {
                $range = $this->cidrToRange(trim($cidr));

                if ($range === null) {
                    continue;
                }

                $rows[] = ['start_ip' => $range[0], 'end_ip' => $range[1], 'country_code' => $code];
                $total++;

                if (count($rows) >= 2000) {
                    DB::table('ip_country_ranges_new')->insert($rows);
                    $rows = [];
                }
            }

            $bar->advance();
        }

        if ($rows !== []) {
            DB::table('ip_country_ranges_new')->insert($rows);
        }

        $bar->finish();
        $this->newLine();

        if ($total === 0) {
            DB::statement('DROP TABLE IF EXISTS ip_country_ranges_new');
            $this->error('no ranges parsed; keeping the previous table');

            return false;
        }

        DB::statement('DROP TABLE IF EXISTS ip_country_ranges_old');
        DB::statement('RENAME TABLE ip_country_ranges TO ip_country_ranges_old, ip_country_ranges_new TO ip_country_ranges');
        DB::statement('DROP TABLE IF EXISTS ip_country_ranges_old');

        $this->info("Lookup table rebuilt: {$total} ranges");

        return true;
    }

    /** @return array{0: int, 1: int}|null */
    protected function cidrToRange(string $cidr): ?array
    {
        if (! str_contains($cidr, '/')) {
            return null;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $start = ip2long($subnet);

        if ($start === false || ! is_numeric($bits)) {
            return null;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return null;
        }

        $size = 2 ** (32 - $bits);

        return [$start, $start + $size - 1];
    }

    protected function download(string $url, string $target): bool
    {
        @mkdir(dirname($target), 0755, true);

        $process = new Process(['curl', '-fsS', '--max-time', '180', '-L', '-o', $target, $url]);
        $process->setTimeout(200);
        $process->run();

        return $process->isSuccessful() && is_file($target) && filesize($target) > 0;
    }
}
