<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotTutorial;

class TutorialController extends Controller
{
    public function index(): View
    {
        return view('shahbot::tutorials', [
            'tutorials' => BotTutorial::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        BotTutorial::query()->create($this->validated($request));

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function update(Request $request, BotTutorial $tutorial): RedirectResponse
    {
        $tutorial->update($this->validated($request));

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function destroy(BotTutorial $tutorial): RedirectResponse
    {
        $tutorial->delete();

        return back()->with('success', __('shahbot::admin.deleted'));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:128'],
            'body' => ['nullable', 'string', 'max:3500'],
            'url' => ['nullable', 'url:https,http', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
