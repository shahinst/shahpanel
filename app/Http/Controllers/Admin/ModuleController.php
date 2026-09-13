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
        ], [], ['module' => 'فایل ماژول']);

        $file = $request->file('module');
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            return back()->with('error', 'فقط فایل با پسوند zip مجاز است.');
        }

        try {
            $module = $this->manager->installFromZip($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'نصب ماژول ناموفق بود: '.$e->getMessage());
        }

        return back()->with('success', 'ماژول «'.$module->name.'» با موفقیت آپلود و نصب شد. برای استفاده آن را فعال کنید.');
    }

    public function activate(Module $module): RedirectResponse
    {
        try {
            $this->manager->activate($module);
        } catch (\Throwable $e) {
            return back()->with('error', 'فعال‌سازی ماژول ناموفق بود: '.$e->getMessage());
        }

        return back()->with('success', 'ماژول «'.$module->name.'» فعال شد.');
    }

    public function deactivate(Module $module): RedirectResponse
    {
        $this->manager->deactivate($module);

        return back()->with('success', 'ماژول «'.$module->name.'» غیرفعال شد.');
    }

    public function destroy(Module $module): RedirectResponse
    {
        $name = $module->name;
        $this->manager->delete($module);

        return back()->with('success', 'ماژول «'.$name.'» به‌طور کامل حذف شد.');
    }
}
