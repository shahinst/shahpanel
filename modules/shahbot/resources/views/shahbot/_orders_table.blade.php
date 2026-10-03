<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('shahbot::admin.col_user') }}</th>
                <th>{{ __('shahbot::admin.col_type') }}</th>
                <th>{{ __('shahbot::admin.col_service') }}</th>
                <th>{{ __('shahbot::admin.col_account') }}</th>
                <th>{{ __('shahbot::admin.col_amount') }}</th>
                <th>{{ __('shahbot::admin.col_discount') }}</th>
                <th>{{ __('shahbot::admin.col_date') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td>{{ persian_digits($order->id) }}</td>
                    <td>@if ($order->botUser)<a href="{{ route('admin.shahbot.users.show', $order->botUser) }}">{{ $order->botUser->displayName() }}</a>@else — @endif</td>
                    <td>@include('shahbot::_order_type', ['type' => $order->type])</td>
                    <td>{{ $order->duration?->package?->name ?? '—' }} <span class="sb-muted">{{ $order->duration?->tier->label() }}</span></td>
                    <td>@if ($order->account)<a href="{{ route('admin.accounts.show', $order->account) }}">{{ $order->account->remote_username }}</a>@else — @endif</td>
                    <td>{{ format_money($order->amount) }}</td>
                    <td>@if ((float) $order->discount > 0){{ format_money($order->discount) }} <span class="sb-muted">{{ $order->discount_code }}</span>@else — @endif</td>
                    <td>{{ jalali_date($order->created_at, 'Y/m/d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center sb-muted">{{ __('shahbot::admin.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
