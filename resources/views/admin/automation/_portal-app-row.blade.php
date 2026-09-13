<div class="row g-2 align-items-end" data-app-row>
    <input type="hidden" name="app_categories[{{ $cIndex }}][apps][{{ $aIndex }}][id]" value="{{ $app['id'] ?? \Illuminate\Support\Str::uuid() }}">
    <div class="col-md-4">
        <label class="form-label">{{ __('automation.portal_app_name') }}</label>
        <input type="text" class="form-control" name="app_categories[{{ $cIndex }}][apps][{{ $aIndex }}][name]" value="{{ $app['name'] ?? '' }}" maxlength="120">
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('automation.portal_app_url') }}</label>
        <input type="url" class="form-control" name="app_categories[{{ $cIndex }}][apps][{{ $aIndex }}][url]" value="{{ $app['url'] ?? '' }}" placeholder="https://play.google.com/..." dir="ltr">
    </div>
    <div class="col-md-2">
        @if (! empty($app['icon_url']))
            <img src="{{ $app['icon_url'] }}" alt="" style="width:40px;height:40px;border-radius:8px">
            <input type="hidden" name="app_categories[{{ $cIndex }}][apps][{{ $aIndex }}][icon_url]" value="{{ $app['icon_url'] }}">
        @endif
    </div>
</div>
