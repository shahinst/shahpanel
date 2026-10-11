<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Themes\ThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ThemeController extends Controller
{
    public function __construct(protected ThemeRegistry $themes) {}

    public function index(): View
    {
        return view('admin.themes.index', [
            'themes' => $this->themes->all(),
            'active' => $this->themes->activeSlug(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['theme' => ['required', 'string', 'max:40']]);

        if (! $this->themes->has($data['theme'])) {
            return back()->withErrors(['theme' => __('themes.unknown')]);
        }

        $this->themes->activate($data['theme']);

        return redirect()->route('admin.themes.index')->with('success', __('themes.saved', ['name' => $this->themes->active()['name']]));
    }
}
