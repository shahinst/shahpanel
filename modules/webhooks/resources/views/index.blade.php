@extends('layouts.panel')

@section('page_title', __('webhooks::webhooks.page_title'))

@section('panel_content')
@if (session('success'))
    <x-alert type="success" class="margin-bottom">{{ session('success') }}</x-alert>
@endif
@if ($errors->any())
    <x-alert type="danger" class="margin-bottom">{{ $errors->first() }}</x-alert>
@endif

<x-card>
    <p class="text-muted">{{ __('webhooks::webhooks.intro') }}</p>

    <form method="POST" action="{{ route('admin.webhooks.update') }}">
        @csrf
        <x-form.group label="{{ __('webhooks::webhooks.url') }}">
            <input type="url" name="url" value="{{ old('url', $url) }}" class="form-control" dir="ltr" placeholder="https://example.com/shahpanel-hook">
        </x-form.group>

        <x-form.group label="{{ __('webhooks::webhooks.events') }}">
            @foreach (\Modules\Webhooks\Services\EventWebhookService::EVENTS as $event)
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="events[]" value="{{ $event }}" id="ev-{{ $loop->index }}" @checked(in_array($event, $enabled, true))>
                    <label class="form-check-label" for="ev-{{ $loop->index }}"><code>{{ $event }}</code> {{ __('webhooks::webhooks.event_'.str_replace('.', '_', $event)) }}</label>
                </div>
            @endforeach
        </x-form.group>

        @if ($secret !== '')
            <x-form.group label="{{ __('webhooks::webhooks.secret') }}" hint="{{ __('webhooks::webhooks.secret_hint') }}">
                <input type="text" readonly value="{{ $secret }}" class="form-control" dir="ltr">
                <div class="form-check mt-1">
                    <input type="checkbox" class="form-check-input" name="new_secret" value="1" id="new-secret">
                    <label class="form-check-label" for="new-secret">{{ __('webhooks::webhooks.new_secret') }}</label>
                </div>
            </x-form.group>
        @endif

        <x-button type="submit">{{ __('app.save') }}</x-button>
    </form>

    @if ($url !== '')
        <form method="POST" action="{{ route('admin.webhooks.test') }}" class="mt-2">
            @csrf
            <x-button type="submit" variant="secondary">{{ __('webhooks::webhooks.test') }}</x-button>
        </form>
    @endif
</x-card>

@if ($log !== [])
    <x-card class="mt-3">
        <h6>{{ __('webhooks::webhooks.log') }}</h6>
        <div class="table-responsive">
            <table class="table">
                <tbody>
                    @foreach ($log as $entry)
                        <tr>
                            <td>{{ $entry['ok'] ? '✅' : '⚠️' }}</td>
                            <td><code>{{ $entry['event'] }}</code></td>
                            <td dir="ltr">HTTP {{ $entry['status'] ?: '—' }}</td>
                            <td class="text-muted">{{ date('Y-m-d H:i:s', $entry['at']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
@endif
@endsection
