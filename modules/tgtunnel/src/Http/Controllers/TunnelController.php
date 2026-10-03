<?php

namespace Modules\TgTunnel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\TgTunnel\Support\Tunnel;
use Throwable;

class TunnelController extends Controller
{
    public function index(Tunnel $tunnel): View
    {
        return view('tgtunnel::index', [
            'installed' => $tunnel->installed(),
            'status' => $tunnel->status(fresh: true),
            'test' => session('tgtunnel_test'),
        ]);
    }

    public function save(Request $request, Tunnel $tunnel): RedirectResponse
    {
        $data = $request->validate(['config' => ['required', 'string', 'max:8000']]);

        try {
            $tunnel->apply($data['config']);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', __('tgtunnel::tunnel.apply_failed', ['error' => $e->getMessage()]));
        }

        // Give the handshake a moment, then prove traffic really goes through.
        sleep(3);

        return redirect()->route('admin.tgtunnel.index')
            ->with('success', __('tgtunnel::tunnel.applied'))
            ->with('tgtunnel_test', $tunnel->test());
    }

    public function test(Tunnel $tunnel): RedirectResponse
    {
        return back()->with('tgtunnel_test', $tunnel->test() ?? ['ok' => false, 'error' => __('tgtunnel::tunnel.helper_missing')]);
    }

    public function disconnect(Tunnel $tunnel): RedirectResponse
    {
        $tunnel->down();

        return back()->with('success', __('tgtunnel::tunnel.removed'));
    }
}
