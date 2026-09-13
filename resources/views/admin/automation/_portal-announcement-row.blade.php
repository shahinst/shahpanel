<div class="border rounded p-3" data-announcement-row>
    <input type="hidden" name="announcements[{{ $index }}][id]" value="{{ $row['id'] ?? \Illuminate\Support\Str::uuid() }}">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <strong>{{ __('automation.portal_announcement_box') }} #{{ is_numeric($index) ? $index + 1 : 1 }}</strong>
        <div class="d-flex gap-2 align-items-center">
            <label class="form-check mb-0">
                <input type="checkbox" class="form-check-input" name="announcements[{{ $index }}][enabled]" value="1" @checked($row['enabled'] ?? true)>
                <span class="form-check-label small">{{ __('automation.portal_enabled') }}</span>
            </label>
            <input type="number" class="form-control form-control-sm" style="width:5rem" name="announcements[{{ $index }}][sort]" value="{{ (int) ($row['sort'] ?? 0) }}" min="0">
        </div>
    </div>
    <div class="btn-toolbar gap-1 mb-2" role="toolbar">
        <button type="button" class="btn btn-light btn-sm" data-cmd="bold"><b>B</b></button>
        <button type="button" class="btn btn-light btn-sm" data-cmd="foreColor" data-value="#dc2626">A</button>
        <button type="button" class="btn btn-light btn-sm" data-cmd="foreColor" data-value="#2563eb">A</button>
        <button type="button" class="btn btn-light btn-sm" data-cmd="justifyRight">{{ __('automation.portal_align_right') }}</button>
        <button type="button" class="btn btn-light btn-sm" data-cmd="justifyLeft">{{ __('automation.portal_align_left') }}</button>
        <button type="button" class="btn btn-light btn-sm" data-cmd="justifyCenter">{{ __('automation.portal_align_center') }}</button>
        <button type="button" class="btn btn-light btn-sm" data-cmd="createLink">{{ __('automation.portal_add_link') }}</button>
    </div>
    <div class="form-control portal-editor" data-editor contenteditable="true" style="min-height:5rem">{!! $row['html'] ?? '' !!}</div>
    <input type="hidden" name="announcements[{{ $index }}][html]" data-html-input value="">
</div>
