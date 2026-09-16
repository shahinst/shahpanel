@extends('layouts.panel')

@php
    use Illuminate\Support\Facades\Route;

    $categoryService = app(\App\Services\PackageCategoryService::class);
    $categoriesAvailable = $categoryService->isAvailable();
    $categoryRoutesReady = Route::has('admin.package-categories.create')
        && Route::has('admin.package-categories.edit')
        && Route::has('admin.package-categories.destroy');
    $activeTab = request('tab') === 'categories' ? 'categories' : 'packages';
    $managedCategories = $managedCategories ?? collect();
@endphp

@section('page_title', __('menu.packages'))

@section('page_actions')
    @if ($activeTab === 'categories' && $categoryRoutesReady)
        <x-button :href="route('admin.package-categories.create')" variant="secondary" size="sm">
            <i class="bx bx-plus align-middle"></i> {{ __('packages.category_create') }}
        </x-button>
    @elseif ($activeTab !== 'categories')
        <x-button :href="route('admin.packages.create')" size="sm">
            <i class="bx bx-plus align-middle"></i> {{ __('packages.create') }}
        </x-button>
    @endif
@endsection

@section('panel_content')
<x-page-header :title="__('menu.packages')">
    <x-slot:actions>
        @if ($activeTab === 'categories' && $categoryRoutesReady)
            <x-button :href="route('admin.package-categories.create')" variant="secondary">
                <i class="bx bx-plus align-middle"></i> {{ __('packages.category_create') }}
            </x-button>
        @elseif ($activeTab !== 'categories')
            <x-button :href="route('admin.packages.create')">
                <i class="bx bx-plus align-middle"></i> {{ __('packages.create') }}
            </x-button>
        @endif
    </x-slot:actions>
</x-page-header>

<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link @if ($activeTab === 'packages') active @endif"
           href="{{ route('admin.packages.index', request()->only('category_id')) }}">
            <i class="bx bx-package align-middle"></i> {{ __('menu.packages') }}
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link @if ($activeTab === 'categories') active @endif"
           href="{{ route('admin.packages.index', ['tab' => 'categories']) }}">
            <i class="bx bx-category align-middle"></i> {{ __('menu.package_categories') }}
        </a>
    </li>
</ul>

@if ($activeTab === 'categories')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted small mb-3">{{ __('packages.category_admin_only_hint') }}</p>

                    @unless ($categoriesAvailable)
                        <x-alert type="warning" class="mb-3">
                            {{ __('packages.category_migration_required') }}
                        </x-alert>
                    @endunless

                    @unless ($categoryRoutesReady)
                        <x-alert type="danger" class="mb-3">
                            {{ __('packages.category_routes_missing') }}
                        </x-alert>
                    @endunless

                    <x-table :headers="[__('packages.category_name'), __('packages.category_sort_order'), __('packages.packages_count'), __('app.status'), __('app.actions')]">
                        @forelse ($managedCategories as $category)
                            <tr>
                                <td>{{ $category->name }}</td>
                                <td>{{ persian_digits($category->sort_order) }}</td>
                                <td>{{ persian_digits($category->packages_count) }}</td>
                                <td>
                                    @if ($category->is_active)
                                        <span class="badge bg-success">{{ __('app.active') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('app.inactive') }}</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($categoryRoutesReady)
                                        @if (Route::has('admin.package-categories.toggle-active'))
                                            <form method="POST" action="{{ route('admin.package-categories.toggle-active', $category) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-outline-{{ $category->is_active ? 'warning' : 'success' }}">
                                                    {{ $category->is_active ? __('packages.deactivate') : __('packages.activate') }}
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('admin.package-categories.edit', $category) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
                                        <form method="POST" action="{{ route('admin.package-categories.destroy', $category) }}" class="d-inline"
                                              onsubmit="return confirm(@json(__('packages.category_delete_confirm')))">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.delete') }}</button>
                                        </form>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    {{ __('app.no_results') }}
                                    @if ($categoryRoutesReady && $categoriesAvailable)
                                        — <a href="{{ route('admin.package-categories.create') }}">{{ __('packages.category_create') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>
            </div>
        </div>
    </div>
@else
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    @if (($categories ?? collect())->isNotEmpty())
                        <form method="GET" class="row g-2 mb-3">
                            <div class="col-md-4">
                                <select name="category_id" class="form-control" onchange="this.form.submit()">
                                    <option value="">{{ __('packages.all_categories') }}</option>
                                    <option value="0" @selected(request('category_id') === '0')>{{ __('packages.uncategorized') }}</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </form>
                    @endif

                    @php
                        $tableHeaders = ['نام', 'نوع سرویس', 'قیمت از', 'سرورها', 'وضعیت', __('app.actions')];
                        if (($categories ?? collect())->isNotEmpty()) {
                            array_splice($tableHeaders, 1, 0, [__('packages.category')]);
                        }
                    @endphp

                    <x-table :headers="$tableHeaders">
                        @forelse ($packages as $package)
                            <tr>
                                <td>{{ $package->name }}</td>
                                @if (($categories ?? collect())->isNotEmpty())
                                    <td>{{ $package->category?->name ?? __('packages.uncategorized') }}</td>
                                @endif
                                <td>{{ $package->service_type->value }}</td>
                                <td>
                                    @if ($price = $package->lowestEnabledPrice())
                                        {{ __('packages.from_price', ['price' => format_money($price, $package->moneyCurrency())]) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ persian_digits($package->servers->count()) }}</td>
                                <td>
                                    @if ($package->is_active)
                                        <span class="badge bg-success">{{ __('app.active') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('app.inactive') }}</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if (Route::has('admin.packages.toggle-active'))
                                        <form method="POST" action="{{ route('admin.packages.toggle-active', $package) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-{{ $package->is_active ? 'warning' : 'success' }}">
                                                {{ $package->is_active ? __('packages.deactivate') : __('packages.activate') }}
                                            </button>
                                        </form>
                                    @endif
                                    <a href="{{ route('admin.packages.edit', $package) }}" class="btn btn-sm btn-light">{{ __('app.edit') }}</a>
                                    @can('delete', $package)
                                        @if (Route::has('admin.packages.destroy'))
                                            <form method="POST" action="{{ route('admin.packages.destroy', $package) }}" class="d-inline"
                                                  onsubmit="return confirm(@json(__('packages.delete_confirm')))">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.delete') }}</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($tableHeaders) }}" class="text-center text-muted py-4">
                                    {{ __('app.no_results') }}
                                    — <a href="{{ route('admin.packages.create') }}">{{ __('packages.create') }}</a>
                                </td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>
                @if ($packages->hasPages())
                    <div class="card-footer">{{ $packages->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endif
@endsection
