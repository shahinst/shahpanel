<div class="border rounded p-3">
    <input type="hidden" name="app_categories[{{ $cIndex }}][id]" value="{{ $category['id'] ?? \Illuminate\Support\Str::uuid() }}">
    <div class="row g-2 mb-2">
        <div class="col-md-5">
            <label class="form-label">{{ __('automation.portal_category_name') }}</label>
            <input type="text" class="form-control" name="app_categories[{{ $cIndex }}][name]" value="{{ $category['name'] ?? '' }}" maxlength="120">
        </div>
        <div class="col-md-5">
            <label class="form-label">{{ __('automation.portal_category_icon_url') }}</label>
            <input
                type="url"
                class="form-control"
                name="app_categories[{{ $cIndex }}][icon_source_url]"
                value="{{ $category['icon_source_url'] ?? '' }}"
                placeholder="https://example.com/icon.png"
                dir="ltr"
            >
            <p class="form-text text-muted mb-0">{{ __('automation.portal_category_icon_hint') }}</p>
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('automation.portal_sort') }}</label>
            <input type="number" class="form-control" name="app_categories[{{ $cIndex }}][sort]" value="{{ (int) ($category['sort'] ?? 0) }}" min="0">
            @if (! empty($category['icon_url']))
                <img src="{{ $category['icon_url'] }}" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:contain" class="mt-2">
                <input type="hidden" name="app_categories[{{ $cIndex }}][icon_url]" value="{{ $category['icon_url'] }}">
            @endif
        </div>
    </div>
    <div data-apps-list="{{ $cIndex }}" class="d-grid gap-2">
        @forelse ($category['apps'] ?? [] as $aIndex => $app)
            @include('admin.automation._portal-app-row', ['cIndex' => $cIndex, 'aIndex' => $aIndex, 'app' => $app])
        @empty
        @endforelse
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" data-add-app="{{ $cIndex }}">{{ __('automation.portal_add_app') }}</button>
</div>
