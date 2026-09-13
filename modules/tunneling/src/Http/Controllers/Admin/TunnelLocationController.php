<?php

namespace Modules\Tunneling\Http\Controllers\Admin;

use AppHttpControllersController;
use App\Enums\ServerType;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TunnelLocationController extends Controller
{
    public function index(): View
    {
        return view('tunneling::locations', [
            'locations' => Location::query()
                ->with('iranServer')
                ->withCount('tunnelGroups', 'managedInterfaces')
                ->orderBy('name')
                ->get(),
            'servers' => Server::query()
                ->active()
                ->where('type', ServerType::Mikrotik)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Location::create($this->validated($request));

        return back()->with('success', __('tunneling.location_created'));
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $location->update($this->validated($request, $location));

        return back()->with('success', __('tunneling.location_updated'));
    }

    public function destroy(Location $location): RedirectResponse
    {
        if ($location->tunnelGroups()->exists() || $location->managedInterfaces()->exists()) {
            return back()->with('error', __('tunneling.location_in_use'));
        }

        $location->delete();

        return back()->with('success', __('tunneling.location_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Location $location = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => [
                'required', 'string', 'max:16', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('locations', 'code')->ignore($location?->id),
            ],
            'iran_server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'iran_server_id' => isset($data['iran_server_id']) ? (int) $data['iran_server_id'] : null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
