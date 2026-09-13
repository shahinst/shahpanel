@extends('layouts.panel')

@section('page_title', __('settings.page_title'))

@section('panel_content')
<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    @method('PUT')

    @foreach ($sections as $sectionKey => $sectionTitle)
        @php
            $sectionFields = collect($fields)->filter(fn ($meta) => $meta['section'] === $sectionKey);
        @endphp

        @if ($sectionFields->isNotEmpty())
            <x-card :title="$sectionTitle" class="margin-bottom">
                <div class="row">
                    @foreach ($sectionFields as $key => $meta)
                        @if ($meta['type'] === 'boolean')
                            <x-form.checkbox
                                :name="$key"
                                :label="__('settings.fields.'.$key)"
                                :checked="old($key, ($settings[$key] ?? '0') === '1')"
                                hiddenZero
                                wide
                            />
                        @else
                            <x-form.group
                                :for="'setting_'.$key"
                                :label="__('settings.fields.'.$key)"
                                :hint="\Illuminate\Support\Facades\Lang::has('settings.hints.'.$key) ? __('settings.hints.'.$key) : null"
                            >
                                <input
                                    id="setting_{{ $key }}"
                                    name="{{ $key }}"
                                    type="@if ($meta['type'] === 'number') number @elseif ($meta['type'] === 'color') color @elseif ($meta['type'] === 'datetime') datetime-local @else text @endif"
                                    value="{{ old($key, $settings[$key] ?? '') }}"
                                    class="form-control"
                                    @if ($meta['type'] === 'color') style="height:38px;padding:2px;" @endif
                                    @if ($meta['type'] === 'number') min="0" step="1" @endif
                                >
                            </x-form.group>
                        @endif
                    @endforeach
                </div>
            </x-card>
        @endif
    @endforeach

    <div class="row">
        <x-form.actions>
            <x-button type="submit">{{ __('app.save') }}</x-button>
            <x-button :href="route('admin.settings.logs')" variant="secondary">
                <i class="bx bx-file-find"></i> {{ __('settings.logs_open') }}
            </x-button>
            <x-button :href="route('admin.settings.server-backups.index')" variant="secondary">
                <i class="bx bx-cloud-download"></i> {{ __('server_backups.open') }}
            </x-button>
        </x-form.actions>
    </div>
</form>
@endsection
