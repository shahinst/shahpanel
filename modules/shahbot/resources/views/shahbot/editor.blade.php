@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_editor'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-box">
    <header>{{ __('shahbot::admin.keyboard_editor') }}</header>
    <div class="sb-body">
        <p class="sb-muted">{{ __('shahbot::admin.keyboard_hint') }}</p>
        <form method="POST" action="{{ route('admin.shahbot.editor.keyboard') }}">
            @csrf
            <div class="table-responsive">
                <table class="table align-middle" style="max-width:720px">
                    <thead><tr><th>{{ __('shahbot::admin.button') }}</th><th style="width:110px">{{ __('shahbot::admin.row') }}</th><th style="width:110px">{{ __('shahbot::admin.position') }}</th><th style="width:90px">{{ __('shahbot::admin.visible') }}</th></tr></thead>
                    <tbody>
                        @foreach ($layout as $key => $entry)
                            <tr>
                                <td>
                                    <b>{{ __('shahbot::admin.btn_'.$key) }}</b>
                                    @php $labelKey = $key === 'agency' ? 'menu_agency' : 'menu_'.$key; @endphp
                                    <div class="sb-muted">{{ $overrides[$labelKey] ?? ($defaults[$labelKey] ?? '') }}</div>
                                </td>
                                <td><input type="number" min="1" max="10" name="layout[{{ $key }}][row]" value="{{ $entry['row'] }}" class="form-control form-control-sm"></td>
                                <td><input type="number" min="1" max="10" name="layout[{{ $key }}][pos]" value="{{ $entry['pos'] }}" class="form-control form-control-sm"></td>
                                <td><input type="checkbox" name="layout[{{ $key }}][on]" value="1" class="form-check-input" @checked($entry['on'])></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('shahbot::admin.save') }}</button>
        </form>
    </div>
</div>

<div class="sb-box">
    <header>
        <span>{{ __('shahbot::admin.texts_editor') }} <span class="sb-pill info">{{ __('shahbot::admin.changed') }}: {{ persian_digits(count($overrides)) }}</span></span>
        <input type="search" id="sb-text-search" class="form-control form-control-sm" style="max-width:260px" placeholder="{{ __('shahbot::admin.texts_search') }}">
    </header>
    <div class="sb-body">
        <p class="sb-muted">{{ __('shahbot::admin.texts_hint') }}</p>
        <form method="POST" action="{{ route('admin.shahbot.editor.texts') }}">
            @csrf
            <div id="sb-texts">
                @foreach ($defaults as $key => $default)
                    @php $value = $overrides[$key] ?? $default; @endphp
                    <div class="mb-3 sb-text-row" data-search="{{ mb_strtolower($key.' '.$value) }}">
                        <label class="form-label d-flex justify-content-between">
                            <code dir="ltr">{{ $key }}</code>
                            @isset($overrides[$key])<span class="sb-pill warn">{{ __('shahbot::admin.changed') }}</span>@endisset
                        </label>
                        <textarea name="texts[{{ $key }}]" rows="{{ min(8, max(1, substr_count($value, "\n") + 1)) }}" class="form-control" dir="auto">{{ $value }}</textarea>
                    </div>
                @endforeach
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('shahbot::admin.save') }}</button>
                <button class="btn btn-outline-danger" name="reset" value="1" data-confirm="{{ __('shahbot::admin.reset_all') }}?">{{ __('shahbot::admin.reset_all') }}</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.getElementById('sb-text-search')?.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.sb-text-row').forEach(function (row) {
            row.style.display = q === '' || row.dataset.search.includes(q) ? '' : 'none';
        });
    });
</script>
@endpush
@endsection
