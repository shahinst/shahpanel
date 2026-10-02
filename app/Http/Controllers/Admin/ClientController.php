<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\ManagesClients;
use App\Http\Controllers\Concerns\ShowsClientAccountDetails;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    use ManagesClients;
    use ShowsClientAccountDetails;

    public function destroy(Request $request, User $client, UserDeletionService $deletionService): RedirectResponse
    {
        abort_unless($client->role === UserRole::Client, 404);
        $this->authorize('delete', $client);

        try {
            $deletionService->delete($request->user(), $client);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.clients.index')
            ->with('success', __('app.deleted'));
    }

    protected function clientsPanel(): string
    {
        return 'admin';
    }
}
