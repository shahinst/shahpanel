<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Support\BotTexts;
use Modules\ShahBot\Support\MenuLayout;

class EditorController extends Controller
{
    public function edit(BotTexts $texts, MenuLayout $layout): View
    {
        return view('shahbot::editor', [
            'defaults' => $texts->defaults(),
            'overrides' => $texts->overrides(),
            'layout' => $layout->all(),
        ]);
    }

    public function updateTexts(Request $request, BotTexts $texts): RedirectResponse
    {
        $request->validate(['texts' => ['array'], 'texts.*' => ['nullable', 'string', 'max:4000']]);

        $texts->save($request->boolean('reset') ? [] : (array) $request->input('texts', []));

        return redirect()->route('admin.shahbot.editor')->with('success', __('shahbot::admin.saved'));
    }

    public function updateKeyboard(Request $request, MenuLayout $layout): RedirectResponse
    {
        $request->validate(['layout' => ['array']]);
        $layout->save((array) $request->input('layout', []));

        return redirect()->route('admin.shahbot.editor')->with('success', __('shahbot::admin.saved'));
    }
}
