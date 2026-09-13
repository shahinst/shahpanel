@if (is_impersonating() && impersonator())
    @php
        $impersonatorUser = impersonator();
    @endphp
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 border-0 shadow-sm">
        <div>
            <div class="fw-semibold">
                <i class="bx bx-user-check"></i>
                {{ __('security.impersonation_banner', ['name' => auth()->user()->full_name, 'role' => auth()->user()->role->label()]) }}
            </div>
            @if ($impersonatorUser)
                <div class="small mt-1 opacity-75">
                    {{ __('security.impersonation_real_account', ['name' => $impersonatorUser->full_name, 'role' => $impersonatorUser->role->label()]) }}
                </div>
            @endif
        </div>
        <form method="POST" action="{{ route('impersonate.leave') }}" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-dark">
                <i class="bx bx-arrow-back"></i> {{ __('security.impersonation_leave') }}
            </button>
        </form>
    </div>
@endif
