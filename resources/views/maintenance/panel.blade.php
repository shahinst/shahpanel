@extends('layouts.auth')

@section('title', __('maintenance.page_title_mode'))

@section('content')
<div class="text-center py-5">
    <div class="mb-4">
        <i class="bx bx-wrench display-3 text-warning"></i>
    </div>
    <h1 class="h3 mb-3">{{ __('maintenance.mode_title') }}</h1>
    <p class="text-muted lead mx-auto" style="max-width: 520px;">{{ $message }}</p>

    @if ($showAdminLoginLink ?? false)
        <p class="mt-4 mb-0">
            <a href="{{ route('login', ['admin' => 1]) }}" class="btn btn-outline-secondary btn-sm">
                {{ __('maintenance.admin_login_link') }}
            </a>
        </p>
    @endif
</div>
@endsection
