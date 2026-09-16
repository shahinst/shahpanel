<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Services\Modules\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModuleController extends Controller
{
    public function __construct(private readonly ModuleManager $manager)
    {
    }

    public function index(): View
    {
        $modules = Module::query()->orderBy('name')->get();

        return view('admin.modules.index', compact('modules'));
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'module' => ['required', 'file', 'max:51200'],
        ], [], ['module' => __('backend.module_file_attribute')]);

        $file = $request->file('module');
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            return back()->with('error', __('backend.module_zip_only'));
        }

        try {
            $module = $this->manager->installFromZip($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', __('backend.module_install_failed', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('backend.module_installed', ['name' => $module->name]));
    }

    public function activate(Module $module): RedirectResponse
    {
        try {
            $this->manager->activate($module);
        } catch (\Throwable $e) {
            return back()->with('error', __('backend.module_activate_failed', ['message' => $e->getMessage()]));
        }

        return back()->with('success', __('backend.module_activated', ['name' => $module->name]));
    }

    public function deactivate(Module $module): RedirectResponse
    {
        $this->manager->deactivate($module);

        return back()->with('success', __('backend.module_deactivated', ['name' => $module->name]));
    }

    public function destroy(Module $module): RedirectResponse
    {
        $name = $module->name;
        $this->manager->delete($module);

        return back()->with('success', __('backend.module_deleted', ['name' => $name]));
    }
}
