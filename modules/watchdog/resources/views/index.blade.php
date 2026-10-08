@extends('layouts.panel')

@section('page_title', __('watchdog::watchdog.page_title'))

@section('panel_content')
@if (session('success'))
    <x-alert type="success" class="margin-bottom">{{ session('success') }}</x-alert>
@endif
@unless ($telegramReady)
    <x-alert type="warning" class="margin-bottom">{{ __('watchdog::watchdog.telegram_missing') }}</x-alert>
@endunless

<x-card>
    <p class="text-muted">{{ __('watchdog::watchdog.intro') }}</p>

    <form method="POST" action="{{ route('admin.watchdog.run') }}" class="margin-bottom">
        @csrf
        <x-button type="submit" variant="secondary">{{ __('watchdog::watchdog.run_now') }}</x-button>
        @if ($lastRun)
            <span class="text-muted">{{ __('watchdog::watchdog.last_run', ['time' => date('Y-m-d H:i', $lastRun['at'])]) }}</span>
        @endif
    </form>

    @if ($lastRun)
        <div class="table-responsive">
            <table class="table">
                <tbody>
                    @foreach ($lastRun['checks'] as $check)
                        <tr>
                            <td>{{ $check['ok'] ? '✅' : '⚠️' }}</td>
                            <td>{{ $check['label'] }}</td>
                            <td class="text-muted">{{ $check['ok'] ? '' : $check['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>

<x-card class="mt-3">
    <form method="POST" action="{{ route('admin.watchdog.update') }}">
        @csrf
        <div class="row">
            @foreach ($thresholds as $key => $value)
                <div class="col-md-4">
                    <x-form.group label="{{ __('watchdog::watchdog.'.$key) }}">
                        <input type="number" name="{{ $key }}" value="{{ old($key, $value) }}" class="form-control" dir="ltr" required>
                    </x-form.group>
                </div>
            @endforeach
        </div>
        <x-button type="submit">{{ __('app.save') }}</x-button>
    </form>
</x-card>
@endsection
