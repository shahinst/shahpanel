<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AdminSystemHealthService;
use App\Services\AgentSellerMarkupReconciliationService;
use App\Services\AgentSellerMarkupService;
use App\Services\CronManagerService;
use App\Support\CronDocumentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AutomationController extends Controller
{
    public function pricing(AgentSellerMarkupService $markupService): View
    {
        return view('admin.automation.pricing', [
            'agentSellerMarkupPercent' => $markupService->percent(),
        ]);
    }

    public function index(
        CronManagerService $cronManager,
        AdminSystemHealthService $health,
    ): View {
        $env = $cronManager->detectEnvironment();

        return view('admin.automation.index', [
            'environment' => $env,
            'canInstall' => $cronManager->canInstall(),
            'installBlockedReason' => $cronManager->installReason(),
            'crontabInstalled' => $cronManager->isPanelCronInstalled(),
            'currentCrontab' => $cronManager->readCrontabSafe(),
            'previewLines' => $cronManager->previewInstallLines(),
            'cronJobs' => $health->cronJobStatuses(),
            'cronDocs' => CronDocumentation::entries(),
            'projectPath' => str_replace('\\', '/', base_path()),
            'installedAt' => cache('system_health.cron_installed_at'),
        ]);
    }

    /** Panel-wide thresholds driving the "expiring accounts" list. */
    public function expiring(): View
    {
        return view('admin.automation.expiring', [
            'days' => \App\Support\ExpiringAccountThresholds::days(),
            'volumeMb' => \App\Support\ExpiringAccountThresholds::volumeMb(),
        ]);
    }

    public function updateExpiring(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'days' => [
                'required', 'integer',
                'min:'.\App\Support\ExpiringAccountThresholds::MIN_DAYS,
                'max:'.\App\Support\ExpiringAccountThresholds::MAX_DAYS,
            ],
            'volume_mb' => [
                'required', 'integer',
                'min:'.\App\Support\ExpiringAccountThresholds::MIN_VOLUME_MB,
                'max:'.\App\Support\ExpiringAccountThresholds::MAX_VOLUME_MB,
            ],
        ], [
            'days.min' => 'حداقل ۱ روز.',
            'days.max' => 'حداکثر ۷ روز.',
            'volume_mb.min' => 'حداقل ۱۰۰ مگابایت.',
            'volume_mb.max' => 'حداکثر ۵۱۲۰ مگابایت (۵ گیگ).',
        ]);

        Setting::setValue(
            \App\Support\ExpiringAccountThresholds::DAYS_KEY,
            (string) \App\Support\ExpiringAccountThresholds::clampDays((int) $validated['days']),
        );
        Setting::setValue(
            \App\Support\ExpiringAccountThresholds::VOLUME_MB_KEY,
            (string) \App\Support\ExpiringAccountThresholds::clampVolumeMb((int) $validated['volume_mb']),
        );

        return redirect()
            ->route('admin.automation.expiring')
            ->with('success', 'آستانه‌ی اکانت‌های در حال انقضا ذخیره شد.');
    }

    public function updatePricing(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'agent_seller_markup_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Setting::setValue(
            AgentSellerMarkupService::SETTING_PERCENT,
            number_format((float) $validated['agent_seller_markup_percent'], 2, '.', ''),
        );

        app(AgentSellerMarkupReconciliationService::class)->reconcileAll();

        return redirect()
            ->route('admin.automation.pricing')
            ->with('success', __('automation.pricing_saved'));
    }

    public function install(CronManagerService $cronManager): RedirectResponse
    {
        $result = $cronManager->installRequired(auth()->user());

        return redirect()
            ->route('admin.automation.index')
            ->with($result['ok'] ? 'success' : 'warning', $result['message'])
            ->with('install_log', $result['lines']);
    }
}
