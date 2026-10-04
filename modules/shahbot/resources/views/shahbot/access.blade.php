@extends('layouts.panel')

@section('page_title', __('shahbot::admin.bot_access'))

@section('panel_content')
    <x-page-header :title="__('shahbot::admin.bot_access')">
        <a href="{{ $panel === 'admin' ? route('admin.shahbot.index') : route('agent.shahbot.my-bot') }}" class="btn btn-outline-secondary"><i class="bx bx-arrow-back"></i> {{ __('app.back') }}</a>
        <p class="text-muted mb-0">{{ __($panel === 'admin' ? 'shahbot::admin.bot_access_admin_hint' : 'shahbot::admin.bot_access_agent_hint') }}</p>
    </x-page-header>

    @if ($locked)
        <x-alert type="warning">{{ __('shahbot::admin.bot_access_locked') }}</x-alert>
    @elseif ($users->isEmpty())
        <x-alert type="info">{{ __('shahbot::admin.bot_access_empty') }}</x-alert>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('shahbot::admin.bot_access_user') }}</th>
                            @if ($panel === 'admin')
                                <th>{{ __('shahbot::admin.bot_access_parent') }}</th>
                            @endif
                            <th class="text-end">{{ __('shahbot::admin.bot_access') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            @php $on = $granted->has($user->id); @endphp
                            <tr>
                                <td>{{ $user->full_name ?: $user->username }} <span class="text-muted">({{ $user->username }}) — {{ $user->role->label() }}</span></td>
                                @if ($panel === 'admin')
                                    <td class="text-muted">{{ $user->parent?->full_name ?: ($user->parent?->username ?? '—') }}</td>
                                @endif
                                <td class="text-end">
                                    <form method="POST" action="{{ $action }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $user->id }}">
                                        <input type="hidden" name="on" value="{{ $on ? 0 : 1 }}">
                                        <button type="submit" class="btn btn-sm {{ $on ? 'btn-success' : 'btn-outline-secondary' }}">
                                            <i class="bx {{ $on ? 'bx-check' : 'bx-x' }}"></i>
                                            {{ __($on ? 'shahbot::admin.bot_access_on' : 'shahbot::admin.bot_access_off') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
