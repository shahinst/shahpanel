<?php

namespace Modules\Watchdog\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ServerBackupTelegramSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Modules\Watchdog\Services\WatchdogService;

class WatchdogController extends Controller
{
    public function index(): View
    {
        return view('watchdog::index', [
            'lastRun' => Cache::get(WatchdogService::LAST_RUN_KEY),
            'telegramReady' => ServerBackupTelegramSettings::isConfigured(),
            'thresholds' => collect(WatchdogService::DEFAULTS)->map(fn ($v, string $key) => WatchdogService::threshold($key))->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'watchdog_disk_percent' => ['required', 'integer', 'min:1', 'max:90'],
            'watchdog_cert_days' => ['required', 'integer', 'min:1', 'max:90'],
            'watchdog_pool_percent' => ['required', 'integer', 'min:10', 'max:100'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::setValue($key, (string) $value);
        }

        return back()->with('success', __('app.saved'));
    }

    public function run(WatchdogService $watchdog): RedirectResponse
    {
        $watchdog->run();

        return back()->with('success', __('watchdog::watchdog.ran'));
    }
}
