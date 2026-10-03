@extends('layouts.panel')

@section('page_title', __('shahbot::admin.tab_fun'))

@section('panel_content')
@include('shahbot::_nav')

<div class="sb-box">
    <header>{{ __('shahbot::admin.lottery_new') }}</header>
    <div class="sb-body">
        <p class="sb-muted">{{ __('shahbot::admin.lottery_hint') }}</p>
        <form method="POST" action="{{ route('admin.shahbot.lotteries.store') }}" class="row g-2">
            @csrf
            <div class="col-md-4"><label class="form-label">{{ __('shahbot::admin.lottery_title') }}</label><input type="text" name="title" value="{{ old('title') }}" class="form-control" required maxlength="160"></div>
            <div class="col-md-2"><label class="form-label">{{ __('shahbot::admin.lottery_prize') }}</label><input type="number" name="prize_amount" min="0" step="any" value="{{ old('prize_amount') }}" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">{{ __('shahbot::admin.lottery_winners') }}</label><input type="number" name="winners_count" min="1" max="100" value="{{ old('winners_count', 1) }}" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">{{ __('shahbot::admin.lottery_from') }}</label><input type="text" name="starts_at" value="{{ old('starts_at', jalali_date(now(), 'Y/m/d')) }}" class="form-control" dir="ltr" required></div>
            <div class="col-md-2"><label class="form-label">{{ __('shahbot::admin.lottery_draw') }}</label><input type="text" name="draw_at" value="{{ old('draw_at', jalali_date(now()->addDays(7), 'Y/m/d')) }}" class="form-control" dir="ltr" required></div>
            <div class="col-md-8"><label class="form-label">{{ __('shahbot::admin.lottery_description') }}</label><input type="text" name="description" value="{{ old('description') }}" class="form-control" maxlength="2000"></div>
            <div class="col-md-3">
                <label class="form-label">{{ __('shahbot::admin.bot_col') }}</label>
                <select name="bot_id" class="form-select">
                    <option value="0">{{ __('shahbot::admin.main_bot') }}</option>
                    @foreach ($bots as $bot)
                        <option value="{{ $bot->id }}">{{ $bot->username ? '@'.$bot->username : '#'.$bot->id }} — {{ $bot->owner?->username }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">{{ __('shahbot::admin.save') }}</button></div>
        </form>
    </div>
</div>

<div class="sb-box">
    <header>{{ __('shahbot::admin.lotteries') }}</header>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>#</th><th>{{ __('shahbot::admin.lottery_title') }}</th><th>{{ __('shahbot::admin.lottery_prize') }}</th><th>{{ __('shahbot::admin.lottery_period') }}</th><th>{{ __('shahbot::admin.lottery_tickets') }}</th><th>{{ __('shahbot::admin.col_status') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($lotteries as $lottery)
                    <tr>
                        <td>{{ persian_digits($lottery->id) }}</td>
                        <td><b>{{ $lottery->title }}</b>@if ($lottery->bot_id)<div class="sb-muted">{{ __('shahbot::admin.bot_col') }} #{{ $lottery->bot_id }}</div>@endif</td>
                        <td>{{ format_money($lottery->prize_amount) }} × {{ persian_digits($lottery->winners_count) }}</td>
                        <td>{{ jalali_date($lottery->starts_at, 'Y/m/d') }} → {{ jalali_date($lottery->draw_at, 'Y/m/d H:i') }}</td>
                        <td>{{ $lottery->status === 'open' ? persian_digits($ticketCounts[$lottery->id] ?? 0) : persian_digits($lottery->participants).' '.__('shahbot::admin.lottery_people') }}</td>
                        <td>
                            <span @class(['sb-pill', 'warn' => $lottery->status === 'open', 'ok' => $lottery->status === 'drawn', 'bad' => $lottery->status === 'cancelled'])>{{ __('shahbot::admin.lottery_status_'.$lottery->status) }}</span>
                            @if ($lottery->status === 'drawn')
                                <div class="sb-muted">🏆 {{ collect((array) $lottery->winners)->map(fn ($id) => $winners[$id]?->displayName() ?? '#'.$id)->implode('، ') ?: '—' }}</div>
                            @endif
                        </td>
                        <td class="d-flex gap-1">
                            @if ($lottery->status === 'open')
                                <x-icon-action icon="bx-trophy" variant="success" :label="__('shahbot::admin.lottery_draw_now')" :action="route('admin.shahbot.lotteries.draw', $lottery)" :confirm="__('shahbot::admin.lottery_draw_now').'?'" />
                                <x-icon-action icon="bx-x" variant="danger" :label="__('shahbot::admin.cancel')" :action="route('admin.shahbot.lotteries.cancel', $lottery)" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $lotteries->links() }}

<div class="sb-box">
    <header>{{ __('shahbot::admin.wheel_recent') }}</header>
    <div class="table-responsive">
        <table class="table align-middle">
            <tbody>
                @forelse ($spins as $spin)
                    <tr>
                        <td>@if ($spin->botUser)<a href="{{ route('admin.shahbot.users.show', $spin->botUser) }}">{{ $spin->botUser->displayName() }}</a>@endif</td>
                        <td>{{ $spin->prize_label }} @if ($spin->code)<code dir="ltr">{{ $spin->code }}</code>@endif</td>
                        <td>{{ jalali_date($spin->created_at, 'Y/m/d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
