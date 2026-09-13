<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageCategoryController extends Controller
{
    public function index(): RedirectResponse
    {
        $this->authorize('viewAny', PackageCategory::class);

        return redirect()->route('admin.packages.index', ['tab' => 'categories']);
    }

    public function create(): View
    {
        $this->authorize('create', PackageCategory::class);

        return view('admin.package-categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PackageCategory::class);

        $validated = $this->validated($request);

        $category = PackageCategory::query()->create($validated);
        $this->syncPackagesWithCategoryState($category, (bool) $category->is_active);

        return redirect()
            ->route('admin.packages.index', ['tab' => 'categories'])
            ->with('success', __('app.saved'));
    }

    public function edit(PackageCategory $packageCategory): View
    {
        $this->authorize('update', $packageCategory);

        return view('admin.package-categories.edit', ['category' => $packageCategory]);
    }

    public function update(Request $request, PackageCategory $packageCategory): RedirectResponse
    {
        $this->authorize('update', $packageCategory);

        $validated = $this->validated($request);
        $wasActive = (bool) $packageCategory->is_active;

        $packageCategory->update($validated);
        $packageCategory->refresh();

        if ($wasActive && ! $packageCategory->is_active) {
            $this->syncPackagesWithCategoryState($packageCategory, false);
        }

        return redirect()
            ->route('admin.packages.index', ['tab' => 'categories'])
            ->with('success', __('app.saved'));
    }

    public function toggleActive(PackageCategory $packageCategory): RedirectResponse
    {
        $this->authorize('update', $packageCategory);

        $next = ! $packageCategory->is_active;
        $packageCategory->update(['is_active' => $next]);

        if (! $next) {
            $this->syncPackagesWithCategoryState($packageCategory, false);
        }

        return redirect()
            ->route('admin.packages.index', ['tab' => 'categories'])
            ->with('success', $next ? __('packages.category_activated') : __('packages.category_deactivated'));
    }

    public function destroy(PackageCategory $packageCategory): RedirectResponse
    {
        $this->authorize('delete', $packageCategory);

        $packageCategory->delete();

        return redirect()
            ->route('admin.packages.index', ['tab' => 'categories'])
            ->with('success', __('app.deleted'));
    }

    protected function syncPackagesWithCategoryState(PackageCategory $category, bool $isActive): void
    {
        if ($isActive) {
            return;
        }

        $category->packages()->update(['is_active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
