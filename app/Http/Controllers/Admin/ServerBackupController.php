<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerBackup;
use App\Services\ServerBackup\ServerBackupService;
use App\Services\ServerBackup\ServerBackupStorage;
use App\Services\ServerBackup\ServerBackupTelegramNotifier;
use App\Support\ServerBackupTelegramSettings;
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
            return view('admin.settings.server-backups.index', array_merge($this->telegramViewData(), [
                'servers' => collect(),
                'recentBackups' => collect(),
                'migrationRequired' => true,
            ]));
        }

        try {
            $servers = $backupService->backupableServers();

            $recentBackups = ServerBackup::query()
                ->with(['server', 'triggeredBy'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->groupBy(static fn (ServerBackup $backup): string => (string) $backup->server_id);

            return view('admin.settings.server-backups.index', array_merge($this->telegramViewData(), [
                'servers' => $servers,
                'recentBackups' => $recentBackups,
                'migrationRequired' => false,
            ]));
        } catch (Throwable $exception) {
            report($exception);

            return view('admin.settings.server-backups.index', array_merge($this->telegramViewData(), [
                'servers' => collect(),
                'recentBackups' => collect(),
                'migrationRequired' => false,
                'loadError' => $exception->getMessage(),
            ]));
        }
    }

    /**
     * تنظیمات تلگرام و آمادگی ستون‌های زمان‌بندی؛ در هر سه مسیر index لازم است،
     * حتی وقتی جدول بک‌آپ‌ها هنوز ساخته نشده، تا ادمین بتواند توکن را ذخیره کند.
     *
     * @return array<string, mixed>
     */
    protected function telegramViewData(): array
    {
        return [
            'telegramEnabled' => ServerBackupTelegramSettings::isEnabled(),
            'telegramConfigured' => ServerBackupTelegramSettings::isConfigured(),
            'telegramMaskedToken' => ServerBackupTelegramSettings::maskedBotToken(),
            'telegramChatId' => ServerBackupTelegramSettings::chatId(),
            'scheduleReady' => Schema::hasColumn('servers', 'backup_times'),
            'panelTimezone' => (string) config('app.timezone'),
            // زمان‌بندی دیتابیس پنل در جدول settings است، پس به ستون تازه و
            // مایگریشن وابسته نیست و حتی وقتی جدول بک‌آپ‌ها نیست هم قابل تنظیم است.
            'databaseScheduleEnabled' => ServerBackupTelegramSettings::isDatabaseEnabled(),
            'databaseScheduleTimes' => implode(', ', ServerBackupTelegramSettings::databaseTimes()),
        ];
    }

    public function updateTelegram(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ]);

        $token = trim((string) ($validated['telegram_bot_token'] ?? ''));

        // فیلد خالی یعنی «توکن فعلی را نگه دار»؛ وگرنه هر ذخیرهٔ فرم آن را پاک می‌کرد،
        // چون توکن هیچ‌وقت به صفحه برنمی‌گردد و فقط ماسک‌شده نمایش داده می‌شود.
        if ($token !== '') {
            ServerBackupTelegramSettings::setBotToken($token);
        }

        ServerBackupTelegramSettings::setChatId(trim((string) ($validated['telegram_chat_id'] ?? '')));
        ServerBackupTelegramSettings::setEnabled($request->boolean('telegram_enabled'));

        return redirect()
            ->route('admin.settings.server-backups.index')
            ->with('success', __('server_backups.telegram_saved'));
    }

    public function testTelegram(ServerBackupTelegramNotifier $notifier): RedirectResponse
    {
        $result = $notifier->sendTest();

        return redirect()
            ->route('admin.settings.server-backups.index')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updateSchedule(Request $request): RedirectResponse
    {
        if (! Schema::hasColumn('servers', 'backup_times')) {
            return redirect()
                ->route('admin.settings.server-backups.index')
                ->with('error', __('server_backups.migration_required'));
        }

        $request->validate([
            'schedule_enabled' => ['nullable', 'array'],
            'schedule_times' => ['nullable', 'array'],
        ]);

        $enabled = $request->input('schedule_enabled');
        $times = $request->input('schedule_times');
        $enabled = is_array($enabled) ? $enabled : [];
        $times = is_array($times) ? $times : [];

        $servers = Server::query()
            ->whereIn('id', array_map('intval', array_keys($times)))
            ->orderBy('name')
            ->get();

        $rejected = [];

        foreach ($servers as $server) {
            $raw = $times[$server->id] ?? '';
            $parsed = Server::normalizeBackupTimes(is_string($raw) ? $raw : '');
            $wantsEnabled = (bool) ($enabled[$server->id] ?? false);

            // فعال‌بودن بدون ساعت معتبر یعنی بک‌آپی که هرگز اجرا نمی‌شود؛ پس
            // خاموشش می‌کنیم و صریح به ادمین می‌گوییم ورودی‌اش پذیرفته نشد.
            if ($wantsEnabled && $parsed === []) {
                $rejected[] = (string) $server->name;
                $wantsEnabled = false;
            }

            $server->forceFill([
                'backup_schedule_enabled' => $wantsEnabled,
                'backup_times' => $parsed === [] ? null : $parsed,
            ])->save();
        }

        $redirect = redirect()
            ->route('admin.settings.server-backups.index')
            ->with('success', __('server_backups.schedule_saved'));

        if ($rejected !== []) {
            $redirect->with('error', __('server_backups.schedule_invalid_times', [
                'name' => implode(', ', $rejected),
            ]));
        }

        return $redirect;
    }

    /**
     * زمان‌بندی بک‌آپ دیتابیسِ خودِ پنل — جدا از سرورها، با کلید و ساعت‌های خودش،
     * ولی با همان پارسر ساعت (Server::normalizeBackupTimes) تا قالب ورودی و
     * پذیرش ارقام فارسی در هر دو فرم یکی باشد.
     */
    public function updateDatabaseSchedule(Request $request): RedirectResponse
    {
        $request->validate([
            'database_schedule_times' => ['nullable', 'string', 'max:255'],
        ]);

        $times = ServerBackupTelegramSettings::setDatabaseTimes((string) $request->input('database_schedule_times', ''));
        $wantsEnabled = $request->boolean('database_schedule_enabled');

        // فعال‌بودن بدون ساعت معتبر یعنی بک‌آپی که هرگز اجرا نمی‌شود؛ پس خاموشش
        // می‌کنیم و صریح به ادمین می‌گوییم ورودی‌اش پذیرفته نشد.
        $rejected = $wantsEnabled && $times === [];

        ServerBackupTelegramSettings::setDatabaseEnabled($rejected ? false : $wantsEnabled);

        $redirect = redirect()
            ->route('admin.settings.server-backups.index')
            ->with('success', __('server_backups.database_schedule_saved'));

        if ($rejected) {
            $redirect->with('error', __('server_backups.database_schedule_invalid_times'));
        }

        return $redirect;
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
