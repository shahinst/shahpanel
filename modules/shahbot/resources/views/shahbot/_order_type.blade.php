<span @class(['sb-pill', 'ok' => $type === 'buy', 'info' => $type === 'renew', 'warn' => $type === 'test', 'info' => $type === 'bulk'])>{{ __('shahbot::admin.order_'.$type) }}</span>
