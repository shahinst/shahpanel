<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpWhitelist;
use App\Services\SecurityShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Imunify-like Web Shield panel: CrowdSec decisions, whitelist, malware scans, logs.
 */
class WebShieldController extends Controller
{
    public function __construct(
        protected SecurityShieldService $shield,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['overview', 'decisions', 'whitelist', 'scan', 'logs', 'services'], true)) {
            $tab = 'overview';
        }

        $logKind = $request->string('log')->toString();
        if (! in_array($logKind, ['crowdsec', 'nginx', 'nginx-error', 'fail2ban', 'clamav', 'modsec', 'modsec-danger', 'audit'], true)) {
            $logKind = 'crowdsec';
        }

        $status = $this->shield->status();
        $decisions = $tab === 'decisions' || $tab === 'overview' ? $this->shield->decisions() : [];
        $whitelist = $tab === 'whitelist' || $tab === 'overview' ? $this->shield->whitelist() : [];
        $scan = $tab === 'scan' || $tab === 'overview' ? $this->shield->scanReport() : [];
        $alerts = $tab === 'overview' ? $this->shield->alerts() : [];
        $logs = $tab === 'logs' ? $this->shield->logs($logKind) : ['ok' => true, 'lines' => [], 'file' => ''];

        $dbWhitelist = IpWhitelist::query()->orderBy('ip')->get();

        return view('admin.web-shield.index', [
            'tab' => $tab,
            'logKind' => $logKind,
            'status' => $status,
            'decisions' => $decisions,
            'whitelist' => $whitelist,
            'dbWhitelist' => $dbWhitelist,
            'scan' => $scan,
            'alerts' => $alerts,
            'logs' => $logs,
            'available' => $this->shield->isAvailable(),
            'clientIp' => $request->ip(),
        ]);
    }

    public function ban(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'seconds' => ['nullable', 'integer', 'min:60', 'max:31536000'],
        ]);

        if (! $this->shield->ban($data['ip'], (int) ($data['seconds'] ?? 3600))) {
            return back()->with('error', __('webshield.ban_failed'));
        }

        return back()->with('success', __('webshield.banned', ['ip' => $data['ip']]));
    }

    public function unban(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
        ]);

        if (! $this->shield->unban($data['ip'])) {
            return back()->with('error', __('webshield.unban_failed'));
        }

        return back()->with('success', __('webshield.unbanned', ['ip' => $data['ip']]));
    }

    public function whitelistAdd(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
        ]);

        if (! $this->shield->whitelistAdd($data['ip'])) {
            return back()->with('error', __('webshield.whitelist_failed'));
        }

        return back()->with('success', __('webshield.whitelist_added', ['ip' => $data['ip']]));
    }

    public function whitelistDel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
        ]);

        if (! $this->shield->whitelistDel($data['ip'])) {
            return back()->with('error', __('webshield.whitelist_failed'));
        }

        return back()->with('success', __('webshield.whitelist_removed', ['ip' => $data['ip']]));
    }

    public function whitelistMe(Request $request): RedirectResponse
    {
        $ip = $request->ip();
        if (! is_string($ip) || ! $this->shield->whitelistAdd($ip)) {
            return back()->with('error', __('webshield.whitelist_failed'));
        }

        return back()->with('success', __('webshield.whitelist_added', ['ip' => $ip]));
    }

    public function syncDbWhitelist(): RedirectResponse
    {
        $ips = IpWhitelist::query()->pluck('ip')->all();
        if (! $this->shield->whitelistSync($ips)) {
            return back()->with('error', __('webshield.sync_failed'));
        }

        return back()->with('success', __('webshield.synced'));
    }

    public function scanStart(): RedirectResponse
    {
        if (! $this->shield->startScan()) {
            return back()->with('error', __('webshield.scan_failed'));
        }

        return back()->with('success', __('webshield.scan_started'));
    }

    public function service(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:64'],
            'action' => ['required', 'in:start,stop,restart,reload'],
        ]);

        if (! $this->shield->serviceAction($data['action'], $data['service'])) {
            return back()->with('error', __('webshield.service_failed'));
        }

        return back()->with('success', __('webshield.service_ok', [
            'service' => $data['service'],
            'action' => $data['action'],
        ]));
    }

    public function init(): RedirectResponse
    {
        if (! $this->shield->init()) {
            return back()->with('error', __('webshield.init_failed'));
        }

        return back()->with('success', __('webshield.initialized'));
    }
}
