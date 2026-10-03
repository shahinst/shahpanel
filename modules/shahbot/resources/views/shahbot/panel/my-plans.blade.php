@extends('layouts.panel')

@section('page_title', __('shahbot::admin.my_plans'))

@php $prefix = $panel.'.shahbot.my-bot'; @endphp

@section('panel_content')
<x-page-header :title="__('shahbot::admin.my_plans')">
    <p class="text-muted mb-0">{{ __('shahbot::admin.my_plans_subtitle') }}</p>
    <a href="{{ route($prefix) }}" class="btn btn-outline-secondary"><i class="bx bx-arrow-back"></i> {{ __('shahbot::admin.my_bot') }}</a>
</x-page-header>

@if (! $enabled)
    <x-alert type="warning">{{ __('shahbot::admin.my_bot_disabled') }}</x-alert>
@elseif ($bot === null)
    <x-alert type="warning">{{ __('shahbot::admin.my_bot_needed_first') }}</x-alert>
@elseif ($rows->isEmpty())
    <x-alert type="warning">{{ __('shahbot::admin.my_plans_empty') }}</x-alert>
@else
    <form method="POST" action="{{ route($prefix.'.plans.save') }}">
        @csrf
        <div class="card">
            <div class="card-body">
                <p class="text-muted">{{ __('shahbot::admin.my_plans_hint') }}</p>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width:1%">{{ __('shahbot::admin.plan_shown') }}</th>
                                <th>{{ __('shahbot::admin.plan') }}</th>
                                <th>{{ __('shahbot::admin.plan_my_price') }}</th>
                                <th>{{ __('shahbot::admin.plan_bot_price') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $id = (int) $row['duration']->id;
                                    $pick = $own->get($id);
                                    $shown = old('enabled.'.$id, $pick === null ? true : $pick->is_enabled);
                                @endphp
                                <tr>
                                    <td>
                                        <input type="hidden" name="enabled[{{ $id }}]" value="0">
                                        <input type="checkbox" class="form-check-input" name="enabled[{{ $id }}]" value="1" @checked($shown)>
                                    </td>
                                    <td>
                                        {{ $row['package']->name }}
                                        <span class="text-muted">· {{ $row['duration']->tier->label() }}</span>
                                    </td>
                                    <td class="text-muted" dir="ltr">{{ format_money($row['display_price']) }}</td>
                                    <td style="max-width:12rem">
                                        <input type="number" step="0.01" min="{{ $row['display_price'] }}" dir="ltr"
                                               name="prices[{{ $id }}]"
                                               value="{{ old('prices.'.$id, $pick?->display_price) }}"
                                               placeholder="{{ __('shahbot::admin.plan_price_same') }}"
                                               class="form-control form-control-sm">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-primary"><i class="bx bx-save"></i> {{ __('shahbot::admin.save') }}</button>
            </div>
        </div>
    </form>
@endif
@endsection
