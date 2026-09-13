@php
    $size = $size ?? 'sm';
    $hero = $hero ?? false;
@endphp

@can('impersonate', $seller)
    @if (Route::has('agent.sellers.impersonate'))
        @php
            $confirm = __('security.impersonation_seller_confirm', [
                'name' => $seller->full_name ?: $seller->username,
            ]);
            $btnClass = $hero ? 'btn btn-light btn-sm' : 'btn btn-'.$size.' btn-info';
        @endphp
        <form method="POST" action="{{ route('agent.sellers.impersonate', $seller) }}" class="d-inline">
            @csrf
            <button type="submit"
                    class="{{ $btnClass }}"
                    title="{{ __('security.impersonation_enter_seller') }}"
                    onclick="return confirm(@json($confirm))">
                <i class="bx bx-log-in-circle"></i> {{ __('security.impersonation_enter_seller') }}
            </button>
        </form>
    @endif
@endcan
