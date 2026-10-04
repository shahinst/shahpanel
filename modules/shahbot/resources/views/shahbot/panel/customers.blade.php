@extends('layouts.panel')

@section('page_title', __('shahbot::admin.customers'))

@section('panel_content')
<div class="sbp-page">
    @include('shahbot::panel._nav', ['panel' => $panel])

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3"><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ __('shahbot::admin.customers_search') }}"></div>
        @if ($owners->count() > 1)
            <div class="col-md-3">
                <select name="owner" class="form-select">
                    <option value="">{{ __('shahbot::admin.customers_all_bots') }}</option>
                    @foreach ($owners as $o)
                        <option value="{{ $o->id }}" @selected((int) request('owner') === $o->id)>{{ $o->full_name ?: $o->username }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="col-md-2">
            <select name="bought" class="form-select">
                <option value="">{{ __('shahbot::admin.customers_everyone') }}</option>
                <option value="1" @selected(request('bought') === '1')>{{ __('shahbot::admin.customers_bought') }}</option>
                <option value="0" @selected(request('bought') === '0')>{{ __('shahbot::admin.customers_not_bought') }}</option>
            </select>
        </div>
        <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control" dir="ltr"></div>
        <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control" dir="ltr"></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bx bx-search"></i> {{ __('app.search') }}</button></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>{{ __('shahbot::admin.customers_name') }}</th>
                    <th>Telegram</th>
                    <th>{{ __('shahbot::admin.customers_bot') }}</th>
                    <th>{{ __('shahbot::admin.customers_joined') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td>{{ $u->displayName() }}</td>
                            <td dir="ltr">{{ $u->username ? '@'.$u->username : '' }} <code>{{ $u->telegram_id }}</code></td>
                            <td>{{ $u->bot?->owner?->full_name ?: $u->bot?->owner?->username }}</td>
                            <td>{{ jalali_date($u->created_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{ __('app.no_results') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $users->links() }}</div>
</div>
@endsection
