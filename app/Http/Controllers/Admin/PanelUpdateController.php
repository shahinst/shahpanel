<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\PanelVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use Throwable;

/**
 * The admin's "Update" page: installed version, what the newer versions add,
 * and how to install them.
 */
class PanelUpdateController extends Controller
{
    /** Root-owned launcher installed by install.sh / update.sh (scripts/shahpanel-update). */
    public const LAUNCHER = '/usr/local/sbin/shahpanel-update';

    /** Where the launcher writes <token>.txt (log) and <token>.json (status). */
    public const PROGRESS_DIR = '/var/lib/shahpanel/progress';

    /** Largest log slice sent per poll. */
    protected const LOG_CHUNK = 262144;

    public function index(Request $request, PanelVersionService $versions): View
    {
        // The page always shows a fresh answer; the banner elsewhere reads the cache.
        $state = $request->boolean('cached') ? ($versions->cachedState() ?? $versions->refresh()) : $versions->refresh();
        $stamp = now()->format('Ymd-His');
        $backupDir = rtrim((string) config('shahpanel.update.backup_dir', '/var/backups/shahpanel'), '/');

        return view('admin.updates.index', [
            'currentVersion' => $versions->current(),
            'currentCommit' => substr((string) $versions->currentCommit(), 0, 7),
            'state' => $state,
            'updateAvailable' => $versions->updateAvailable($state),
            'latestLabel' => $versions->latestLabel($state),
            'newerNotes' => $versions->newerNotes($state),
            'currentNotes' => $versions->currentNotes(),
            'backupPath' => $backupDir.'/db-'.$stamp.'.sql.gz',
            'backupDir' => $backupDir,
            'appDir' => base_path(),
            'stamp' => $stamp,
            'webUpdaterReady' => $this->webUpdaterReady(),
        ]);
    }

    /**
     * Start the update. The launcher runs update.sh as root in its own unit and
     * returns at once; the page then follows the run through the progress and
     * status files the launcher writes under public/update-progress/<token>.
     */
    public function run(Request $request, ActivityLogService $activityLog): JsonResponse
    {
        $validated = $request->validate([
            'stamp' => ['required', 'string', 'regex:/^\d{8}-\d{6}$/'],
        ]);

        if (! $this->webUpdaterReady()) {
            return response()->json(['error' => __('updates.web_not_installed')], 409);
        }

        $token = bin2hex(random_bytes(16));

        try {
            $result = Process::timeout(30)->run(['sudo', '-n', self::LAUNCHER, $token, $validated['stamp']]);
        } catch (Throwable $exception) {
            Log::error('Web update could not start', ['error' => $exception->getMessage()]);

            return response()->json(['error' => __('updates.start_failed', ['error' => $exception->getMessage()])], 500);
        }

        if (! $result->successful()) {
            $error = trim($result->errorOutput() ?: $result->output()) ?: 'exit '.$result->exitCode();
            Log::error('Web update could not start', ['error' => $error]);

            return response()->json(['error' => __('updates.start_failed', ['error' => $error])], 500);
        }

        $backupDir = rtrim((string) config('shahpanel.update.backup_dir', '/var/backups/shahpanel'), '/');

        $activityLog->log($request->user(), 'panel.update_started', null, [
            'token' => substr($token, 0, 8),
            'backup' => $backupDir.'/db-'.$validated['stamp'].'.sql.gz',
        ]);

        return response()->json([
            'token' => $token,
            'progress_url' => route('admin.updates.progress', ['token' => $token]),
            'backup' => $backupDir.'/db-'.$validated['stamp'].'.sql.gz',
        ]);
    }

    /**
     * The run's status and the part of its log after `offset`. Served by the
     * panel to the main admin only (updates.* is super_only), from a root-owned
     * folder outside the webroot.
     */
    public function progress(Request $request, string $token): JsonResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $token) === 1, 404);

        $statusFile = self::PROGRESS_DIR.'/'.$token.'.json';
        $logFile = self::PROGRESS_DIR.'/'.$token.'.txt';

        if (! is_file($statusFile)) {
            return response()->json(['status' => null, 'log' => '', 'offset' => 0], 404);
        }

        $status = json_decode((string) @file_get_contents($statusFile), true);
        $offset = max(0, (int) $request->query('offset', 0));
        $log = '';

        if (is_file($logFile)) {
            $size = (int) @filesize($logFile);

            if ($offset < $size && ($handle = @fopen($logFile, 'rb')) !== false) {
                fseek($handle, $offset);
                $log = (string) fread($handle, self::LOG_CHUNK);
                fclose($handle);
            }

            // Never split a multi-byte character between two polls.
            $valid = mb_strcut($log, 0, strlen($log), 'UTF-8');
            $offset += strlen($valid);
            $log = $valid;
        }

        return response()->json([
            'status' => is_array($status) ? $status : null,
            'log' => $log,
            'offset' => $offset,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Whether this server has the launcher and the sudo rule that lets the web
     * user start it (installed by install.sh, or by one manual update.sh run
     * on servers installed before the Update button existed).
     */
    protected function webUpdaterReady(): bool
    {
        if (! is_file(self::LAUNCHER) || ! function_exists('proc_open')) {
            return false;
        }

        try {
            return Process::timeout(10)->run(['sudo', '-n', '-l', self::LAUNCHER])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The welcome "star us on GitHub" popup has been seen; never show it to
     * this admin again.
     */
    public function dismissStarPrompt(Request $request): \Illuminate\Http\JsonResponse
    {
        \App\Models\Setting::setValue(self::starPromptKey($request->user()->id), now()->toIso8601String());

        return response()->json(['ok' => true]);
    }

    public static function starPromptKey(int $userId): string
    {
        return 'star_prompt_seen_'.$userId;
    }

    public function check(PanelVersionService $versions): RedirectResponse
    {
        $versions->refresh();

        return redirect()->route('admin.updates.index', ['cached' => 1]);
    }
}
