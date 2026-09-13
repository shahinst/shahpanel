<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Server;
use App\Models\User;
use App\Services\SanaeiServerMigrationService;
use Illuminate\Console\Command;

class MigrateSanaeiServerCommand extends Command
{
    protected $signature = 'sanaei:migrate-server
                            {to : شناسه یا نام سرور مقصد (3x-ui جدید)}
                            {--from= : شناسه یا نام سرور قدیم — انتقال همه اکانت‌ها}
                            {--push-only : فقط push روی سرور مقصد (server_id قبلاً عوض شده)}
                            {--dry-run : فقط گزارش بدون تغییر}
                            {--no-sync-inbounds : inboundهای مقصد را قبل از کار نگیر}';

    protected $description = 'انتقال اکانت‌های Sanaei پنل به سرور 3x-ui جدید با همان حجم، مصرف و انقضا';

    public function handle(SanaeiServerMigrationService $migration): int
    {
        $to = $this->resolveServer((string) $this->argument('to'));

        if ($to === null) {
            $this->error('سرور مقصد یافت نشد.');
            $this->listServers($migration);

            return self::FAILURE;
        }

        $actor = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();
        $dryRun = (bool) $this->option('dry-run');
        $syncInbounds = ! $this->option('no-sync-inbounds');

        if ($this->option('push-only')) {
            $this->info("Push همه اکانت‌های Sanaei روی «{$to->name}» (#{$to->id})…");

            $result = $migration->pushAllOnServer($to, $dryRun, $syncInbounds);
        } else {
            $fromId = $this->option('from');

            if ($fromId === null || $fromId === '') {
                $this->error('برای انتقال از سرور قدیم، --from=ID یا --push-only لازم است.');
                $this->listServers($migration);

                return self::FAILURE;
            }

            $from = $this->resolveServer((string) $fromId);

            if ($from === null) {
                $this->error('سرور مبدأ یافت نشد.');
                $this->listServers($migration);

                return self::FAILURE;
            }

            $this->info("انتقال از «{$from->name}» (#{$from->id}) به «{$to->name}» (#{$to->id})…");

            if ($dryRun) {
                $this->warn('حالت dry-run — هیچ تغییری اعمال نمی‌شود.');
            }

            $result = $migration->migrateFromServer($from, $to, $actor, $dryRun, $syncInbounds);
        }

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        $this->newLine();
        $this->table(
            ['کل', 'منتقل‌شده', 'push', 'رد شده', 'خطا'],
            [[
                $result['total'],
                $result['transferred'],
                $result['pushed'],
                $result['skipped'],
                $result['failed'] + count($result['errors']),
            ]]
        );

        return ($result['failed'] === 0 && $result['errors'] === []) ? self::SUCCESS : self::FAILURE;
    }

    protected function resolveServer(string $token): ?Server
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        if (ctype_digit($token)) {
            return Server::query()->find((int) $token);
        }

        return Server::query()
            ->where('name', $token)
            ->orWhere('host', $token)
            ->first();
    }

    protected function listServers(SanaeiServerMigrationService $migration): void
    {
        $this->info('سرورهای Sanaei:');
        foreach ($migration->listSanaeiServers() as $row) {
            $this->line("  #{$row['id']} {$row['name']} ({$row['host']})");
        }
    }
}
