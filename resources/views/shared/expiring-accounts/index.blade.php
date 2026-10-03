@extends('layouts.panel')

@section('page_title', __('expiring.title'))

@php
    use App\Http\Controllers\ExpiringAccountsController;
    $routeBase = $panel.'.accounts.expiring';
    $query = fn (array $override = []) => array_filter(array_merge(request()->only(['days', 'category', 'agent_id', 'seller_id', 'search', 'auto_renew']), $override), fn ($v) => $v !== null && $v !== '');
    $categoryColors = ['wireguard' => '#10b981', 'ppp' => '#3b82f6', 'v2ray' => '#f59e0b', 'anyconnect' => '#8b5cf6'];
@endphp

@section('panel_content')
<div class="exp" dir="{{ locale_dir() }}">
    <section class="exp-head">
        <div>
            <h1><i class="bx bx-alarm-exclamation"></i> {{ __('expiring.title') }}</h1>
            <p>{{ __('expiring.subtitle_'.$panel) }}</p>
        </div>
        <a href="{{ route($routeBase.'.export', $query()) }}" class="exp-export"><i class="bx bx-download"></i> {{ __('expiring.export') }}</a>
    </section>

    {{-- Day strip: how many expire on each of the coming days --}}
    <section class="exp-days" aria-label="{{ __('expiring.by_day') }}">
        @foreach ($summary['days'] as $day)
            <a href="{{ route($routeBase, $query(['days' => max(1, $day['day'])])) }}" @class(['exp-day', 'is-in' => $day['day'] <= $filters['days'], 'is-hot' => $day['day'] <= 1 && $day['count'] > 0])>
                <span class="exp-day__label">{{ $day['label'] }}</span>
                <strong>{{ persian_digits($day['count']) }}</strong>
            </a>
        @endforeach
    </section>

    <section class="exp-stats">
        <div class="exp-stat"><i class="bx bx-list-ul" style="--tone:#6366f1"></i><div><strong>{{ persian_digits(number_format($summary['total'])) }}</strong><span>{{ __('expiring.stat_total', ['days' => persian_digits($filters['days'])]) }}</span></div></div>
        <div class="exp-stat"><i class="bx bx-time-five" style="--tone:#ef4444"></i><div><strong>{{ persian_digits(number_format($summary['within_24h'])) }}</strong><span>{{ __('expiring.stat_24h') }}</span></div></div>
        <div class="exp-stat"><i class="bx bx-revision" style="--tone:#10b981"></i><div><strong>{{ persian_digits(number_format($summary['auto_renew'])) }}</strong><span>{{ __('expiring.stat_auto_renew') }}</span></div></div>
        <div class="exp-stat exp-stat--mix">
            @foreach ($categories as $category)
                <span class="exp-chip" style="--tone: {{ $categoryColors[$category->value] ?? '#64748b' }}">{{ $category->label() }} <b>{{ persian_digits($summary['by_category'][$category->value] ?? 0) }}</b></span>
            @endforeach
            <small>{{ __('expiring.next_7_days') }}</small>
        </div>
    </section>

    {{-- Filters --}}
    <form method="GET" class="exp-filters">
        <div class="exp-field exp-field--days">
            <label>{{ __('expiring.period') }}</label>
            <div class="exp-seg">
                @for ($d = 1; $d <= ExpiringAccountsController::MAX_DAYS; $d++)
                    <label><input type="radio" name="days" value="{{ $d }}" @checked($filters['days'] === $d) onchange="this.form.submit()"><span>{{ persian_digits($d) }}</span></label>
                @endfor
                <em>{{ __('expiring.days_unit') }}</em>
            </div>
        </div>
        <div class="exp-field">
            <label for="exp-category">{{ __('expiring.type') }}</label>
            <select name="category" id="exp-category" class="form-select" onchange="this.form.submit()">
                <option value="">{{ __('expiring.all_types') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}" @selected($filters['category'] === $category->value)>{{ $category->label() }}</option>
                @endforeach
            </select>
        </div>
        @if ($panel === 'admin')
            <div class="exp-field">
                <label for="exp-agent">{{ __('expiring.col_agent') }}</label>
                <select name="agent_id" id="exp-agent" class="form-select" onchange="this.form.seller_id && (this.form.seller_id.value = ''); this.form.submit()">
                    <option value="">{{ __('expiring.all_agents') }}</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}" @selected($filters['agent_id'] === $agent->id)>{{ $agent->full_name ?: $agent->username }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($panel !== 'seller')
            <div class="exp-field">
                <label for="exp-seller">{{ __('expiring.col_seller') }}</label>
                <select name="seller_id" id="exp-seller" class="form-select" onchange="this.form.submit()">
                    <option value="">{{ __('expiring.all_sellers') }}</option>
                    @if ($panel === 'agent')
                        <option value="{{ auth()->id() }}" @selected($filters['seller_id'] === auth()->id())>{{ __('expiring.my_own') }}</option>
                    @endif
                    @foreach ($sellers as $seller)
                        <option value="{{ $seller->id }}" @selected($filters['seller_id'] === $seller->id)>{{ $seller->full_name ?: $seller->username }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="exp-field">
            <label for="exp-auto">{{ __('expiring.col_auto_renew') }}</label>
            <select name="auto_renew" id="exp-auto" class="form-select" onchange="this.form.submit()">
                <option value="">{{ __('expiring.any') }}</option>
                <option value="1" @selected($filters['auto_renew'] === '1')>{{ __('expiring.auto_on') }}</option>
                <option value="0" @selected($filters['auto_renew'] === '0')>{{ __('expiring.auto_off') }}</option>
            </select>
        </div>
        <div class="exp-field exp-field--search">
            <label for="exp-search">{{ __('app.search') }}</label>
            <div class="d-flex gap-2">
                <input type="search" name="search" id="exp-search" class="form-control" value="{{ $filters['search'] }}" placeholder="{{ __('expiring.search_placeholder') }}">
                <button type="submit" class="btn btn-primary"><i class="bx bx-search"></i></button>
                @if (count($query()) > 0)
                    <a href="{{ route($routeBase) }}" class="btn btn-light" title="{{ __('app.cancel') }}"><i class="bx bx-x"></i></a>
                @endif
            </div>
        </div>
    </form>

    {{-- List --}}
    <section class="exp-card">
        <div class="table-responsive">
            <table class="table exp-table mb-0">
                <thead>
                    <tr>
                        <th>{{ __('expiring.col_account') }}</th>
                        <th>{{ __('expiring.col_service') }}</th>
                        <th>{{ __('expiring.col_package') }}</th>
                        @if ($panel !== 'seller')<th>{{ __('expiring.col_owner') }}</th>@endif
                        <th>{{ __('expiring.col_usage') }}</th>
                        <th>{{ __('expiring.col_expiry') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accounts as $account)
                        @php
                            $hoursLeft = now()->diffInHours($account->expiry_at, false);
                            $urgency = $hoursLeft <= 24 ? 'hot' : ($hoursLeft <= 72 ? 'warm' : 'cool');
                            $limit = (int) $account->data_limit_bytes;
                            $usedPct = $limit > 0 ? min(100, round($account->data_used_bytes / $limit * 100)) : null;
                            $category = $account->service_type->accountCategory()->value;
                            $client = $account->clientUser;
                        @endphp
                        <tr>
                            <td>
                                <div class="exp-acc">
                                    <span class="exp-acc__dot" style="background: {{ $categoryColors[$category] ?? '#94a3b8' }}"></span>
                                    <div>
                                        <strong dir="ltr">{{ $account->remote_username }}</strong>
                                        @if ($client)
                                            <div class="exp-sub">{{ $client->full_name ?: $client->username }}@if ($client->phone) · <span dir="ltr">{{ persian_digits($client->phone) }}</span>@endif</div>
                                        @elseif ($account->display_label)
                                            <div class="exp-sub">{{ $account->display_label }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="exp-chip" style="--tone: {{ $categoryColors[$category] ?? '#64748b' }}">{{ $account->service_type->label() }}</span>
                                <div class="exp-sub">{{ $account->server?->name ?? '—' }}</div>
                            </td>
                            <td>{{ $account->package?->name ?? '—' }}</td>
                            @if ($panel !== 'seller')
                                <td>
                                    {{ $account->ownerSeller?->full_name ?: ($account->ownerSeller?->username ?? '—') }}
                                    @if ($panel === 'admin' && $account->ownerAgent && $account->owner_agent_id !== $account->owner_seller_id)
                                        <div class="exp-sub"><i class="bx bx-user-pin"></i> {{ $account->ownerAgent->full_name ?: $account->ownerAgent->username }}</div>
                                    @endif
                                </td>
                            @endif
                            <td style="min-width: 150px;">
                                <div class="exp-sub" dir="ltr">{{ persian_digits(format_data_size((int) $account->data_used_bytes)) }} / {{ $limit > 0 ? persian_digits(format_data_size($limit)) : '∞' }}</div>
                                @if ($usedPct !== null)
                                    <div class="exp-meter"><span style="width: {{ $usedPct }}%; background: {{ $usedPct >= 90 ? '#ef4444' : ($usedPct >= 70 ? '#f59e0b' : '#22c55e') }}"></span></div>
                                @endif
                            </td>
                            <td>
                                <span class="exp-left exp-left--{{ $urgency }}"><i class="bx bx-time"></i> {{ ExpiringAccountsController::timeLeft($account->expiry_at) }}</span>
                                <div class="exp-sub">{{ jalali_date($account->expiry_at, 'Y/m/d H:i') }}</div>
                                @if ($account->auto_renew)<div class="exp-auto"><i class="bx bx-revision"></i> {{ __('expiring.auto_on') }}</div>@endif
                            </td>
                            <td class="text-nowrap">
                                <div class="icon-actions">
                                    @if (Route::has($panel.'.accounts.renew-form'))
                                        <x-icon-action icon="bx-refresh" variant="primary" :label="__('menu.renew')" :href="route($panel.'.accounts.renew-form', $account)" />
                                    @endif
                                    @if (Route::has($panel.'.accounts.show'))
                                        <x-icon-action icon="bx-show" :label="__('app.view')" :href="route($panel.'.accounts.show', $account)" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="exp-empty"><i class="bx bx-check-shield"></i>{{ __('expiring.empty', ['days' => persian_digits($filters['days'])]) }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($accounts->hasPages())
            <div class="p-3">{{ $accounts->links() }}</div>
        @endif
    </section>
</div>
@endsection

@push('styles')
<style>
    .exp { display: flex; flex-direction: column; gap: 16px; }
    .exp-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; padding: 20px 22px; border-radius: 20px; color: #fff; background: linear-gradient(135deg, #f97316, #e11d48 60%, #9f1239); box-shadow: 0 18px 40px -24px rgba(225,29,72,.8); }
    .exp-head h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
    .exp-head p { margin: 0; opacity: .9; font-size: .9rem; }
    .exp-export { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 12px; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3); color: #fff; text-decoration: none; font-weight: 600; font-size: .86rem; }
    .exp-export:hover { background: rgba(255,255,255,.28); color: #fff; }

    .exp-days { display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 8px; }
    .exp-day { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 10px 4px; border-radius: 14px; background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); color: var(--muted, #64748b); text-decoration: none; transition: transform .12s, border-color .12s; }
    .exp-day:hover { transform: translateY(-2px); border-color: #fb923c; color: var(--text, #0f172a); }
    .exp-day strong { font-size: 1.25rem; color: var(--text, #0f172a); }
    .exp-day__label { font-size: .75rem; }
    .exp-day.is-in { background: #fff7ed; border-color: #fed7aa; }
    .exp-day.is-hot { background: #fef2f2; border-color: #fecaca; }
    .exp-day.is-hot strong { color: #dc2626; }

    .exp-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 2fr); gap: 12px; }
    .exp-stat { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); }
    .exp-stat > i { width: 40px; height: 40px; flex: none; display: grid; place-items: center; border-radius: 12px; font-size: 1.25rem; color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, transparent); }
    .exp-stat strong { display: block; font-size: 1.2rem; color: var(--text, #0f172a); }
    .exp-stat span { font-size: .78rem; color: var(--muted, #64748b); }
    .exp-stat--mix { flex-wrap: wrap; gap: 6px; }
    .exp-stat--mix small { width: 100%; color: var(--muted-2, #94a3b8); font-size: .72rem; }
    .exp-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: .78rem; font-weight: 600; color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, transparent); }
    .exp-chip b { color: var(--text, #0f172a); }

    .exp-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; padding: 14px 16px; border-radius: 16px; background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); }
    .exp-field { display: flex; flex-direction: column; gap: 4px; min-width: 150px; flex: 1; }
    .exp-field > label { font-size: .76rem; color: var(--muted, #64748b); font-weight: 600; margin: 0; }
    .exp-field--days { flex: 0 0 auto; }
    .exp-field--search { flex: 2; min-width: 220px; }
    .exp-seg { display: inline-flex; align-items: center; gap: 4px; background: var(--surface-3, #f1f5f9); padding: 4px; border-radius: 12px; }
    .exp-seg label { margin: 0; cursor: pointer; }
    .exp-seg input { position: absolute; opacity: 0; pointer-events: none; }
    .exp-seg span { display: grid; place-items: center; width: 34px; height: 32px; border-radius: 9px; font-weight: 700; color: var(--muted, #64748b); transition: background .12s; }
    .exp-seg input:checked + span { background: #f97316; color: #fff; box-shadow: 0 4px 10px -4px rgba(249,115,22,.7); }
    .exp-seg input:focus-visible + span { outline: 2px solid #f97316; outline-offset: 2px; }
    .exp-seg em { font-style: normal; font-size: .78rem; color: var(--muted, #64748b); padding: 0 6px; }

    .exp-card { background: var(--surface, #fff); border: 1px solid var(--border, #e6e9ef); border-radius: 18px; overflow: hidden; }
    .exp-table th { font-size: .78rem; color: var(--muted, #64748b); font-weight: 700; background: var(--surface-2, #f8fafc); white-space: nowrap; }
    .exp-table td { vertical-align: middle; font-size: .86rem; }
    .exp-acc { display: flex; align-items: center; gap: 10px; }
    .exp-acc__dot { width: 8px; height: 34px; border-radius: 4px; flex: none; }
    .exp-sub { font-size: .75rem; color: var(--muted, #64748b); margin-top: 2px; }
    .exp-meter { height: 6px; border-radius: 99px; background: #eef2f7; overflow: hidden; margin-top: 4px; }
    .exp-meter span { display: block; height: 100%; border-radius: inherit; }
    .exp-left { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: .78rem; font-weight: 700; }
    .exp-left--hot { background: #fee2e2; color: #b91c1c; }
    .exp-left--warm { background: #ffedd5; color: #c2410c; }
    .exp-left--cool { background: #e0f2fe; color: #0369a1; }
    .exp-auto { font-size: .72rem; color: #15803d; margin-top: 2px; }
    .exp-empty { text-align: center; color: var(--muted, #64748b); padding: 40px 0 !important; }
    .exp-empty i { display: block; font-size: 2rem; color: #22c55e; margin-bottom: 6px; }

    @media (max-width: 1199px) { .exp-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } .exp-stat--mix { grid-column: 1 / -1; } }
    @media (max-width: 767px) {
        .exp-days { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .exp-stats { grid-template-columns: 1fr 1fr; }
        .exp-field { min-width: 100%; }
    }
</style>
@endpush
