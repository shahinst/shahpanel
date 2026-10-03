@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_broadcasts'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-box">
    <header>{{ __('shahbot::admin.broadcast_new') }}</header>
    <div class="sb-body">
        <form method="POST" action="{{ route('admin.shahbot.broadcasts.store') }}" data-confirm="{{ __('shahbot::admin.send') }}?">
            @csrf
            <label class="form-label">{{ __('shahbot::admin.broadcast_text') }}</label>
            <textarea name="text" rows="5" maxlength="3500" class="form-control mb-2" required>{{ old('text') }}</textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select name="audience" class="form-select" style="max-width:300px">
                    @foreach ($audiences as $key => $count)
                        <option value="{{ $key }}">{{ __('shahbot::admin.audience_'.$key) }} ({{ persian_digits($count) }})</option>
                    @endforeach
                </select>
                <button class="btn btn-primary"><i class="bx bx-send"></i> {{ __('shahbot::admin.send') }}</button>
                <span class="sb-muted">{{ __('shahbot::admin.broadcast_hint') }}</span>
            </div>
        </form>
    </div>
</div>

<div class="sb-box">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('shahbot::admin.broadcast_text') }}</th>
                    <th>{{ __('shahbot::admin.audience') }}</th>
                    <th>{{ __('shahbot::admin.col_status') }}</th>
                    <th>{{ __('shahbot::admin.col_date') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($broadcasts as $broadcast)
                    <tr>
                        <td>{{ persian_digits($broadcast->id) }}</td>
                        <td style="max-width:380px">{{ \Illuminate\Support\Str::limit(html_entity_decode($broadcast->text), 140) }}</td>
                        <td>{{ __('shahbot::admin.audience_'.$broadcast->audience) }}</td>
                        <td>
                            <span @class(['sb-pill', 'ok' => $broadcast->status === 'done', 'info' => $broadcast->status === 'sending', 'warn' => $broadcast->status === 'queued'])>{{ __('shahbot::admin.broadcast_status_'.$broadcast->status) }}</span>
                            <div class="sb-muted">{{ __('shahbot::admin.broadcast_progress', ['sent' => persian_digits($broadcast->sent), 'total' => persian_digits($broadcast->total), 'failed' => persian_digits($broadcast->failed)]) }}</div>
                        </td>
                        <td>{{ jalali_date($broadcast->created_at, 'Y/m/d H:i') }}<div class="sb-muted">{{ $broadcast->created_by }}</div></td>
                        <td>
                            @if (in_array($broadcast->status, ['queued', 'sending'], true))
                                <x-icon-action icon="bx-stop-circle" variant="danger" :label="__('shahbot::admin.cancel')" :action="route('admin.shahbot.broadcasts.cancel', $broadcast)" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $broadcasts->links() }}
@endsection
