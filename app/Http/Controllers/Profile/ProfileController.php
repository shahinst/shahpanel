<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\ApiTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(protected ApiTokenService $tokens) {}

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

        $passwordChanged = ! empty($validated['password']);

        if (! $passwordChanged) {
            unset($validated['password']);
        }

        unset($validated['current_password']);

        $user->update($validated);

        // Only once the new password is actually stored, and only for a password
        // change: an mp_ bearer lives up to 30 days, so a stolen token used to
        // survive the one reaction a victim has. Editing a name, email or phone
        // number is not a compromise signal and must not log the bots out.
        if ($passwordChanged) {
            $this->tokens->revokeAllForUser($user, 'password_changed');
        }

        $panel = explode('.', (string) $request->route()?->getName())[0] ?: 'admin';

        return redirect()
            ->route("{$panel}.profile.edit")
            ->with('success', __('app.saved'));
    }
}
