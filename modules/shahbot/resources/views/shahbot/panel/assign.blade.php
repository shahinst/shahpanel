@extends('layouts.panel')

@section('page_title', __('shahbot::admin.assign_accounts'))

@section('panel_content')
<div class="sbp-page">
    @include('shahbot::panel._nav', ['panel' => $panel])

    <p class="text-muted">{{ __('shahbot::admin.assign_intro') }}</p>

    @if ($bot === null)
        <x-alert type="warning">{{ __('shahbot::admin.my_bot_needed_first') }}</x-alert>
    @else
        @if ($members->isEmpty())
            <x-alert type="info">{{ __('shahbot::admin.assign_no_members') }}</x-alert>
        @endif

        <datalist id="sb-members">
            @foreach ($members as $m)
                <option value="{{ $m->telegram_id }}">{{ trim($m->first_name.' '.$m->last_name) }}{{ $m->username ? ' @'.$m->username : '' }}</option>
            @endforeach
        </datalist>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4"><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="{{ __('shahbot::admin.assign_search') }}"></div>
            <div class="col-auto"><button class="btn btn-outline-primary"><i class="bx bx-search"></i></button></div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('shahbot::admin.assign_account') }}</th>
                            <th>{{ __('shahbot::admin.assign_current') }}</th>
                            <th style="min-width:300px">{{ __('shahbot::admin.assign_member') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            @php $given = $active->get($account->id); @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold" dir="ltr">{{ $account->remote_username }}</div>
                                    <small class="text-muted">{{ $account->display_label }} · {{ $account->service_type->label() }}
                                        @if ($panel === 'agent' && $account->ownerSeller) · {{ $account->ownerSeller->full_name ?: $account->ownerSeller->username }} @endif
                                    </small>
                                </td>
                                <td>
                                    @if ($given)
                                        <span class="badge bg-success-subtle text-success"><i class="bx bxl-telegram"></i>
                                            {{ $given->botUser?->displayName() }} <span dir="ltr">({{ $given->botUser?->telegram_id }})</span></span>
                                    @else
                                        <span class="text-muted">{{ $account->clientUser?->full_name ?: $account->clientUser?->username ?: '—' }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($given)
                                        <form method="POST" action="{{ route($panel.'.shahbot.assign.destroy', $given) }}" data-confirm="{{ __('shahbot::admin.assign_revoke_confirm') }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger"><i class="bx bx-undo"></i> {{ __('shahbot::admin.assign_revoke') }}</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route($panel.'.shahbot.assign.store', $account) }}" class="d-flex gap-2">
                                            @csrf
                                            <input type="text" name="member" list="sb-members" required maxlength="64" dir="ltr" class="form-control form-control-sm" placeholder="{{ __('shahbot::admin.assign_member_placeholder') }}">
                                            <button class="btn btn-sm btn-primary text-nowrap"><i class="bx bx-send"></i> {{ __('shahbot::admin.assign_btn') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">{{ __('app.no_results') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $accounts->links() }}</div>
    @endif
</div>
@endsection
