<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        unset($validated['current_password']);

        $user->update($validated);

        $panel = explode('.', (string) $request->route()?->getName())[0] ?: 'admin';

        return redirect()
            ->route("{$panel}.profile.edit")
            ->with('success', __('app.saved'));
    }
}
