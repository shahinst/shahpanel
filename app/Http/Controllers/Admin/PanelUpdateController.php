<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PanelVersionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin's "Update" page: installed version, what the newer versions add,
 * and how to install them.
 */
class PanelUpdateController extends Controller
{
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
        ]);
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
