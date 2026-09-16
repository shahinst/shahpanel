@if ($account->expiry_at)
    <span @class(['d-block', 'text-danger fw-semibold' => $expiringSoon ?? false])>{{ jalali_date($account->expiry_at, 'Y/m/d') }}</span>
    <small @class(['d-block', 'text-muted' => !($expiringSoon ?? false), 'text-danger' => $expiringSoon ?? false])>{{ __('ui.at_time') }} {{ jalali_date($account->expiry_at, 'H:i') }}</small>
@else
    <span class="text-muted">{{ __('accounts.no_expiry') }}</span>
@endif
