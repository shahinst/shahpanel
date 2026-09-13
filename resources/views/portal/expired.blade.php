@extends('layouts.portal')

@section('title', __('accounts.portal_link_expired'))

@section('portal_content')
<div class="portal-wrap">
    <section class="portal-card portal-expired-card">
        <div class="portal-expired-icon" aria-hidden="true"><i class="bx bx-time-five"></i></div>
        <h1 class="portal-expired-title">{{ __('accounts.portal_link_expired') }}</h1>
        <p class="portal-expired-text">{{ __('accounts.portal_link_expired_hint') }}</p>
    </section>
    <footer class="portal-footer">{{ config('app.name') }}</footer>
</div>
@endsection
